<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DeviceCertificateIssuer;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceCredentials extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DeviceResource::class;

    protected string $view = 'filament.portal.resources.device-management.devices.pages.device-credentials';

    /**
     * @var array{
     *     ca_certificate_pem: string,
     *     device_certificate_pem: string,
     *     device_private_key_pem: string,
     *     has_active_certificate: bool
     * }|null
     */
    public ?array $credentialBundle = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        Gate::authorize('manageCredentials', $this->record);
    }

    public function getTitle(): string
    {
        return 'Device credentials';
    }

    public function hasActiveCertificate(): bool
    {
        return $this->device()->activeCertificate()->exists();
    }

    public function usesMqtt(): bool
    {
        $this->device()->loadMissing('profileVersion');

        return $this->device()->profileVersion?->protocol === Protocol::Mqtt;
    }

    public function issue(): void
    {
        Gate::authorize('manageCredentials', $this->record);
        abort_unless($this->usesMqtt(), 422);
        $device = $this->device();
        $issuer = app(DeviceCertificateIssuer::class);
        $userId = auth()->id();

        if ($device->activeCertificate()->exists()) {
            $issuer->rotateForDevice($device, issuedByUserId: $userId);
        } else {
            $issuer->issueForDevice($device, issuedByUserId: $userId);
        }

        $device->refresh();
        $this->credentialBundle = $issuer->credentialBundleForDevice($device);

        Notification::make()
            ->success()
            ->title('Credentials are ready for one-time download')
            ->send();
    }

    public function revoke(): void
    {
        Gate::authorize('manageCredentials', $this->record);
        app(DeviceCertificateIssuer::class)->revokeActiveForDevice(
            $this->device(),
            'tenant_admin_revocation',
        );
        $this->credentialBundle = null;

        Notification::make()->success()->title('Device credentials revoked')->send();
    }

    public function downloadCredentials(): StreamedResponse
    {
        Gate::authorize('manageCredentials', $this->record);
        abort_unless($this->credentialBundle !== null, 404);
        $payload = json_encode($this->credentialBundle, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $filename = Str::slug($this->device()->name).'-credentials.json';
        $this->credentialBundle = null;

        return response()->streamDownload(
            static function () use ($payload): void {
                echo $payload;
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    private function device(): Device
    {
        abort_unless($this->record instanceof Device, 404);

        return $this->record;
    }
}
