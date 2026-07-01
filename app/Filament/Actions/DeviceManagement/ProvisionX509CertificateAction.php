<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DeviceCertificateIssuer;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

final class ProvisionX509CertificateAction
{
    public static function make(): Action
    {
        $defaultValidityDaysValue = config('iot.pki.default_validity_days', 365);
        $defaultValidityDays = is_numeric($defaultValidityDaysValue)
            ? (int) $defaultValidityDaysValue
            : 365;

        return Action::make('provisionX509')
            ->label('Provision X.509')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->slideOver()
            ->modalHeading('Provision Device Certificate')
            ->modalWidth('4xl')
            ->steps([
                Step::make('Certificate')
                    ->description('Set certificate validity for this device.')
                    ->schema([
                        TextInput::make('validity_days')
                            ->label('Validity Days')
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->default($defaultValidityDays),
                    ]),
                Step::make('Review')
                    ->description('Confirm identity and topic scope before issuing.')
                    ->schema([
                        Placeholder::make('device_identity')
                            ->label('Device Identity')
                            ->content(function (Device $record): string {
                                $deviceIdentifier = is_string($record->external_id) && trim($record->external_id) !== ''
                                    ? $record->external_id
                                    : $record->uuid;

                                return "Name: {$record->name}\nIdentifier: {$deviceIdentifier}";
                            }),
                        Placeholder::make('topic_scope')
                            ->label('Topic Scope')
                            ->content(function (Device $record): string {
                                $record->loadMissing('profileVersion');

                                if (! $record->profileVersion instanceof DeviceProfileVersion) {
                                    return 'No profile version is assigned to this device.';
                                }

                                $contract = app(DeviceProfileContractResolver::class)->resolve($record->profileVersion);
                                $identifier = is_string($record->external_id) && trim($record->external_id) !== ''
                                    ? $record->external_id
                                    : $record->uuid;
                                $topics = $contract->channels()
                                    ->sortBy('sequence')
                                    ->map(fn (ChannelDefinition $channel): string => $channel->resolvedAddress($identifier, $contract->protocolConfig))
                                    ->values()
                                    ->all();

                                if (! is_array($topics) || $topics === []) {
                                    return 'No channels are configured for this device.';
                                }

                                return implode("\n", $topics);
                            }),
                    ]),
            ])
            ->visible(fn (Device $record): bool => $record->profileVersion?->protocol === Protocol::Mqtt)
            ->action(function (array $data, Device $record): void {
                /** @var DeviceCertificateIssuer $issuer */
                $issuer = app(DeviceCertificateIssuer::class);

                $validityDays = is_numeric($data['validity_days'] ?? null)
                    ? (int) $data['validity_days']
                    : null;

                $userId = auth()->id();
                $issuedByUserId = is_int($userId) ? $userId : null;

                $issuedCertificate = $issuer->issueForDevice(
                    device: $record,
                    validityDays: $validityDays,
                    issuedByUserId: $issuedByUserId,
                    revokeExisting: true,
                );

                Notification::make()
                    ->title('X.509 certificate provisioned')
                    ->body("Serial: {$issuedCertificate->serial_number}")
                    ->success()
                    ->send();
            });
    }
}
