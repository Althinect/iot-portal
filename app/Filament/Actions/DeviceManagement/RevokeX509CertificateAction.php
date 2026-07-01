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

final class RevokeX509CertificateAction
{
    public static function make(): Action
    {
        return Action::make('revokeX509')
            ->label('Revoke X.509')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revoke Active Device Certificate')
            ->schema([
                TextInput::make('reason')
                    ->label('Revocation Reason')
                    ->required()
                    ->default('manual_revocation')
                    ->maxLength(255),
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

                $reason = is_string($data['reason'] ?? null) && trim($data['reason']) !== ''
                    ? trim($data['reason'])
                    : 'manual_revocation';

                $revokedCount = $issuer->revokeActiveForDevice($record, $reason);

                Notification::make()
                    ->title('X.509 certificate revoked')
                    ->body($revokedCount > 0 ? 'The active certificate was revoked.' : 'No active certificate was found.')
                    ->success()
                    ->send();
            });
    }
}
