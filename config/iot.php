<?php

declare(strict_types=1);

return [
    'nats' => [
        'host' => (string) env('IOT_NATS_HOST', (string) env('INGESTION_NATS_HOST', '127.0.0.1')),
        'port' => (int) env('IOT_NATS_PORT', (int) env('INGESTION_NATS_PORT', 4223)),
    ],

    'mqtt' => [
        'host' => (string) env('IOT_MQTT_HOST', (string) env('IOT_NATS_HOST', (string) env('INGESTION_NATS_HOST', '127.0.0.1'))),
        'port' => (int) env('IOT_MQTT_PORT', 1883),
        'tls' => [
            'enabled' => (bool) env('IOT_MQTT_TLS_ENABLED', false),
            'ca_path' => (string) env('IOT_MQTT_TLS_CA_PATH', storage_path('app/private/iot-pki/ca.crt')),
            'client_cert_path' => (string) env('IOT_MQTT_TLS_CLIENT_CERT_PATH', storage_path('app/private/iot-pki/platform-client.crt')),
            'client_key_path' => (string) env('IOT_MQTT_TLS_CLIENT_KEY_PATH', storage_path('app/private/iot-pki/platform-client.key')),
            'verify_peer' => (bool) env('IOT_MQTT_TLS_VERIFY_PEER', true),
            'verify_peer_name' => (bool) env('IOT_MQTT_TLS_VERIFY_PEER_NAME', true),
            'allow_self_signed' => (bool) env('IOT_MQTT_TLS_ALLOW_SELF_SIGNED', false),
        ],
    ],

    'pki' => [
        'ca_certificate_path' => (string) env('IOT_PKI_CA_CERT_PATH', storage_path('app/private/iot-pki/ca.crt')),
        'ca_private_key_path' => (string) env('IOT_PKI_CA_KEY_PATH', storage_path('app/private/iot-pki/ca.key')),
        'default_validity_days' => (int) env('IOT_PKI_DEFAULT_VALIDITY_DAYS', 365),
        'subject' => [
            'country' => (string) env('IOT_PKI_SUBJECT_COUNTRY', 'US'),
            'organization' => (string) env('IOT_PKI_SUBJECT_ORGANIZATION', (string) env('APP_NAME', 'LMU IoT Portal')),
        ],
    ],

    'device_control' => [
        'inject_meta_command_id' => (bool) env('IOT_INJECT_META_COMMAND_ID', true),
        'command_timeout_seconds' => (int) env('IOT_COMMAND_TIMEOUT_SECONDS', 120),
    ],

    'presence' => [
        'subject_prefix' => (string) env('IOT_PRESENCE_SUBJECT_PREFIX', 'devices'),
        'subject_suffix' => (string) env('IOT_PRESENCE_SUBJECT_SUFFIX', 'presence'),
        'heartbeat_timeout_seconds' => (int) env('IOT_HEARTBEAT_TIMEOUT_SECONDS', 300),
        'write_throttle_seconds' => (int) env('IOT_PRESENCE_WRITE_THROTTLE_SECONDS', 15),
    ],

    'broadcast' => [
        'raw_telemetry' => (bool) env('IOT_BROADCAST_RAW_TELEMETRY', false),
    ],

    'temporary_devices' => [
        'default_ttl_hours' => 24,
    ],
];
