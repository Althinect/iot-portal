<?php

declare(strict_types=1);

return [
    'contract_ttl_seconds' => (int) env('DEVICE_PROFILE_CONTRACT_TTL_SECONDS', 300),

    'channel_registry_ttl_seconds' => (int) env('DEVICE_PROFILE_CHANNEL_REGISTRY_TTL_SECONDS', 30),
];
