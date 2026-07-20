<?php

declare(strict_types=1);

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\GuidedConditionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\SriLankanDashboardSeeder;
use Database\Seeders\SriLankanMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds sri lankan status and history dashboards for the communicating cold-room devices', function (): void {
    $this->seed(SriLankanMigrationSeeder::class);

    $organization = Organization::query()
        ->where('slug', SriLankanMigrationSeeder::ORGANIZATION_SLUG)
        ->firstOrFail();

    $configuredScopes = resolveSriLankanConfiguredScopes($organization);
    $firstScope = $configuredScopes[0];
    $staleCondition = app(GuidedConditionService::class)->fromLegacyBounds(
        minimumValue: -100.0,
        maximumValue: 100.0,
    );

    AutomationThresholdPolicy::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $firstScope['device']->id,
        'device_channel_id' => $firstScope['topic']->id,
        'parameter_key' => $firstScope['parameter']->key,
        'name' => 'Stale CLD 02 Temperature Threshold',
        'minimum_value' => -100.0,
        'maximum_value' => 100.0,
        'condition_mode' => $staleCondition['condition_mode'],
        'guided_condition' => $staleCondition['guided_condition'],
        'condition_json_logic' => $staleCondition['condition_json_logic'],
        'is_active' => true,
        'cooldown_value' => 1,
        'cooldown_unit' => 'day',
        'sort_order' => 99,
    ]);

    $statusDashboard = IoTDashboard::query()->create([
        'organization_id' => $organization->id,
        'slug' => 'srilankan-cold-room-status',
        'name' => 'Legacy Combined Dashboard',
        'is_active' => true,
        'refresh_interval_seconds' => 60,
    ]);

    $historyDashboard = IoTDashboard::query()->create([
        'organization_id' => $organization->id,
        'slug' => 'srilankan-cold-room-history',
        'name' => 'Legacy History Dashboard',
        'is_active' => true,
        'refresh_interval_seconds' => 60,
    ]);

    IoTDashboardWidget::query()->create([
        'iot_dashboard_id' => $statusDashboard->id,
        'device_id' => $firstScope['device']->id,
        'device_channel_id' => null,
        'device_channel_id' => $firstScope['topic']->id,
        'title' => 'Legacy History Widget',
        'type' => 'line_chart',
        'config' => [
            'series' => [],
        ],
        'layout' => [
            'x' => 0,
            'y' => 0,
            'w' => 12,
            'h' => 4,
            'columns' => 24,
            'card_height_px' => 384,
        ],
        'sequence' => 999,
    ]);

    IoTDashboardWidget::query()->create([
        'iot_dashboard_id' => $historyDashboard->id,
        'device_id' => $firstScope['device']->id,
        'device_channel_id' => null,
        'device_channel_id' => $firstScope['topic']->id,
        'title' => 'Legacy Threshold Widget',
        'type' => 'threshold_status_card',
        'config' => [
            'policy_id' => 999,
        ],
        'layout' => [
            'x' => 0,
            'y' => 0,
            'w' => 12,
            'h' => 3,
            'columns' => 24,
            'card_height_px' => 288,
        ],
        'sequence' => 999,
    ]);

    $this->seed(SriLankanDashboardSeeder::class);
    $this->seed(SriLankanDashboardSeeder::class);

    $dashboards = IoTDashboard::query()
        ->whereIn('slug', [
            'srilankan-cold-room-status',
            'srilankan-cold-room-history',
        ])
        ->get()
        ->keyBy('slug');

    $statusDashboard = $dashboards->get('srilankan-cold-room-status');
    $historyDashboard = $dashboards->get('srilankan-cold-room-history');

    $statusWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $statusDashboard?->id)
        ->orderBy('sequence')
        ->orderBy('id')
        ->get()
        ->keyBy('title');

    $historyWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $historyDashboard?->id)
        ->orderBy('sequence')
        ->orderBy('id')
        ->get()
        ->keyBy('title');

    $thresholdPolicies = AutomationThresholdPolicy::query()
        ->where('organization_id', $organization->id)
        ->orderBy('sort_order')
        ->get()
        ->keyBy('device_id');

    expect($dashboards)->toHaveCount(2)
        ->and($configuredScopes)->toHaveCount(10)
        ->and($statusDashboard?->organization?->slug)->toBe(SriLankanMigrationSeeder::ORGANIZATION_SLUG)
        ->and($statusDashboard?->refresh_interval_seconds)->toBe(15)
        ->and($historyDashboard?->refresh_interval_seconds)->toBe(30)
        ->and($thresholdPolicies)->toHaveCount(count($configuredScopes))
        ->and($statusWidgets)->toHaveCount(count($configuredScopes))
        ->and($historyWidgets)->toHaveCount(count($configuredScopes))
        ->and($statusWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'threshold_status_card'))->toBeTrue()
        ->and($historyWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'line_chart'))->toBeTrue()
        ->and($statusWidgets->has('Legacy History Widget'))->toBeFalse()
        ->and($historyWidgets->has('Legacy Threshold Widget'))->toBeFalse();

    foreach ($configuredScopes as $index => $scope) {
        $policy = $thresholdPolicies->get($scope['device']->id);
        $expectedCondition = app(GuidedConditionService::class)->fromLegacyBounds(
            minimumValue: $scope['card']['minimum_value'],
            maximumValue: $scope['card']['maximum_value'],
        );
        $cardTitle = $scope['card']['room_name'];
        $chartTitle = $scope['card']['room_name'].' Temperature History';
        $cardWidget = $statusWidgets->get($cardTitle);
        $chartWidget = $historyWidgets->get($chartTitle);
        $statusRow = intdiv($index, 5);
        $statusColumn = $index % 5;
        $historyRow = intdiv($index, 2);
        $historyColumn = $index % 2;

        expect($policy)->not->toBeNull()
            ->and($policy?->name)->toBe($scope['card']['room_name'].' Temperature Threshold')
            ->and((float) $policy?->minimum_value)->toBe($scope['card']['minimum_value'])
            ->and((float) $policy?->maximum_value)->toBe($scope['card']['maximum_value'])
            ->and($policy?->condition_mode)->toBe('guided')
            ->and($policy?->guided_condition)->toEqual($expectedCondition['guided_condition'])
            ->and($policy?->condition_json_logic)->toEqual($expectedCondition['condition_json_logic'])
            ->and($cardWidget)->not->toBeNull()
            ->and($cardWidget?->type)->toBe('threshold_status_card')
            ->and($cardWidget?->device_id)->toBe($scope['device']->id)
            ->and($cardWidget?->device_channel_id)->toBe($scope['topic']->id)
            ->and($cardWidget?->sequence)->toBe($index + 1)
            ->and($cardWidget?->configArray())->toMatchArray([
                'policy_id' => $policy?->id,
                'transport' => [
                    'use_websocket' => false,
                    'use_polling' => true,
                    'polling_interval_seconds' => 15,
                ],
                'window' => [
                    'lookback_minutes' => 180,
                    'max_points' => 1,
                ],
            ])
            ->and($cardWidget?->layoutArray())->toMatchArray([
                'x' => $statusColumn * 4,
                'y' => $statusRow * 3,
                'w' => 4,
                'h' => 3,
                'columns' => 24,
                'card_height_px' => 288,
            ])
            ->and($chartWidget)->not->toBeNull()
            ->and($chartWidget?->type)->toBe('line_chart')
            ->and($chartWidget?->device_id)->toBe($scope['device']->id)
            ->and($chartWidget?->device_channel_id)->toBe($scope['topic']->id)
            ->and($chartWidget?->sequence)->toBe($index + 1)
            ->and($chartWidget?->configArray())->toMatchArray([
                'series' => [[
                    'key' => $scope['parameter']->key,
                    'label' => $scope['parameter']->label,
                    'color' => '#0ea5e9',
                    'unit' => null,
                ]],
                'transport' => [
                    'use_websocket' => false,
                    'use_polling' => true,
                    'polling_interval_seconds' => 30,
                ],
                'window' => [
                    'lookback_minutes' => 720,
                    'max_points' => 240,
                ],
            ])
            ->and($chartWidget?->layoutArray())->toMatchArray([
                'x' => $historyColumn * 12,
                'y' => $historyRow * 4,
                'w' => 12,
                'h' => 4,
                'columns' => 24,
                'card_height_px' => 384,
            ]);
    }
});

/**
 * @return list<array{
 *     card: array{
 *         device_name: string,
 *         room_name: string,
 *         parameter_key: string,
 *         minimum_value: float,
 *         maximum_value: float
 *     },
 *     device: Device,
 *     topic: DeviceChannel,
 *     parameter: ProfileParameterDefinition
 * }>
 */
function resolveSriLankanConfiguredScopes(Organization $organization): array
{
    $configuredCards = [
        ['device_name' => 'CLD 02', 'room_name' => 'CLD 02', 'parameter_key' => 'temperature', 'minimum_value' => 2.0, 'maximum_value' => 8.0],
        ['device_name' => 'CLD 03', 'room_name' => 'CLD 03', 'parameter_key' => 'temperature', 'minimum_value' => 2.0, 'maximum_value' => 8.0],
        ['device_name' => 'CLD 04-01', 'room_name' => 'CLD 04-01', 'parameter_key' => 'temperature', 'minimum_value' => 2.0, 'maximum_value' => 8.0],
        ['device_name' => 'CLD 05-01', 'room_name' => 'CLD 05-01', 'parameter_key' => 'temperature', 'minimum_value' => 15.0, 'maximum_value' => 25.0],
        ['device_name' => 'CLD 06', 'room_name' => 'CLD 06', 'parameter_key' => 'temperature', 'minimum_value' => 15.0, 'maximum_value' => 25.0],
        ['device_name' => 'CLD 07-02', 'room_name' => 'CLD 07-02', 'parameter_key' => 'temperature', 'minimum_value' => 2.0, 'maximum_value' => 8.0],
        ['device_name' => 'CLD 08-04', 'room_name' => 'CLD 08-04', 'parameter_key' => 'temperature_2', 'minimum_value' => -20.0, 'maximum_value' => 0.0],
        ['device_name' => 'CLD 09-02', 'room_name' => 'CLD 09-02', 'parameter_key' => 'temperature', 'minimum_value' => 15.0, 'maximum_value' => 25.0],
        ['device_name' => 'CLD 10', 'room_name' => 'CLD 10', 'parameter_key' => 'temperature', 'minimum_value' => 15.0, 'maximum_value' => 25.0],
        ['device_name' => 'CLD 11-01', 'room_name' => 'CLD 11-01', 'parameter_key' => 'temperature', 'minimum_value' => 15.0, 'maximum_value' => 25.0],
    ];

    $devices = Device::query()
        ->with([
            'profileVersion.channels' => fn ($query) => $query
                ->where('key', 'telemetry')
                ->with([
                    'parameters' => fn ($query) => $query
                        ->where('is_active', true)
                        ->orderBy('sequence')
                        ->orderBy('id'),
                ]),
        ])
        ->where('organization_id', $organization->id)
        ->whereIn('name', array_column($configuredCards, 'device_name'))
        ->get();

    $scopes = [];

    foreach ($configuredCards as $card) {
        /** @var Device|null $device */
        $device = $devices->firstWhere('name', $card['device_name']);

        if (! $device instanceof Device) {
            continue;
        }

        $channel = $device->profileVersion?->channels
            ?->first(fn (DeviceChannel $channel): bool => resolveSriLankanParameterByKey($channel, $card['parameter_key']) instanceof ProfileParameterDefinition);

        if (! $channel instanceof DeviceChannel) {
            continue;
        }

        $parameter = resolveSriLankanParameterByKey($channel, $card['parameter_key']);

        if (! $parameter instanceof ProfileParameterDefinition) {
            continue;
        }

        $scopes[] = [
            'card' => $card,
            'device' => $device,
            'topic' => $channel,
            'parameter' => $parameter,
        ];
    }

    return $scopes;
}

function resolveSriLankanParameterByKey(DeviceChannel $channel, string $parameterKey): ?ProfileParameterDefinition
{
    /** @var ProfileParameterDefinition|null $parameter */
    $parameter = $channel->parameters
        ->first(fn (ProfileParameterDefinition $parameter): bool => $parameter->key === $parameterKey);

    return $parameter;
}
