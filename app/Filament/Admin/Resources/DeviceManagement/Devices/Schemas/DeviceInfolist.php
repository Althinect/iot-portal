<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\Schemas;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\VirtualStandards\VirtualStandardProfile;
use App\Domain\DeviceManagement\ValueObjects\VirtualStandards\VirtualStandardShiftSchedule;
use App\Domain\DeviceManagement\ValueObjects\VirtualStandards\VirtualStandardSource;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\DeviceProfileContract;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use App\Filament\Admin\Resources\Shared\Organizations\OrganizationResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DeviceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Device Details')
                    ->schema([
                        TextEntry::make('name')
                            ->weight('bold'),

                        TextEntry::make('uuid')
                            ->label('UUID')
                            ->copyable(),

                        TextEntry::make('external_id')
                            ->label('External ID')
                            ->placeholder('None'),

                        TextEntry::make('is_virtual')
                            ->label('Kind')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Virtual' : 'Physical')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),

                        TextEntry::make('parentDevice.name')
                            ->label('Parent Hub')
                            ->placeholder('None'),

                        TextEntry::make('organization.name')
                            ->label('Organization')
                            ->url(fn (Device $record): ?string => $record->organization_id
                                ? OrganizationResource::getUrl('view', ['record' => $record->organization_id])
                                : null),

                        TextEntry::make('profileVersion.profile.name')
                            ->label('Profile'),

                        TextEntry::make('profileVersion.version')
                            ->label('Profile Version')
                            ->formatStateUsing(fn ($state) => "Version {$state}"),
                    ])
                    ->columns(2),

                Section::make('Status')
                    ->schema([
                        TextEntry::make('is_active')
                            ->label('Active')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                        TextEntry::make('effective_connection_state')
                            ->label('Connection State')
                            ->state(fn (Device $record): string => $record->effectiveConnectionState())
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state))
                            ->color(fn (string $state): string => match ($state) {
                                'online' => 'success',
                                'offline' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('effective_presence_timeout')
                            ->label('Effective Timeout')
                            ->state(fn (Device $record): string => "{$record->presenceTimeoutSeconds()} seconds"),

                        TextEntry::make('last_seen_at')
                            ->label('Last Seen')
                            ->since()
                            ->placeholder('Never'),

                        TextEntry::make('offline_deadline_at')
                            ->label('Offline Deadline')
                            ->state(fn (Device $record) => $record->resolvedOfflineDeadlineAt())
                            ->dateTime()
                            ->placeholder('Pending first signal'),

                        TextEntry::make('child_devices_count')
                            ->label('Child Devices')
                            ->state(fn (Device $record): int => $record->childDevices()->count()),

                        TextEntry::make('virtual_source_count')
                            ->label('Virtual Sources')
                            ->state(fn (Device $record): int => $record->virtualDeviceLinks()->count())
                            ->visible(fn (Device $record): bool => $record->isVirtual()),
                    ])
                    ->columns(2),

                Section::make('Virtual Composition')
                    ->visible(fn (Device $record): bool => $record->isVirtual())
                    ->schema([
                        TextEntry::make('virtual_source_summary')
                            ->label('Source Devices')
                            ->state(function (Device $record): string {
                                $record->loadMissing('virtualDeviceLinks.sourceDevice');

                                return $record->virtualDeviceLinks
                                    ->map(function ($link): string {
                                        $sourceDevice = $link->sourceDevice;
                                        $sourceName = $sourceDevice instanceof Device ? $sourceDevice->name : 'Unknown Device';

                                        return Str::headline((string) $link->purpose).' → '.$sourceName;
                                    })
                                    ->implode(PHP_EOL);
                            })
                            ->placeholder('No virtual source devices attached.')
                            ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ]),

                Section::make('Standard Profile')
                    ->visible(fn (Device $record): bool => $record->isVirtualStandard())
                    ->schema([
                        TextEntry::make('virtual_standard_profile_label')
                            ->label('Profile')
                            ->state(function (Device $record): string {
                                $profile = $record->virtualStandardProfile();

                                return $profile instanceof VirtualStandardProfile ? $profile->label : '—';
                            }),
                        TextEntry::make('virtual_standard_shift_schedule')
                            ->label('Shift Schedule')
                            ->state(function (Device $record): string {
                                $shiftSchedule = $record->virtualStandardProfile()?->shiftSchedule;

                                return $shiftSchedule instanceof VirtualStandardShiftSchedule
                                    ? $shiftSchedule->label
                                    : 'Not configured';
                            }),
                        TextEntry::make('virtual_standard_purpose_summary')
                            ->label('Expected Sources')
                            ->state(fn (Device $record): string => self::virtualStandardPurposeSummary($record))
                            ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('X.509 Security')
                    ->schema([
                        IconEntry::make('has_active_certificate')
                            ->label('Active Certificate')
                            ->boolean()
                            ->state(function (Device $record): bool {
                                $record->loadMissing('activeCertificate');

                                return $record->activeCertificate?->isActive() ?? false;
                            }),

                        TextEntry::make('activeCertificate.serial_number')
                            ->label('Certificate Serial')
                            ->state(function (Device $record): ?string {
                                $record->loadMissing('activeCertificate');

                                return $record->activeCertificate?->serial_number;
                            })
                            ->placeholder('Not provisioned'),

                        TextEntry::make('activeCertificate.fingerprint_sha256')
                            ->label('Fingerprint (SHA-256)')
                            ->state(function (Device $record): ?string {
                                $record->loadMissing('activeCertificate');

                                return $record->activeCertificate?->fingerprint_sha256;
                            })
                            ->copyable()
                            ->placeholder('—'),

                        TextEntry::make('activeCertificate.not_after')
                            ->label('Expires At')
                            ->state(function (Device $record) {
                                $record->loadMissing('activeCertificate');

                                return $record->activeCertificate?->not_after;
                            })
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make('Metadata')
                    ->schema([
                        KeyValueEntry::make('metadata')
                            ->state(fn (Device $record): array => self::normalizeMetadataForDisplay($record->getAttribute('metadata')))
                            ->columnSpanFull(),
                    ]),

                Section::make('Command Payload Samples')
                    ->description('Example JSON payloads the device expects on command channels, using profile defaults.')
                    ->schema([
                        KeyValueEntry::make('command_payload_samples')
                            ->valueLabel('JSON')
                            ->columnSpanFull()
                            ->state(function (Device $record): array {
                                $contract = self::contractFor($record);

                                if ($contract === null) {
                                    return [];
                                }

                                return $contract->commandChannels()
                                    ->sortBy('sequence')
                                    ->mapWithKeys(function (ChannelDefinition $channel): array {
                                        $template = $channel->buildCommandPayloadTemplate();

                                        return [
                                            $channel->key => json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                                        ];
                                    })
                                    ->all();
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_profile_version_id') !== null),
                    ]),

                Section::make('Publish Payload Samples')
                    ->description('Example transport addresses and JSON payload structure the device should publish.')
                    ->schema([
                        TextEntry::make('mqtt_publish_payload_samples')
                            ->label('Publish Samples')
                            ->copyable()
                            ->placeholder('—')
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->state(function (Device $record): string {
                                $contract = self::contractFor($record);

                                if ($contract === null) {
                                    return '';
                                }

                                $identifier = self::deviceIdentifier($record);
                                $samples = $contract->publishChannels()
                                    ->sortBy('sequence')
                                    ->map(function (ChannelDefinition $channel) use ($contract, $identifier): string {
                                        $resolvedAddress = $channel->resolvedAddress($identifier, $contract->protocolConfig);
                                        $template = $channel->buildPublishPayloadTemplate();
                                        $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

                                        $qos = $channel->qos;
                                        $retain = $channel->retain ? 'true' : 'false';

                                        return "Address: {$resolvedAddress}\nQoS: {$qos}\nRetain: {$retain}\nPayload:\n{$json}";
                                    })
                                    ->all();

                                return implode("\n\n", $samples);
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_profile_version_id') !== null),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeMetadataForDisplay(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        return collect($metadata)
            ->map(fn (mixed $value): string => match (true) {
                is_array($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: get_debug_type($value),
            })
            ->all();
    }

    private static function contractFor(Device $record): ?DeviceProfileContract
    {
        $record->loadMissing('profileVersion');

        if (! $record->profileVersion instanceof DeviceProfileVersion) {
            return null;
        }

        return app(DeviceProfileContractResolver::class)->resolve($record->profileVersion);
    }

    private static function deviceIdentifier(Device $record): string
    {
        $externalId = $record->getAttribute('external_id');

        return is_string($externalId) && trim($externalId) !== '' ? $externalId : (string) $record->uuid;
    }

    private static function virtualStandardPurposeSummary(Device $record): string
    {
        $profile = $record->virtualStandardProfile();

        if ($profile === null) {
            return 'No managed standard profile configured.';
        }

        return collect($profile->sources)
            ->map(function (VirtualStandardSource $source): string {
                $allowedTypes = collect($source->allowedDeviceProfileKeys)
                    ->map(fn (string $key): string => Str::headline($key))
                    ->implode(', ');

                $requirement = $source->required ? 'required' : 'optional';

                return sprintf('%s (%s) → %s', $source->label, $requirement, $allowedTypes !== '' ? $allowedTypes : 'Any physical device');
            })
            ->implode(PHP_EOL);
    }
}
