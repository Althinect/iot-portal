<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DeviceCertificateIssuer;
use App\Domain\DeviceProfile\Enums\Protocol;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RotateX509CertificateAction
{
    public static function make(): Action
    {
        $defaultValidityDaysValue = config('iot.pki.default_validity_days', 365);
        $defaultValidityDays = is_numeric($defaultValidityDaysValue)
            ? (int) $defaultValidityDaysValue
            : 365;

        return Action::make('rotateX509')
            ->label('Rotate X.509')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Rotate Device Certificate')
            ->schema([
                TextInput::make('validity_days')
                    ->label('Validity Days')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->maxValue(3650)
                    ->default($defaultValidityDays),
            ])
            ->visible(function (Device $record): bool {
                if ($record->profileVersion?->protocol !== Protocol::Mqtt) {
                    return false;
                }

                return $record->activeCertificate()->exists();
            })
            ->action(function (array $data, Device $record): void {
                /** @var DeviceCertificateIssuer $issuer */
                $issuer = app(DeviceCertificateIssuer::class);

                $validityDays = is_numeric($data['validity_days'] ?? null)
                    ? (int) $data['validity_days']
                    : null;
                $userId = auth()->id();
                $issuedByUserId = is_int($userId) ? $userId : null;

                $rotatedCertificate = $issuer->rotateForDevice(
                    device: $record,
                    validityDays: $validityDays,
                    issuedByUserId: $issuedByUserId,
                );

                Notification::make()
                    ->title('X.509 certificate rotated')
                    ->body("New serial: {$rotatedCertificate->serial_number}")
                    ->success()
                    ->send();
            });
    }
}
