<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StenterUtilization\StenterUtilizationConfig;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class TeejayDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', TeejayMigrationSeeder::ORGANIZATION_SLUG)
            ->first();

        if (! $organization instanceof Organization) {
            $this->command?->warn('Teejay organization not found. Skipping Teejay dashboard seed.');

            return;
        }

        $dashboard = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'teejay-stenter-standards',
            ],
            [
                'name' => 'Stenter Standards',
                'description' => 'Utilization, runtime, efficiency, and production length for all virtual Stenter standard devices.',
                'is_active' => true,
                'refresh_interval_seconds' => 30,
            ],
        );

        $stenterDevices = Device::query()
            ->with(['profileVersion.channels', 'virtualDeviceLinks.sourceDevice.profileVersion.channels.parameters'])
            ->where('organization_id', $organization->id)
            ->where('is_virtual', true)
            ->whereHas('profileVersion.profile', fn ($query) => $query->where('key', 'stenter_line'))
            ->orderBy('name')
            ->get();

        $this->syncStenterUtilizationWidgets($dashboard, $stenterDevices);
    }

    /**
     * @param  Collection<int, Device>  $stenterDevices
     */
    private function syncStenterUtilizationWidgets(IoTDashboard $dashboard, Collection $stenterDevices): void
    {
        $expectedTitles = [];
        $sequence = 1;

        foreach ($stenterDevices->values() as $index => $device) {
            if (! $this->hasVirtualSources($device, ['status', 'length'])) {
                continue;
            }

            $topic = $this->resolveTelemetryTopic($device);

            if (! $topic instanceof DeviceChannel) {
                continue;
            }

            $sources = [
                'status' => $this->virtualLinkSource($device, 'status', 'status'),
                'length' => $this->virtualLinkSource($device, 'length', 'length'),
            ];

            if ($sources['status'] === null || $sources['length'] === null) {
                continue;
            }

            $title = $device->name.' · Utilization';
            $expectedTitles[] = $title;

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $title,
                ],
                [
                    'device_id' => $device->id,
                    'device_channel_id' => $topic->id,
                    'device_channel_id' => $topic->id,
                    'type' => WidgetType::StenterUtilization->value,
                    'config' => [
                        'sources' => $sources,
                        'shifts' => StenterUtilizationConfig::defaultShifts(),
                        'percentage_thresholds' => StenterUtilizationConfig::defaultPercentageThresholds(),
                        'transport' => [
                            'use_websocket' => false,
                            'use_polling' => true,
                            'polling_interval_seconds' => 30,
                        ],
                        'window' => [
                            'lookback_minutes' => 1440,
                            'max_points' => 60,
                        ],
                    ],
                    'layout' => $this->layoutFor($sequence - 1, 4, 8, 768),
                    'sequence' => $sequence,
                ],
            );

            $sequence++;
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->when(
                $expectedTitles !== [],
                fn ($query) => $query->whereNotIn('title', $expectedTitles),
            )
            ->delete();
    }

    /**
     * @param  array<int, string>  $purposes
     */
    private function hasVirtualSources(Device $device, array $purposes): bool
    {
        return collect($purposes)
            ->every(fn (string $purpose): bool => $device->virtualDeviceLinks
                ->contains(fn (VirtualDeviceLink $link): bool => $link->purpose === $purpose && $link->sourceDevice instanceof Device));
    }

    /**
     * @return array{device_id: int, device_channel_id: int, parameter_key: string}|null
     */
    private function virtualLinkSource(Device $device, string $purpose, string $parameterKey): ?array
    {
        $link = $device->virtualDeviceLinks
            ->first(fn (VirtualDeviceLink $candidate): bool => $candidate->purpose === $purpose && $candidate->sourceDevice instanceof Device);
        $sourceDevice = $link?->sourceDevice;

        if (! $sourceDevice instanceof Device) {
            return null;
        }

        $sourceDevice->loadMissing('profileVersion.channels.parameters');

        $topic = $sourceDevice->profileVersion?->channels
            ?->first(function (DeviceChannel $candidate) use ($parameterKey): bool {
                if (! $candidate->isPublish()) {
                    return false;
                }

                return $candidate->parameters->contains(fn (ProfileParameterDefinition $parameter): bool => $parameter->key === $parameterKey && (bool) $parameter->is_active);
            });

        if (! $topic instanceof DeviceChannel) {
            return null;
        }

        return [
            'device_id' => (int) $sourceDevice->id,
            'device_channel_id' => $topic->id,
            'parameter_key' => $parameterKey,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function layoutFor(int $index, int $width, int $height, int $cardHeightPixels): array
    {
        $columnCount = max(1, intdiv(24, $width));

        return [
            'x' => ($index % $columnCount) * $width,
            'y' => intdiv($index, $columnCount) * $height,
            'w' => $width,
            'h' => $height,
            'columns' => 24,
            'card_height_px' => $cardHeightPixels,
        ];
    }

    private function resolveTelemetryTopic(Device $device): ?DeviceChannel
    {
        $device->loadMissing('profileVersion.channels');

        return $device->profileVersion?->channels
            ?->first(fn (DeviceChannel $topic): bool => $topic->key === 'telemetry')
            ?? $device->profileVersion?->channels?->first(fn (DeviceChannel $topic): bool => $topic->isPublish());
    }
}
