<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Application\WidgetRegistry;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetFormOptionsService;
use Database\Seeders\SriLankanDashboardSeeder;
use Database\Seeders\SriLankanMigrationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders sri lankan dashboard runtime paths after profile migration', function (): void {
    $this->seed(UserSeeder::class);
    $this->seed(SriLankanMigrationSeeder::class);
    $this->seed(SriLankanDashboardSeeder::class);

    $admin = User::query()
        ->where('email', UserSeeder::DEFAULT_SUPER_ADMIN_EMAIL)
        ->firstOrFail();

    $this->actingAs($admin);

    $dashboard = IoTDashboard::query()
        ->where('slug', 'srilankan-cold-room-status')
        ->firstOrFail();

    $this->get('/admin/io-t-dashboard?dashboard='.$dashboard->id)
        ->assertSuccessful()
        ->assertSee('Cold Room Status');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('type', WidgetType::ThresholdStatusCard->value)
        ->orderBy('sequence')
        ->firstOrFail();

    $snapshot = app(WidgetRegistry::class)
        ->forWidget($widget)
        ->resolveSnapshot($widget);

    expect(data_get($snapshot, 'card.policy_id'))->toBe($widget->configObject()->policyId())
        ->and(data_get($snapshot, 'card.rule_label'))->toBeString();

    $formOptions = app(WidgetFormOptionsService::class);
    $policyId = $widget->configObject()->policyId();

    $cardInput = $formOptions->resolveInput($dashboard, [
        'widget_type' => WidgetType::ThresholdStatusCard->value,
        'policy_id' => $policyId,
    ]);

    $gridInput = $formOptions->resolveInput($dashboard, [
        'widget_type' => WidgetType::ThresholdStatusGrid->value,
        'scope' => 'selected',
        'policy_ids' => [$policyId],
    ]);

    expect($cardInput)
        ->not->toBeNull()
        ->and($gridInput)
        ->not->toBeNull();

    $options = $formOptions->thresholdPolicyOptions($dashboard);

    expect($options)
        ->toHaveCount(10)
        ->each->toBeString();
});
