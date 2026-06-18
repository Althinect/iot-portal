<?php

declare(strict_types=1);

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Database\Seeders\SriLankanMigrationSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'queue.default' => 'sync',
    ]);

    $this->seed(SriLankanMigrationSeeder::class);
    attachSriLankanNotificationRecipientsToOrganization();

    configureLegacyIotConnectionForSriLankanImportTests();
    recreateLegacySriLankanAlertTables();
    seedLegacySriLankanAlertRows();
});

it('imports sri lankan legacy alert rules into threshold policies, notification profiles, and managed workflows', function (): void {
    $this->artisan('automation:import-sri-lankan-threshold-policies')
        ->expectsOutputToContain('SriLankan threshold policy import completed.')
        ->assertSuccessful();

    $organization = Organization::query()
        ->where('slug', SriLankanMigrationSeeder::ORGANIZATION_SLUG)
        ->firstOrFail();

    $profiles = AutomationNotificationProfile::query()
        ->where('organization_id', $organization->id)
        ->orderBy('name')
        ->get()
        ->keyBy('name');

    $policies = AutomationThresholdPolicy::query()
        ->where('organization_id', $organization->id)
        ->orderBy('legacy_alert_rule_id')
        ->get()
        ->keyBy('legacy_alert_rule_id');

    $primaryProfile = $profiles->get('Legacy sms · Sri Lankan SMS Alerts');
    $testProfile = $profiles->get('Legacy sms · Test SMS Alert');

    expect($profiles)->toHaveCount(2)
        ->and($policies)->toHaveCount(8)
        ->and($primaryProfile)->not->toBeNull()
        ->and($primaryProfile?->channel)->toBe('sms')
        ->and($primaryProfile?->normalizedRecipients())->toBe(['+94771234567', '+94770000000'])
        ->and($primaryProfile?->mask)->toBe('D IoT Alert')
        ->and($primaryProfile?->campaign_name)->toBe('alerts')
        ->and($primaryProfile?->body)->toContain('{{ trigger.device_name }}')
        ->and($primaryProfile?->body)->toContain('{{ alert.metadata.condition_label }}')
        ->and($testProfile)->not->toBeNull()
        ->and($testProfile?->normalizedRecipients())->toBe(['+94771112233']);

    $cld03 = $policies->get('65e7e863a1855a81740279e3');
    $cld10 = $policies->get('65e7eedce09437f4ad048a94');
    $cld10Disabled = $policies->get('6693449225c916423c0336d2');
    $cld11Disabled = $policies->get('65e7f02d3bb512a0e40d8324');

    expect($cld03)->not->toBeNull()
        ->and($cld03?->device?->name)->toBe('CLD 03')
        ->and((float) $cld03?->minimum_value)->toBe(2.0)
        ->and((float) $cld03?->maximum_value)->toBe(8.0)
        ->and($cld03?->is_active)->toBeTrue()
        ->and($cld03?->notificationProfile?->is($primaryProfile))->toBeTrue()
        ->and($cld03?->managed_workflow_id)->not->toBeNull()
        ->and($cld03?->cooldown())->toBe([
            'value' => 1,
            'unit' => 'day',
        ])
        ->and($cld10)->not->toBeNull()
        ->and($cld10?->device?->name)->toBe('CLD 10')
        ->and((float) $cld10?->minimum_value)->toBe(15.0)
        ->and((float) $cld10?->maximum_value)->toBe(25.0)
        ->and($cld10?->is_active)->toBeTrue()
        ->and($cld10Disabled)->not->toBeNull()
        ->and($cld10Disabled?->device_id)->toBe($cld10?->device_id)
        ->and($cld10Disabled?->parameter_definition_id)->toBe($cld10?->parameter_definition_id)
        ->and((float) $cld10Disabled?->minimum_value)->toBe(15.0)
        ->and((float) $cld10Disabled?->maximum_value)->toBe(18.0)
        ->and($cld10Disabled?->is_active)->toBeFalse()
        ->and($cld10Disabled?->managed_workflow_id)->toBeNull()
        ->and($cld11Disabled)->not->toBeNull()
        ->and($cld11Disabled?->device?->name)->toBe('CLD 11 - 02')
        ->and((float) $cld11Disabled?->minimum_value)->toBe(-20.0)
        ->and((float) $cld11Disabled?->maximum_value)->toBe(-18.0)
        ->and($cld11Disabled?->is_active)->toBeFalse();

    expect(AutomationWorkflow::query()
        ->where('organization_id', $organization->id)
        ->where('is_managed', true)
        ->where('managed_type', 'threshold_policy')
        ->count())->toBe(6);
});

function configureLegacyIotConnectionForSriLankanImportTests(): void
{
    $defaultConnectionName = Config::get('database.default');
    $defaultConnection = Config::get("database.connections.{$defaultConnectionName}");

    if (! is_array($defaultConnection)) {
        throw new RuntimeException('Default database connection is not configured.');
    }

    if (($defaultConnection['driver'] ?? null) === 'sqlite') {
        $legacyDatabasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.sprintf(
            'legacy_iot_import_%s.sqlite',
            env('TEST_TOKEN', 'default')
        );

        if (! file_exists($legacyDatabasePath)) {
            touch($legacyDatabasePath);
        }

        $defaultConnection['database'] = $legacyDatabasePath;
    }

    if (($defaultConnection['driver'] ?? null) === 'pgsql') {
        $defaultConnection['search_path'] = 'legacy_iot';
    }

    Config::set('database.connections.legacy_iot', $defaultConnection);
    DB::purge('legacy_iot');

    if (($defaultConnection['driver'] ?? null) === 'pgsql') {
        DB::connection('legacy_iot')->statement('create schema if not exists legacy_iot');
    }
}

function attachSriLankanNotificationRecipientsToOrganization(): void
{
    $organization = Organization::query()
        ->where('slug', SriLankanMigrationSeeder::ORGANIZATION_SLUG)
        ->firstOrFail();

    $userIds = collect([
        ['name' => 'SriLankan Alert Primary', 'email' => 'srilankan-alert-primary@example.com', 'phone_number' => '94771234567'],
        ['name' => 'SriLankan Alert Secondary', 'email' => 'srilankan-alert-secondary@example.com', 'phone_number' => '94770000000'],
        ['name' => 'SriLankan Alert Test', 'email' => 'srilankan-alert-test@example.com', 'phone_number' => '94771112233'],
    ])->map(function (array $attributes): int {
        return (int) User::factory()->create($attributes)->getKey();
    });

    $organization->users()->syncWithoutDetaching($userIds->all());
}

function recreateLegacySriLankanAlertTables(): void
{
    $schema = Schema::connection('legacy_iot');

    $schema->dropIfExists('alerts');
    $schema->dropIfExists('alert_rules');
    $schema->dropIfExists('alert_templates');
    $schema->dropIfExists('alert_providers');

    $schema->create('alert_providers', function (Blueprint $table): void {
        $table->id();
        $table->string('mongodb_id')->nullable();
        $table->string('name');
        $table->string('type')->nullable();
        $table->string('driver')->nullable();
        $table->text('configuration')->nullable();
        $table->timestamps();
    });

    $schema->create('alert_templates', function (Blueprint $table): void {
        $table->id();
        $table->string('mongodb_id')->nullable();
        $table->string('name');
        $table->unsignedBigInteger('alert_provider_id')->nullable();
        $table->text('attributes')->nullable();
        $table->timestamps();
    });

    $schema->create('alert_rules', function (Blueprint $table): void {
        $table->id();
        $table->string('mongodb_id');
        $table->string('name');
        $table->boolean('enabled')->default(true);
        $table->unsignedInteger('alert_interval')->default(0);
        $table->text('logic');
        $table->unsignedBigInteger('alert_template_id');
        $table->unsignedBigInteger('organization_id')->nullable();
        $table->text('attributes')->nullable();
        $table->timestamps();
    });

    $schema->create('alerts', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
}

function seedLegacySriLankanAlertRows(): void
{
    DB::connection('legacy_iot')->table('alert_providers')->insert([
        'id' => 1,
        'mongodb_id' => '65d000000000000000000001',
        'name' => 'althinect-iot-dialog-sms',
        'type' => 'sms',
        'driver' => 'dialog',
        'configuration' => json_encode([
            'url' => 'https://dialog.example.test/sms',
            'user' => 'Althinect_iot',
            'digest' => '7e96c536f4c3ceda35d39077fcedbf3387b7',
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('legacy_iot')->table('alert_templates')->insert([
        [
            'id' => 39,
            'mongodb_id' => '65d000000000000000000039',
            'name' => 'Sri Lankan SMS Alerts',
            'alert_provider_id' => 1,
            'attributes' => json_encode([
                'configuration' => [
                    'recipients' => ['94771234567', '94770000000', 'invalid-recipient'],
                    'body' => '{{.deviceName}}: current temperature = {{.temperature}}C condition {{.condition}}',
                    'mask' => 'D IoT Alert',
                    'campaignName' => 'alerts',
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 25,
            'mongodb_id' => '65d000000000000000000025',
            'name' => 'Test SMS Alert',
            'alert_provider_id' => 1,
            'attributes' => json_encode([
                'configuration' => [
                    'recipients' => ['94771112233'],
                    'body' => '{{.deviceName}} - testing temperature {{.temperature}}C condition {{.condition}}',
                    'mask' => 'D IoT Alert',
                    'campaignName' => 'alerts',
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rules = [
        ['mongodb_id' => '65e7e863a1855a81740279e3', 'name' => 'ACLD 03 - 02 Temperature', 'enabled' => true, 'template_id' => 39, 'minimum' => 2, 'maximum' => 8],
        ['mongodb_id' => '65e7ea30c26dbbfc74049a32', 'name' => 'CLD 04 - 02 Temperature', 'enabled' => true, 'template_id' => 39, 'minimum' => 2, 'maximum' => 8],
        ['mongodb_id' => '65e7ea7c7b5e2b1947017993', 'name' => 'CLD 05 - 02 Temperature', 'enabled' => true, 'template_id' => 39, 'minimum' => 2, 'maximum' => 8],
        ['mongodb_id' => '65e7ed8c3bb512a0e40d8323', 'name' => 'CLD 08 - 02 Temperature', 'enabled' => true, 'template_id' => 39, 'minimum' => 2, 'maximum' => 8],
        ['mongodb_id' => '65e7eedce09437f4ad048a94', 'name' => 'ACLD 10 Temperature', 'enabled' => true, 'template_id' => 39, 'minimum' => 15, 'maximum' => 25],
        ['mongodb_id' => '6693449225c916423c0336d2', 'name' => 'ACLD 10 Secondary Temperature', 'enabled' => false, 'template_id' => 39, 'minimum' => 15, 'maximum' => 18],
        ['mongodb_id' => '65e7f02d3bb512a0e40d8324', 'name' => 'CLD 11 - 02 Testing', 'enabled' => false, 'template_id' => 25, 'minimum' => -20, 'maximum' => -18],
        ['mongodb_id' => '65e7f03234a0a449610037a5', 'name' => 'CLD 09 - 02 Temperature', 'enabled' => true, 'template_id' => 39, 'minimum' => 2, 'maximum' => 8],
    ];

    foreach (array_values($rules) as $index => $rule) {
        DB::connection('legacy_iot')->table('alert_rules')->insert([
            'id' => $index + 1,
            'mongodb_id' => $rule['mongodb_id'],
            'name' => $rule['name'],
            'enabled' => $rule['enabled'],
            'alert_interval' => 86400,
            'logic' => json_encode([
                'or' => [
                    ['<' => [['var' => 'temperature'], $rule['minimum']]],
                    ['>' => [['var' => 'temperature'], $rule['maximum']]],
                ],
            ], JSON_THROW_ON_ERROR),
            'alert_template_id' => $rule['template_id'],
            'organization_id' => 1,
            'attributes' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
