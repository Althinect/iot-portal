<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threshold_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_channel_id')->constrained('device_channels')->cascadeOnDelete();
            $table->string('parameter_key');
            $table->string('name');
            $table->decimal('minimum_value', 10, 3)->nullable();
            $table->decimal('maximum_value', 10, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('cooldown_value')->default(1);
            $table->string('cooldown_unit', 16)->default('day');
            $table->foreignId('notification_profile_id')
                ->nullable()
                ->constrained('notification_profiles')
                ->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('managed_workflow_id')
                ->nullable()
                ->constrained('automation_workflows')
                ->nullOnDelete();
            $table->string('legacy_alert_rule_id', 64)->nullable();
            $table->jsonb('legacy_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'device_id']);
            $table->index(['device_id', 'device_channel_id', 'parameter_key', 'is_active'], 'threshold_policies_device_channel_parameter_active_idx');
            $table->unique(['organization_id', 'legacy_alert_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threshold_policies');
    }
};
