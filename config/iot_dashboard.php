<?php

declare(strict_types=1);

return [
    'snapshot_cache_seconds' => (int) env('IOT_DASHBOARD_SNAPSHOT_CACHE_SECONDS', 2),

    'hot_state_reads' => [
        'enabled' => (bool) env('IOT_DASHBOARD_HOT_STATE_READS_ENABLED', false),
    ],
];
