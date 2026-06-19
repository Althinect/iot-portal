<?php

declare(strict_types=1);

use App\Domain\Shared\Models\User;
use App\Notifications\Automation\AutomationWorkflowAlertNotification;
use App\Notifications\Channels\DialogSmsChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.sms.url' => 'https://dialog.example.test/sms',
        'services.sms.user' => 'Althinect_iot',
        'services.sms.digest' => 'digest-token',
        'services.sms.mask' => 'ALTHINECT',
        'services.sms.campaign_name' => 'alerts',
        'services.sms.timeout_seconds' => 10,
    ]);
});

it('formats e164 user phone numbers for the dialog sms gateway', function (): void {
    Http::fake([
        'https://dialog.example.test/sms' => Http::response([
            'status' => 'accepted',
        ], 200),
    ]);

    $user = User::factory()->create([
        'phone_number' => '+94771234567',
    ]);

    $notification = new AutomationWorkflowAlertNotification(
        channel: 'sms',
        subject: 'Threshold breach',
        body: 'CLD 03 - 02 temperature is 9.5°C',
        context: [],
        mask: 'D IoT Alert',
        campaignName: 'cold-rooms',
    );

    $result = app(DialogSmsChannel::class)->send($user, $notification);

    expect($result)->toMatchArray([
        'channel' => 'sms',
        'recipient' => '94771234567',
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
