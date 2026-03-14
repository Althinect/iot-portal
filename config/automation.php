<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AUTOMATION_PIPELINE_ENABLED', true),

    'telemetry_fanout_enabled' => (bool) env('AUTOMATION_PIPELINE_TELEMETRY_FANOUT_ENABLED', true),

    'queue_connection' => (string) env('AUTOMATION_PIPELINE_QUEUE_CONNECTION', (string) env('QUEUE_CONNECTION', 'database')),

    'queue' => (string) env('AUTOMATION_PIPELINE_QUEUE', 'automation'),

    'telemetry_dispatch_cooldown_seconds' => (int) env('AUTOMATION_PIPELINE_TELEMETRY_DISPATCH_COOLDOWN_SECONDS', 0),

    'log_channel' => (string) env('AUTOMATION_PIPELINE_LOG_CHANNEL', 'automation_pipeline'),

    'step_log_mode' => (string) env('AUTOMATION_STEP_LOG_MODE', 'failures'),

    'step_log_sample_rate' => (float) env('AUTOMATION_STEP_LOG_SAMPLE_RATE', 0.0),

    'capture_step_snapshots' => (bool) env('AUTOMATION_CAPTURE_STEP_SNAPSHOTS', false),
];
