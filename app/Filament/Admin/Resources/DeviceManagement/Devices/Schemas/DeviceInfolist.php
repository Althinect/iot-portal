<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\Schemas;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Filament\Admin\Resources\DeviceManagement\DeviceTypes\DeviceTypeResource;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\DeviceSchemaVersionResource;
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

                        TextEntry::make('organization.name')
                            ->label('Organization')
                            ->url(fn (Device $record): ?string => $record->organization_id
                                ? OrganizationResource::getUrl('view', ['record' => $record->organization_id])
                                : null),

                        TextEntry::make('deviceType.name')
                            ->label('Device Type')
                            ->url(fn (Device $record): ?string => $record->device_type_id
                                ? DeviceTypeResource::getUrl('view', ['record' => $record->device_type_id])
                                : null),

                        TextEntry::make('schemaVersion.version')
                            ->label('Schema Version')
                            ->formatStateUsing(fn ($state) => "Version {$state}")
                            ->url(fn (Device $record): ?string => $record->device_schema_version_id
                                ? DeviceSchemaVersionResource::getUrl('view', ['record' => $record->device_schema_version_id])
                                : null),
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
                            ->columnSpanFull(),
                    ]),

                Section::make('Command Payload Samples')
                    ->description('These are example JSON payloads the device expects on subscribe topics, using default values from the schema.')
                    ->schema([
                        KeyValueEntry::make('command_payload_samples')
                            ->valueLabel('JSON')
                            ->columnSpanFull()
                            ->state(function (Device $record): array {
                                $record->loadMissing('schemaVersion.topics.parameters');

                                $topics = $record->schemaVersion?->topics
                                    ?->filter(fn (SchemaVersionTopic $topic): bool => $topic->isSubscribe())
                                    ->sortBy('sequence');

                                if (! $topics || $topics->isEmpty()) {
                                    return [];
                                }

                                return $topics->mapWithKeys(function (SchemaVersionTopic $topic): array {
                                    $template = $topic->buildCommandPayloadTemplate();

                                    return [
                                        $topic->key => json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                                    ];
                                })->all();
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_schema_version_id') !== null),
                    ]),

                Section::make('MQTT Publish Payload Samples')
                    ->description('Example topics + JSON payload structure the device should publish (Device → Platform). Copy and paste into your MQTT client.')
                    ->schema([
                        TextEntry::make('mqtt_publish_payload_samples')
                            ->label('Publish Samples')
                            ->copyable()
                            ->placeholder('—')
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->state(function (Device $record): string {
                                $record->loadMissing('schemaVersion.topics.parameters', 'deviceType');

                                $topics = $record->schemaVersion?->topics
                                    ?->filter(fn (SchemaVersionTopic $topic): bool => $topic->isPublish())
                                    ->sortBy('sequence');

                                if (! $topics || $topics->isEmpty()) {
                                    return '';
                                }

                                $samples = $topics->map(function (SchemaVersionTopic $topic) use ($record): string {
                                    $resolvedTopic = $topic->resolvedTopic($record);
                                    $template = $topic->buildPublishPayloadTemplate();
                                    $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

                                    $qos = $topic->qos ?? 0;
                                    $retain = $topic->retain ? 'true' : 'false';

                                    return "Topic: {$resolvedTopic}\nQoS: {$qos}\nRetain: {$retain}\nPayload:\n{$json}";
                                })->all();

                                return implode("\n\n", $samples);
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_schema_version_id') !== null),
                    ]),
            ]);
    }
}
