<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\Messages\DialogSmsMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DialogSmsChannel
{
    /**
     * @return array<string, mixed>|null
     */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! method_exists($notification, 'toDialogSms')) {
            return null;
        }

        $message = $notification->toDialogSms($notifiable);

        if (! $message instanceof DialogSmsMessage) {
            return null;
        }

        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('dialog_sms', $notification);

        if (! is_string($route) || trim($route) === '') {
            return null;
        }

        $phoneNumber = $this->formatForGateway($route);
        $url = $this->stringConfig('services.sms.url');
        $user = $this->stringConfig('services.sms.user');
        $digest = $this->stringConfig('services.sms.digest');

        if ($url === '' || $user === '' || $digest === '') {
            throw new RuntimeException('SMS gateway configuration is incomplete.');
        }

        $payload = [
            'messages' => [[
                'number' => $phoneNumber,
                'mask' => $message->mask ?: $this->stringConfig('services.sms.mask', 'ELWIDS'),
                'text' => $message->body,
                'campaignName' => $message->campaignName ?: $this->stringConfig('services.sms.campaign_name', 'alerts'),
            ]],
        ];

        $response = Http::timeout($this->intConfig('services.sms.timeout_seconds', 15))
            ->withHeaders([
                'USER' => $user,
                'DIGEST' => $digest,
                'CREATED' => now()->setTimezone('Asia/Colombo')->format('Y-m-d\TH:i:00'),
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to send SMS. Status: '.$response->status());
        }

        return [
            'channel' => 'sms',
            'recipient' => $phoneNumber,
            'status' => $response->status(),
            'response' => $response->json(),
        ];
    }

    private function formatForGateway(string $route): string
    {
        $normalized = trim($route);

        if (preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized) !== 1) {
            throw new RuntimeException("Invalid E.164 phone number [{$route}].");
        }

        return ltrim($normalized, '+');
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
