<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

final class DevicePresenceMessageHandler
{
    public function __construct(
        private readonly DevicePresenceService $presenceService,
    ) {}

    public function subscriptionSubject(string $prefix, string $suffix): string
    {
        return $this->normalizeSubjectFragment($prefix).'.*.'.$this->normalizeSubjectFragment($suffix);
    }

    public function handle(string $subject, string $body, string $prefix, string $suffix): bool
    {
        $deviceIdentifier = $this->extractDeviceIdentifier($subject, $prefix, $suffix);

        if ($deviceIdentifier === null) {
            Log::channel('device_control')->debug('Could not extract device identifier from presence subject', [
                'subject' => $subject,
            ]);

            return false;
        }

        $normalizedBody = $this->normalizePayload($body);

        Log::channel('device_control')->debug('Presence message received', [
            'subject' => $subject,
            'device_identifier' => $deviceIdentifier,
            'state' => $normalizedBody,
        ]);

        return match ($normalizedBody) {
            'offline' => $this->markOffline($deviceIdentifier),
            'online' => $this->markOnline($deviceIdentifier),
            default => $this->reportUnknownPayload($deviceIdentifier, $normalizedBody),
        };
    }

    public function extractDeviceIdentifier(string $subject, string $prefix, string $suffix): ?string
    {
        $pattern = '/^'
            .preg_quote($this->normalizeSubjectFragment($prefix), '/')
            .'\.([^.]+)\.'
            .preg_quote($this->normalizeSubjectFragment($suffix), '/')
            .'$/';

        if (preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function markOffline(string $deviceIdentifier): bool
    {
        $this->presenceService->markOfflineByUuid($deviceIdentifier);

        return true;
    }

    private function markOnline(string $deviceIdentifier): bool
    {
        $this->presenceService->markOnlineByUuid($deviceIdentifier);

        return true;
    }

    private function reportUnknownPayload(string $deviceIdentifier, string $body): bool
    {
        Log::channel('device_control')->warning('Unknown presence payload', [
            'device_identifier' => $deviceIdentifier,
            'body' => $body,
        ]);

        return false;
    }

    private function normalizeSubjectFragment(string $fragment): string
    {
        return str_replace('/', '.', trim($fragment));
    }

    private function normalizePayload(string $body): string
    {
        $normalizedBody = trim($body);

        if ($normalizedBody === '') {
            return $normalizedBody;
        }

        $decoded = json_decode($normalizedBody, true);

        if (! is_array($decoded)) {
            return $normalizedBody;
        }

        $status = Arr::get($decoded, 'status');

        return is_string($status) ? trim($status) : $normalizedBody;
    }
}
