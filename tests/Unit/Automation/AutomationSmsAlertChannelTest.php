<?php

declare(strict_types=1);

use App\Domain\Automation\Services\AutomationSmsAlertChannel;
use App\Notifications\Channels\DialogSmsChannel;
use App\Notifications\Messages\DialogSmsMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'services.sms.url' => 'https://dialog.example.test/sms',
        'services.sms.user' => 'Althinect_iot',
        'services.sms.digest' => 'digest-token',
        'services.sms.mask' => 'ELWIDS',
        'services.sms.campaign_name' => 'alerts',
        'services.sms.timeout_seconds' => 10,
    ]);
});

it('dispatches sms alerts with normalized recipients and provider metadata', function (): void {
    Http::fake([
        'https://dialog.example.test/sms' => Http::response([
            'status' => 'accepted',
        ], 200),
    ]);

    $result = app(AutomationSmsAlertChannel::class)->dispatch(
        recipients: [' 94771234567 ', '94771234567', 'invalid-number'],
        subject: 'Threshold breach',
        body: 'CLD 03 - 02 temperature is 9.5°C',
        context: [
            'alert' => [
                'metadata' => [
                    'mask' => 'D IoT Alert',
                    'campaign_name' => 'cold-rooms',
                ],
            ],
        ],
    );

    expect($result)->toMatchArray([
        'channel' => 'sms',
        'recipient_count' => 1,
        'recipients' => ['94771234567'],
        'subject' => 'Threshold breach',
        'status' => 200,
    ]);

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('https://dialog.example.test/sms')
            ->and($request->hasHeader('USER', 'Althinect_iot'))->toBeTrue()
            ->and($request->hasHeader('DIGEST', 'digest-token'))->toBeTrue()
            ->and($request->hasHeader('CREATED'))->toBeTrue();

        return $request->data() === [
            'messages' => [[
                'number' => '94771234567',
                'mask' => 'D IoT Alert',
                'text' => 'CLD 03 - 02 temperature is 9.5°C',
                'campaignName' => 'cold-rooms',
            ]],
        ];
    });
});

it('throws when the sms gateway rejects the request', function (): void {
    Http::fake([
        '*' => Http::response(['message' => 'gateway unavailable'], 500),
    ]);

    expect(fn () => app(AutomationSmsAlertChannel::class)->dispatch(
        recipients: ['94771234567'],
        subject: 'Threshold breach',
        body: 'Alert body',
        context: [],
    ))->toThrow(RuntimeException::class, 'Failed to send SMS. Status: 500');
});

it('uses ELWIDS as the default sender label for automation and notification SMS messages', function (): void {
    Http::fake([
        'https://dialog.example.test/sms' => Http::response(['status' => 'accepted'], 200),
    ]);

    app(AutomationSmsAlertChannel::class)->dispatch(
        recipients: ['94771234567'],
        subject: 'Threshold breach',
        body: 'Automation alert',
        context: [],
    );

    $notifiable = new class
    {
        public function routeNotificationFor(string $driver, Notification $notification): string
        {
            return '+94771234567';
        }
    };

    $notification = new class extends Notification
    {
        public function toDialogSms(object $notifiable): DialogSmsMessage
        {
            return new DialogSmsMessage(body: 'Notification alert');
        }
    };

    app(DialogSmsChannel::class)->send($notifiable, $notification);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'messages.0.mask') === 'ELWIDS');
});
