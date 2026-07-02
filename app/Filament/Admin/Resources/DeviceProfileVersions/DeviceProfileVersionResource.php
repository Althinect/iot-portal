<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions;

use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeviceProfileVersionResource extends Resource
{
    protected static ?string $model = DeviceProfileVersion::class;

    protected static ?string $slug = 'device-profile-versions';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return Schemas\DeviceProfileVersionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\DeviceProfileVersionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\DeviceProfileVersionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChannelsRelationManager::class,
            RelationManagers\DerivedParametersRelationManager::class,
            RelationManagers\ChannelLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeviceProfileVersions::route('/'),
            'create' => Pages\CreateDeviceProfileVersion::route('/create'),
            'view' => Pages\ViewDeviceProfileVersion::route('/{record}'),
            'edit' => Pages\EditDeviceProfileVersion::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormDataForFill(DeviceProfileVersion $record, array $data): array
    {
        $protocolConfig = $record->protocol_config;

        if ($protocolConfig instanceof MqttProtocolConfig) {
            $data['mqtt_broker_host'] = $protocolConfig->brokerHost;
            $data['mqtt_broker_port'] = $protocolConfig->brokerPort;
            $data['mqtt_base_topic'] = $protocolConfig->baseTopic;
            $data['mqtt_use_tls'] = $protocolConfig->useTls;
        }

        if ($protocolConfig instanceof HttpProtocolConfig) {
            $data['http_base_url'] = $protocolConfig->baseUrl;
            $data['http_telemetry_endpoint'] = $protocolConfig->telemetryEndpoint;
            $data['http_method'] = $protocolConfig->method;
        }

        $data['ingestion_config_json'] = self::encodeJson($record->ingestion_config);
        $data['virtual_standard_profile_json'] = self::encodeJson($record->virtual_standard_profile);
        unset($data['protocol_config']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormDataForSave(array $data, ?DeviceProfileVersion $record = null): array
    {
        if ($record instanceof DeviceProfileVersion && ! $record->canEditContract()) {
            return [
                'notes' => $data['notes'] ?? $record->notes,
            ];
        }

        $data['protocol_config'] = self::protocolConfigFromData($data);
        $data['ingestion_config'] = self::decodeJson($data['ingestion_config_json'] ?? null);
        $data['virtual_standard_profile'] = self::decodeJson($data['virtual_standard_profile_json'] ?? null);

        unset(
            $data['mqtt_broker_host'],
            $data['mqtt_broker_port'],
            $data['mqtt_base_topic'],
            $data['mqtt_use_tls'],
            $data['http_base_url'],
            $data['http_telemetry_endpoint'],
            $data['http_method'],
            $data['ingestion_config_json'],
            $data['virtual_standard_profile_json'],
        );

        return $data;
    }

    public static function nextVersionNumber(int $profileId): int
    {
        $latestVersion = DeviceProfile::query()->findOrFail($profileId)->versions()->max('version');

        return is_numeric($latestVersion) ? ((int) $latestVersion) + 1 : 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function protocolConfigFromData(array $data): array
    {
        if (($data['protocol'] ?? Protocol::Mqtt->value) === Protocol::Http->value) {
            return [
                'base_url' => $data['http_base_url'] ?? 'https://example.test',
                'telemetry_endpoint' => $data['http_telemetry_endpoint'] ?? '/telemetry',
                'method' => $data['http_method'] ?? 'POST',
                'headers' => [],
                'auth_type' => 'none',
                'timeout' => 30,
            ];
        }

        return [
            'broker_host' => $data['mqtt_broker_host'] ?? config('iot.mqtt.host', '127.0.0.1'),
            'broker_port' => (int) ($data['mqtt_broker_port'] ?? config('iot.mqtt.port', 1883)),
            'username' => null,
            'password' => null,
            'use_tls' => (bool) ($data['mqtt_use_tls'] ?? false),
            'base_topic' => $data['mqtt_base_topic'] ?? 'device',
            'security_mode' => 'username_password',
        ];
    }

    private static function encodeJson(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value === [] ? null : $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
