<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 32);
            $table->foreignId('active_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('automation_workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('graph_json');
            $table->string('graph_checksum', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_workflow_id', 'version']);
            $table->index('published_at');
        });

        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table
                ->foreign('active_version_id')
                ->references('id')
                ->on('automation_workflow_versions')
                ->nullOnDelete();
        });

        Schema::create('automation_telemetry_triggers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained('automation_workflow_versions')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_channel_id')->nullable()->constrained('device_channels')->nullOnDelete();
            $table->string('channel_key')->nullable();
            $table->string('parameter_key')->nullable();
            $table->jsonb('filter_expression')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'device_id', 'device_channel_id'], 'automation_trigger_device_channel_idx');
            $table->index(['organization_id', 'channel_key', 'parameter_key'], 'automation_trigger_channel_parameter_idx');
        });

        Schema::create('automation_schedule_triggers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained('automation_workflow_versions')->cascadeOnDelete();
            $table->string('cron_expression');
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'next_run_at']);
            $table->index(['organization_id', 'active']);
        });

        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained('automation_workflow_versions')->cascadeOnDelete();
            $table->string('trigger_type', 32);
            $table->jsonb('trigger_payload')->nullable();
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('error_summary')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['workflow_id', 'created_at']);
        });

        Schema::create('automation_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->string('node_id');
            $table->string('node_type', 64);
            $table->string('status', 32);
            $table->jsonb('input_snapshot')->nullable();
            $table->jsonb('output_snapshot')->nullable();
            $table->jsonb('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'status']);
            $table->index(['automation_run_id', 'node_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_schedule_triggers');
        Schema::dropIfExists('automation_telemetry_triggers');

        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table->dropForeign(['active_version_id']);
        });

        Schema::dropIfExists('automation_workflow_versions');
        Schema::dropIfExists('automation_workflows');
    }
};
