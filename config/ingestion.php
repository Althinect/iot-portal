<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('INGESTION_PIPELINE_ENABLED', true),

    'driver' => (string) env('INGESTION_PIPELINE_DRIVER', 'laravel'),

    'broadcast_realtime' => (bool) env('INGESTION_PIPELINE_BROADCAST_REALTIME', true),

    'publish_analytics' => (bool) env('INGESTION_PIPELINE_PUBLISH_ANALYTICS', true),

    'publish_invalid_events' => (bool) env('INGESTION_PIPELINE_PUBLISH_INVALID', true),

    'queue_connection' => (string) env('INGESTION_PIPELINE_QUEUE_CONNECTION', 'redis'),

    'queue' => (string) env('INGESTION_PIPELINE_QUEUE', 'ingestion'),

    'side_effects_queue_connection' => (string) env(
        'INGESTION_PIPELINE_SIDE_EFFECTS_QUEUE_CONNECTION',
        (string) env('INGESTION_PIPELINE_QUEUE_CONNECTION', 'redis'),
    ),

    'side_effects_queue' => (string) env('INGESTION_PIPELINE_SIDE_EFFECTS_QUEUE', 'telemetry-side-effects'),

    'metadata_ttl_seconds' => (int) env('INGESTION_METADATA_TTL_SECONDS', 300),

    'registry_ttl_seconds' => (int) env('INGESTION_REGISTRY_TTL_SECONDS', 30),

    'stage_log_mode' => (string) env('INGESTION_STAGE_LOG_MODE', 'failures'),

    'stage_log_sample_rate' => (float) env('INGESTION_STAGE_LOG_SAMPLE_RATE', 0.0),

    'capture_stage_snapshots' => (bool) env('INGESTION_CAPTURE_STAGE_SNAPSHOTS', true),

    'capture_success_stage_snapshots' => (bool) env('INGESTION_CAPTURE_SUCCESS_STAGE_SNAPSHOTS', false),

    'default_raw_retention_days' => (int) env('INGESTION_DEFAULT_RAW_RETENTION_DAYS', 90),

    'telemetry_chunk_interval' => (string) env('INGESTION_TELEMETRY_CHUNK_INTERVAL', '1 day'),

    'telemetry_compress_after' => (string) env('INGESTION_TELEMETRY_COMPRESS_AFTER', '7 days'),

    'telemetry_retention' => (string) env('INGESTION_TELEMETRY_RETENTION', '90 days'),

    'ingestion_message_retention_days' => (int) env('INGESTION_MESSAGES_RETENTION_DAYS', 14),

    'ingestion_stage_log_retention_days' => (int) env('INGESTION_STAGE_LOG_RETENTION_DAYS', 7),

    'storage_prune_batch_size' => (int) env('INGESTION_STORAGE_PRUNE_BATCH_SIZE', 1000),

    'nats' => [
        'host' => (string) env('INGESTION_NATS_HOST', (string) env('IOT_NATS_HOST', '127.0.0.1')),
        'port' => (int) env('INGESTION_NATS_PORT', (int) env('IOT_NATS_PORT', 4223)),
        'subject' => (string) env('INGESTION_NATS_SUBJECT', '>'),
        'analytics_subject_prefix' => (string) env('INGESTION_NATS_ANALYTICS_PREFIX', 'iot.v1.analytics'),
        'invalid_subject_prefix' => (string) env('INGESTION_NATS_INVALID_PREFIX', 'iot.v1.invalid'),
    ],

    'subject' => [
        'environment' => (string) env('INGESTION_SUBJECT_ENV', env('APP_ENV', 'local')),
    ],
];
