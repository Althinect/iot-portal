<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Contracts\AutomationAlertChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AutomationSmsAlertChannel implements AutomationAlertChannel
{
    public function channel(): string
    {
        return 'sms';
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function dispatch(array $recipients, string $subject, string $body, array $context): array
    {
        $resolvedRecipients = $this->resolveRecipients($recipients);

        if ($resolvedRecipients === []) {
            throw new RuntimeException('Alert SMS channel requires at least one valid recipient.');
        }

        $url = $this->stringConfig('services.sms.url');
        $user = $this->stringConfig('services.sms.user');
        $digest = $this->stringConfig('services.sms.digest');

        if ($url === '' || $user === '' || $digest === '') {
            throw new RuntimeException('SMS gateway configuration is incomplete.');
        }

        $mask = $this->stringFromContext($context, 'alert.metadata.mask')
            ?: $this->stringFromContext($context, 'node.config.metadata.mask')
            ?: $this->stringConfig('services.sms.mask', 'ELWIDS');
        $campaignName = $this->stringFromContext($context, 'alert.metadata.campaign_name')
            ?: $this->stringFromContext($context, 'node.config.metadata.campaign_name')
            ?: $this->stringConfig('services.sms.campaign_name', 'alerts');
        $timeoutSeconds = $this->intConfig('services.sms.timeout_seconds', 15);
        $createdAt = now()->setTimezone('Asia/Colombo')->format('Y-m-d\TH:i:00');

        $payload = [
            'messages' => array_map(
                static fn (string $recipient): array => [
                    'number' => $recipient,
                    'mask' => $mask,
                    'text' => $body,
                    'campaignName' => $campaignName,
                ],
                $resolvedRecipients,
            ),
        ];

        $response = Http::timeout($timeoutSeconds)
            ->withHeaders([
                'USER' => $user,
                'DIGEST' => $digest,
                'CREATED' => $createdAt,
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to send SMS. Status: '.$response->status());
        }

        $responseBody = $response->json();

        return [
            'channel' => $this->channel(),
            'recipient_count' => count($resolvedRecipients),
            'recipients' => $resolvedRecipients,
            'subject' => $subject,
            'status' => $response->status(),
            'response' => is_array($responseBody) ? $responseBody : [],
        ];
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array<int, string>
     */
    private function resolveRecipients(array $recipients): array
    {
        $resolved = [];

        foreach ($recipients as $recipient) {
            $phoneNumber = trim($recipient);

            if ($phoneNumber === '') {
                continue;
            }

            if (! preg_match('/^94[0-9]{9}$/', $phoneNumber)) {
                continue;
            }

            $resolved[$phoneNumber] = $phoneNumber;
        }

        return array_values($resolved);
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

    /**
     * @param  array<string, mixed>  $context
     */
    private function stringFromContext(array $context, string $path): string
    {
        $value = data_get($context, $path);

        return is_string($value) ? trim($value) : '';
    }
}
