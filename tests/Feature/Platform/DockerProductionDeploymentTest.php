<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('defines a production docker compose stack beside the local sail stack', function (): void {
    $compose = Yaml::parseFile(base_path('compose.production.yaml'));
    $services = $compose['services'];

    expect($services)->toHaveKeys([
        'proxy',
        'web',
        'reverb',
        'iot-listen-states',
        'iot-listen-presence',
        'iot-ingest-telemetry',
        'ingestion-go-events',
        'horizon',
        'scheduler',
        'nightwatch',
        'pulse-check',
        'pulse-worker',
        'pgsql',
        'redis',
        'nats',
        'emqx',
        'node-red',
        'grafana',
        'loki',
        'prometheus',
        'alloy',
        'node-exporter',
        'cadvisor',
    ]);

    expect(array_key_exists('mailpit', $services))->toBeFalse()
        ->and($services['web']['image'])->toBe('${APP_IMAGE:?Set APP_IMAGE in .env.production}')
        ->and($services['web']['command'])->toBe('./scripts/start-production-octane.sh')
        ->and($services['web']['healthcheck']['test'])->toContain('php artisan about --only=environment >/dev/null 2>&1')
        ->and($services['reverb']['command'])->toBe('php artisan reverb:start --host=0.0.0.0 --port=8090')
        ->and($services['reverb']['healthcheck']['disable'])->toBeTrue()
        ->and($services['iot-listen-states']['command'])->toContain('--host=emqx')
        ->and($services['iot-listen-states']['command'])->toContain('--subject=${IOT_DEVICE_STATE_NATS_SUBJECT:-devices.*.state,devices.*.*.state,devices.*.*.*.state}')
        ->and($services['iot-listen-states']['healthcheck']['disable'])->toBeTrue()
        ->and($services['iot-listen-presence']['command'])->toContain('--host=emqx')
        ->and($services['iot-listen-presence']['healthcheck']['disable'])->toBeTrue()
        ->and($services['iot-ingest-telemetry']['image'])->toBe('${TELEMETRY_INGESTER_IMAGE:?Set TELEMETRY_INGESTER_IMAGE in .env.production}')
        ->and($services['iot-ingest-telemetry']['environment']['INGESTION_PIPELINE_DRIVER'])->toBe('go')
        ->and($services['iot-ingest-telemetry']['environment']['INGESTION_NATS_SUBJECT'])->toBe('${INGESTION_NATS_SUBJECT:-devices.*.telemetry,devices.*.*.telemetry,devices.*.*.*.telemetry,migration.source.imoni.*.*.telemetry,migration.source.egravity.*.telemetry}')
        ->and($services['ingestion-go-events']['command'])->toBe('php artisan ingestion:consume-go-events --host=nats --port=4222 --only=persisted')
        ->and($services['ingestion-go-events']['healthcheck']['disable'])->toBeTrue()
        ->and($services['horizon']['command'])->toBe('php artisan horizon --quiet')
        ->and($services['horizon']['healthcheck']['disable'])->toBeTrue()
        ->and($services['horizon']['stop_grace_period'])->toBe('1h')
        ->and($services['scheduler']['command'])->toBe('php artisan schedule:work --quiet')
        ->and($services['scheduler']['healthcheck']['disable'])->toBeTrue()
        ->and($services['nightwatch']['healthcheck']['disable'])->toBeTrue()
        ->and($services['pulse-check']['healthcheck']['disable'])->toBeTrue()
        ->and($services['pulse-worker']['healthcheck']['disable'])->toBeTrue()
        ->and($services['web']['environment']['REVERB_BROADCAST_HOST'])->toBe('reverb')
        ->and($services['web']['environment']['REVERB_BROADCAST_SCHEME'])->toBe('http')
        ->and($services['web']['environment']['IOT_NATS_PORT'])->toBe(4222)
        ->and($services['web']['environment']['IOT_MQTT_HOST'])->toBe('emqx')
        ->and($services['emqx']['image'])->toBe('${EMQX_IMAGE:-emqx/emqx-enterprise:6.2.1}')
        ->and($services['emqx']['hostname'])->toBe('emqx.local')
        ->and($services['emqx']['ports'])->toContain('${EMQX_MQTT_BIND:-127.0.0.1}:${FORWARD_EMQX_MQTT_PORT:-1883}:1883')
        ->and($services['emqx']['ports'])->toContain('${EMQX_DASHBOARD_BIND:-127.0.0.1}:${FORWARD_EMQX_DASHBOARD_PORT:-18083}:18083')
        ->and($services['emqx']['volumes'])->toContain('./docker/emqx/base.hocon:/opt/emqx/etc/base.hocon:ro')
        ->and($services['emqx']['healthcheck']['test'])->toBe(['CMD', '/opt/emqx/bin/emqx', 'ctl', 'status'])
        ->and($services['nats']['ports'])->not->toContain('${NATS_MQTT_BIND:-127.0.0.1}:${FORWARD_NATS_MQTT_PORT:-1883}:1883')
        ->and($services['redis']['command'])->toContain('--loglevel')
        ->and($services['redis']['command'])->toContain('warning')
        ->and($services['node-red']['environment']['MQTT_BROKER_HOST'])->toBe('${NODE_RED_MQTT_BROKER_HOST:-emqx}')
        ->and($services['node-red']['environment']['MQTT_BROKER_PASSWORD'])->toBe('${NODE_RED_MQTT_PASSWORD:?Set NODE_RED_MQTT_PASSWORD in .env.production}')
        ->and($services['node-red']['depends_on']['emqx']['condition'])->toBe('service_healthy')
        ->and($services['grafana']['environment']['GF_LOG_LEVEL'])->toBe('${GRAFANA_LOG_LEVEL:-warn}')
        ->and($services['loki']['command'])->toContain('-config.expand-env=true')
        ->and($services['loki']['command'])->toContain('-log.level=${LOKI_LOG_LEVEL:-warn}')
        ->and($services['loki']['environment']['LOKI_RETENTION_PERIOD'])->toBe('${LOKI_RETENTION_PERIOD:-48h}')
        ->and($services['prometheus']['command'])->toContain('--storage.tsdb.retention.time=${PROMETHEUS_RETENTION:-3d}')
        ->and($services['prometheus']['command'])->toContain('--storage.tsdb.retention.size=${PROMETHEUS_RETENTION_SIZE:-1GB}')
        ->and($services['web']['volumes'])->toContain('app-storage:/app/storage')
        ->and($services['web']['volumes'])->toContain('app-bootstrap-cache:/app/bootstrap/cache');

    $servicesMissingLogRotation = array_keys(array_filter($services, fn (array $service): bool => ! isset($service['logging'])));

    expect($servicesMissingLogRotation)->toBe([]);

    foreach ($services as $service) {
        expect($service['logging']['driver'])->toBe('json-file')
            ->and($service['logging']['options']['max-size'])->toBe('${DOCKER_LOG_MAX_SIZE:-10m}')
            ->and($service['logging']['options']['max-file'])->toBe('${DOCKER_LOG_MAX_FILE:-3}');
    }
});

it('defines local EMQX MQTT services for sail development', function (): void {
    $compose = Yaml::parseFile(base_path('compose.yaml'));
    $services = $compose['services'];

    expect($services)->toHaveKeys(['nats', 'emqx', 'node-red'])
        ->and($services['laravel.test']['environment']['IOT_MQTT_HOST'])->toBe('emqx')
        ->and($services['iot-listen-states']['command'])->toContain('--host=emqx')
        ->and($services['iot-listen-presence']['command'])->toContain('--host=emqx')
        ->and($services['iot-ingest-telemetry']['build']['context'])->toBe('./services/telemetry-ingester')
        ->and($services['iot-ingest-telemetry']['environment']['INGESTION_PIPELINE_DRIVER'])->toBe('go')
        ->and($services['ingestion-go-events']['command'])->toBe('php artisan ingestion:consume-go-events --host=emqx --port=4222')
        ->and($services['nats']['ports'])->not->toContain('${FORWARD_NATS_MQTT_PORT:-1883}:1883')
        ->and($services['emqx']['ports'])->toContain('${EMQX_MQTT_BIND:-127.0.0.1}:${FORWARD_EMQX_MQTT_PORT:-1883}:1883')
        ->and($services['emqx']['ports'])->toContain('${EMQX_DASHBOARD_BIND:-127.0.0.1}:${FORWARD_EMQX_DASHBOARD_PORT:-18083}:18083')
        ->and($services['emqx']['volumes'])->toContain('./docker/emqx/local.hocon:/opt/emqx/etc/base.hocon:ro')
        ->and($services['emqx']['volumes'])->toContain('./docker/emqx/local-auth-built-in-db-bootstrap.csv:/opt/emqx/etc/local-auth-built-in-db-bootstrap.csv:ro')
        ->and($services['node-red']['environment']['MQTT_BROKER_HOST'])->toBe('${NODE_RED_MQTT_BROKER_HOST:-emqx}')
        ->and($services['node-red']['depends_on']['emqx']['condition'])->toBe('service_healthy');
});

it('configures EMQX as the MQTT ingress broker and NATS gateway', function (): void {
    $emqxConfig = file_get_contents(base_path('docker/emqx/base.hocon'));
    $localEmqxConfig = file_get_contents(base_path('docker/emqx/local.hocon'));
    $localEmqxBootstrap = file_get_contents(base_path('docker/emqx/local-auth-built-in-db-bootstrap.csv'));
    $natsConfig = file_get_contents(base_path('docker/nats/nats.conf'));
    $nodeRedSettings = file_get_contents(base_path('docker/node-red/data/settings.js'));
    $nodeRedFlowsJson = file_get_contents(base_path('docker/node-red/data/flows.json'));
    $nodeRedFlows = json_decode($nodeRedFlowsJson ?: '[]', true, flags: JSON_THROW_ON_ERROR);

    $localBroker = collect($nodeRedFlows)->firstWhere('id', 'c5812f56b80b9a3e');
    $normalizeNode = collect($nodeRedFlows)->firstWhere('id', 'nr_normalize_01');

    expect($emqxConfig)
        ->not->toBeFalse()
        ->toContain('authentication = [')
        ->toContain('backend = built_in_database')
        ->toContain('gateway.nats')
        ->toContain('mountpoint = ""')
        ->toContain('bind = 4222')
        ->toContain('prometheus');

    expect($localEmqxConfig)
        ->not->toBeFalse()
        ->toContain('bootstrap_file = "/opt/emqx/etc/local-auth-built-in-db-bootstrap.csv"')
        ->toContain('bootstrap_type = plain');

    expect($localEmqxBootstrap)
        ->not->toBeFalse()
        ->toContain('node-red-migration,node-red-migration-local,false')
        ->toContain('device-client,device-client-local,false');

    expect($natsConfig)
        ->not->toBeFalse()
        ->toContain('server_name: "iot_portal_nats"')
        ->not->toContain('mqtt {');

    expect($localBroker)
        ->toHaveKey('name', 'Local EMQX MQTT')
        ->toHaveKey('broker', '${MQTT_BROKER_HOST}')
        ->toHaveKey('port', '${MQTT_BROKER_PORT}')
        ->toHaveKey('credentials', [
            'user' => '${MQTT_BROKER_USERNAME}',
            'password' => '${MQTT_BROKER_PASSWORD}',
        ]);

    expect($nodeRedSettings)
        ->not->toBeFalse()
        ->toContain('credentialSecret: false');

    expect(data_get($normalizeNode, 'wires.0'))->toBe(['nr_mqtt_out_01']);
});

it('builds an immutable frankenphp image with composer and vite assets', function (): void {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $dockerignore = file_get_contents(base_path('.dockerignore'));

    expect($dockerfile)
        ->not->toBeFalse()
        ->toContain('FROM ${PHP_IMAGE} AS php-base')
        ->toContain('FROM node:22-alpine AS frontend')
        ->toContain('composer install')
        ->toContain('--no-dev')
        ->toContain('npm run build')
        ->toContain('install-php-extensions')
        ->toContain('procps')
        ->toContain('pdo_pgsql')
        ->toContain('redis')
        ->toContain('public/frankenphp-worker.php')
        ->toContain('USER www-data')
        ->not->toContain('vendor/laravel/sail');

    expect($dockerignore)
        ->not->toBeFalse()
        ->toContain('.env.*')
        ->toContain('!.env.production.example')
        ->toContain('node_modules')
        ->toContain('vendor')
        ->toContain('public/build');
});

it('ships deployment automation for release commands and horizon reloads', function (): void {
    $deployScript = file_get_contents(base_path('scripts/deploy-production.sh'));
    $workflow = file_get_contents(base_path('.github/workflows/docker-production.yml'));

    expect($deployScript)
        ->not->toBeFalse()
        ->toContain('PRODUCTION_COMPOSE_FILES=compose.production.yaml:compose.forge.yaml')
        ->toContain('PRODUCTION_SKIP_PULL=false')
        ->toContain('PRODUCTION_PRUNE_DANGLING_IMAGES=true')
        ->toContain('compose=(docker compose --env-file "$env_file")')
        ->toContain('compose+=(-f "$compose_file")')
        ->toContain('Skipping production image pull because PRODUCTION_SKIP_PULL is enabled')
        ->toContain('pull')
        ->toContain('php artisan migrate --force --no-interaction')
        ->toContain('php artisan optimize')
        ->toContain('php artisan horizon:terminate')
        ->toContain('php artisan pulse:restart')
        ->toContain('up -d --wait --wait-timeout 300 pgsql redis nats emqx')
        ->toContain('up -d --remove-orphans')
        ->toContain('docker image prune --force --filter "dangling=true"');

    expect($workflow)
        ->not->toBeFalse()
        ->toContain('docker/build-push-action@v6')
        ->toContain('type=raw,value=${{ github.sha }}')
        ->toContain('PRODUCTION_SSH_HOST')
        ->toContain('PRODUCTION_SSH_KEY')
        ->toContain('PRODUCTION_ENV_FILE')
        ->toContain('APP_IMAGE=\'$IMAGE_REF\' ./scripts/deploy-production.sh');
});

it('ships s3-compatible production database backup automation', function (): void {
    $backupScript = file_get_contents(base_path('scripts/backup-production-db.sh'));
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $dockerignore = file_get_contents(base_path('.dockerignore'));

    expect($backupScript)
        ->not->toBeFalse()
        ->toContain('PRODUCTION_COMPOSE_FILES=compose.production.yaml:compose.forge.yaml')
        ->toContain('compose=(docker compose --env-file "$env_file")')
        ->toContain('compose+=(-f "$compose_file")')
        ->toContain('exec -T pgsql')
        ->toContain('pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"')
        ->toContain('--format=custom')
        ->toContain('--compress=9')
        ->toContain('amazon/aws-cli:2')
        ->toContain('BACKUP_S3_BUCKET')
        ->toContain('BACKUP_S3_ENDPOINT_URL')
        ->toContain('aws "$@"');

    expect($productionEnvironment)
        ->not->toBeFalse()
        ->toContain('BACKUP_S3_BUCKET=')
        ->toContain('BACKUP_S3_PREFIX=postgres')
        ->toContain('BACKUP_S3_ENDPOINT_URL=')
        ->toContain('BACKUP_S3_ACCESS_KEY_ID=')
        ->toContain('BACKUP_S3_SECRET_ACCESS_KEY=');

    expect($dockerignore)
        ->not->toBeFalse()
        ->toContain('backups');
});

it('ships production monitoring services for laravel and container telemetry', function (): void {
    $compose = Yaml::parseFile(base_path('compose.production.yaml'));
    $services = $compose['services'];
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $alloyConfig = file_get_contents(base_path('docker/monitoring/alloy/config.alloy'));
    $prometheusConfig = file_get_contents(base_path('docker/monitoring/prometheus/prometheus.yml'));
    $grafanaDatasources = file_get_contents(base_path('docker/monitoring/grafana/provisioning/datasources/datasources.yaml'));
    $grafanaDashboardProvider = file_get_contents(base_path('docker/monitoring/grafana/provisioning/dashboards/dashboards.yaml'));
    $grafanaOverviewDashboardJson = file_get_contents(base_path('docker/monitoring/grafana/provisioning/dashboards/iot-portal-overview.json'));
    $grafanaContainerMetricsDashboardJson = file_get_contents(base_path('docker/monitoring/grafana/provisioning/dashboards/iot-portal-container-metrics.json'));
    $grafanaLogsRuntimeDashboardJson = file_get_contents(base_path('docker/monitoring/grafana/provisioning/dashboards/iot-portal-logs-runtime.json'));
    $lokiConfig = file_get_contents(base_path('docker/monitoring/loki/config.yaml'));
    $grafanaOverviewDashboard = json_decode($grafanaOverviewDashboardJson ?: '[]', true, flags: JSON_THROW_ON_ERROR);
    $grafanaContainerMetricsDashboard = json_decode($grafanaContainerMetricsDashboardJson ?: '[]', true, flags: JSON_THROW_ON_ERROR);
    $grafanaLogsRuntimeDashboard = json_decode($grafanaLogsRuntimeDashboardJson ?: '[]', true, flags: JSON_THROW_ON_ERROR);

    expect($services['nightwatch']['command'])->toContain('php artisan nightwatch:agent --listen-on=0.0.0.0:2407')
        ->toContain('NIGHTWATCH_ENABLED')
        ->toContain('NIGHTWATCH_TOKEN')
        ->and($services['pulse-check']['command'])->toBe('php artisan pulse:check')
        ->and($services['pulse-worker']['command'])->toBe('php artisan pulse:work')
        ->and($services['web']['environment']['NIGHTWATCH_INGEST_URI'])->toBe('${NIGHTWATCH_INGEST_URI:-nightwatch:2407}')
        ->and($services['web']['environment']['PULSE_INGEST_DRIVER'])->toBe('${PULSE_INGEST_DRIVER:-redis}')
        ->and($services['grafana']['ports'])->toContain('${GRAFANA_BIND:-127.0.0.1}:${GRAFANA_PORT:-3000}:3000')
        ->and($services['grafana']['volumes'])->toContain('./docker/monitoring/grafana/provisioning:/etc/grafana/provisioning:ro')
        ->and($services['alloy']['volumes'])->toContain('/var/run/docker.sock:/var/run/docker.sock:ro')
        ->and($services['prometheus']['volumes'])->toContain('./docker/monitoring/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro')
        ->and($services['loki']['volumes'])->toContain('./docker/monitoring/loki/config.yaml:/etc/loki/config.yaml:ro');

    expect($productionEnvironment)
        ->not->toBeFalse()
        ->toContain('LOG_STACK=stderr')
        ->toContain('PRODUCTION_PRUNE_DANGLING_IMAGES=true')
        ->toContain('NIGHTWATCH_ENABLED=false')
        ->toContain('NIGHTWATCH_INGEST_URI=nightwatch:2407')
        ->toContain('PULSE_INGEST_DRIVER=redis')
        ->toContain('PULSE_REDIS_CONNECTION=default')
        ->toContain('DOCKER_LOG_MAX_SIZE=10m')
        ->toContain('DOCKER_LOG_MAX_FILE=3')
        ->toContain('GRAFANA_BIND=127.0.0.1')
        ->toContain('GRAFANA_ADMIN_PASSWORD=')
        ->toContain('GRAFANA_LOG_LEVEL=warn')
        ->toContain('LOKI_LOG_LEVEL=warn')
        ->toContain('LOKI_RETENTION_PERIOD=48h')
        ->toContain('PROMETHEUS_RETENTION=3d')
        ->toContain('PROMETHEUS_RETENTION_SIZE=1GB')
        ->toContain('EMQX_DASHBOARD_BIND=127.0.0.1')
        ->toContain('EMQX_DASHBOARD_PASSWORD=replace-with-strong-emqx-dashboard-password');

    expect($alloyConfig)
        ->not->toBeFalse()
        ->toContain('discovery.docker "containers"')
        ->toContain('loki.source.docker "containers"')
        ->toContain('http://loki:3100/loki/api/v1/push');

    expect($prometheusConfig)
        ->not->toBeFalse()
        ->toContain('node-exporter:9100')
        ->toContain('cadvisor:8080')
        ->toContain('alloy:12345')
        ->toContain('job_name: emqx_stats')
        ->toContain('/api/v5/prometheus/stats')
        ->toContain('job_name: emqx_auth')
        ->toContain('/api/v5/prometheus/auth')
        ->toContain('job_name: emqx_data_integration')
        ->toContain('/api/v5/prometheus/data_integration')
        ->toContain('emqx:18083');

    expect($lokiConfig)
        ->not->toBeFalse()
        ->toContain('retention_period: ${LOKI_RETENTION_PERIOD}');

    expect($grafanaDatasources)
        ->not->toBeFalse()
        ->toContain('uid: PBFA97CFB590B2093')
        ->toContain('http://prometheus:9090')
        ->toContain('uid: P8E80F9AEF21F6940')
        ->toContain('http://loki:3100');

    expect($grafanaDashboardProvider)
        ->not->toBeFalse()
        ->toContain('IoT Portal Monitoring')
        ->toContain('/etc/grafana/provisioning/dashboards');

    expect($grafanaOverviewDashboard)
        ->toHaveKey('uid', 'iot-portal-overview')
        ->toHaveKey('title', 'IoT Portal - Overview');

    expect($grafanaContainerMetricsDashboard)
        ->toHaveKey('uid', 'iot-portal-container-metrics')
        ->toHaveKey('title', 'IoT Portal - Container Metrics');

    expect($grafanaLogsRuntimeDashboard)
        ->toHaveKey('uid', 'iot-portal-logs-runtime')
        ->toHaveKey('title', 'IoT Portal - Logs & Runtime');

    expect($grafanaOverviewDashboardJson)
        ->not->toBeFalse()
        ->toContain('"uid": "PBFA97CFB590B2093"')
        ->toContain('node_cpu_seconds_total')
        ->toContain('node_memory_MemAvailable_bytes')
        ->toContain('node_filesystem_avail_bytes');

    expect($grafanaContainerMetricsDashboardJson)
        ->not->toBeFalse()
        ->toContain('"uid": "PBFA97CFB590B2093"')
        ->toContain('container_cpu_usage_seconds_total')
        ->toContain('container_memory_working_set_bytes')
        ->toContain('container_fs_usage_bytes')
        ->toContain('container_network_receive_bytes_total')
        ->toContain('container_network_transmit_bytes_total');

    expect($grafanaLogsRuntimeDashboardJson)
        ->not->toBeFalse()
        ->toContain('"uid": "PBFA97CFB590B2093"')
        ->toContain('"uid": "P8E80F9AEF21F6940"')
        ->toContain('{compose_project=\\"$compose_project\\", service=~\\"$service\\", container=~\\"$container\\"}')
        ->toContain('count_over_time')
        ->toContain('(?i)$search')
        ->toContain('web|horizon|scheduler|reverb|iot-ingest-telemetry|iot-listen-presence|iot-listen-states|emqx');

    expect($lokiConfig)
        ->not->toBeFalse()
        ->toContain('retention_period: ${LOKI_RETENTION_PERIOD}')
        ->toContain('schema: v13');
});

it('defines a local monitoring overlay for the sail network', function (): void {
    $compose = Yaml::parseFile(base_path('compose.monitoring.yaml'));
    $services = $compose['services'];

    expect($services)->toHaveKeys([
        'grafana',
        'loki',
        'prometheus',
        'alloy',
        'node-exporter',
        'cadvisor',
    ]);

    expect($services['grafana']['image'])->toBe('${GRAFANA_IMAGE:-grafana/grafana-oss:13.0.2}')
        ->and($services['grafana']['ports'])->toContain('${GRAFANA_BIND:-127.0.0.1}:${GRAFANA_PORT:-3000}:3000')
        ->and($services['alloy']['volumes'])->toContain('/var/run/docker.sock:/var/run/docker.sock:ro')
        ->and($services['prometheus']['volumes'])->toContain('./docker/monitoring/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro')
        ->and($services['loki']['volumes'])->toContain('./docker/monitoring/loki/config.yaml:/etc/loki/config.yaml:ro')
        ->and($compose['networks']['sail']['external'])->toBeTrue()
        ->and($compose['networks']['sail']['name'])->toBe('${MONITORING_DOCKER_NETWORK:-iot-portal_sail}');
});

it('defines a forge reverse proxy overlay for docker production', function (): void {
    $compose = Yaml::parseFile(base_path('compose.forge.yaml'));
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $caddyfile = file_get_contents(base_path('docker/production/Caddyfile.forge'));

    expect($compose['services']['proxy']['profiles'])->toContain('caddy-edge')
        ->and($compose['services']['proxy-forge']['ports'])->toContain('${FORGE_PROXY_BIND:-127.0.0.1}:${FORGE_PROXY_PORT:-18080}:80')
        ->and($compose['services']['proxy-forge']['volumes'])->toContain('./docker/production/Caddyfile.forge:/etc/caddy/Caddyfile:ro')
        ->and($compose['services']['proxy-forge']['depends_on'])->toContain('web', 'reverb')
        ->and($compose['volumes'])->toHaveKeys(['caddy-forge-data', 'caddy-forge-config']);

    expect($productionEnvironment)
        ->not->toBeFalse()
        ->toContain('FORGE_PROXY_BIND=127.0.0.1')
        ->toContain('FORGE_PROXY_PORT=18080');

    expect($caddyfile)
        ->not->toBeFalse()
        ->toContain('auto_https off')
        ->toContain('reverse_proxy @reverb reverb:8090')
        ->toContain('reverse_proxy web:8000')
        ->toContain('header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}')
        ->toContain('header_up X-Forwarded-Port {http.request.header.X-Forwarded-Port}');
});

it('documents production environment variables for proxy and reverb separation', function (): void {
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $exampleEnvironment = file_get_contents(base_path('.env.example'));

    expect($productionEnvironment)
        ->not->toBeFalse()
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('TRUSTED_PROXIES=*')
        ->toContain('TRUSTED_HOSTS=^iot\\.example\\.com$')
        ->toContain('REVERB_BROADCAST_HOST=reverb')
        ->toContain('REVERB_BROADCAST_SCHEME=http')
        ->toContain('REVERB_PUBLIC_HOST=iot.example.com')
        ->toContain('VITE_REVERB_HOST=iot.example.com')
        ->toContain('NATS_CLIENT_BIND=127.0.0.1')
        ->not->toContain('NATS_MQTT_BIND=')
        ->toContain('IOT_NATS_PORT=4222')
        ->toContain('IOT_MQTT_HOST=emqx')
        ->toContain('INGESTION_NATS_PORT=4222')
        ->toContain("INGESTION_NATS_SUBJECT='devices.*.telemetry,devices.*.*.telemetry,devices.*.*.*.telemetry,migration.source.imoni.*.*.telemetry,migration.source.egravity.*.telemetry'")
        ->toContain("IOT_DEVICE_STATE_NATS_SUBJECT='devices.*.state,devices.*.*.state,devices.*.*.*.state'")
        ->toContain('EMQX_MQTT_BIND=127.0.0.1')
        ->toContain('EMQX_DASHBOARD_BIND=127.0.0.1')
        ->toContain('EMQX_DASHBOARD_PASSWORD=replace-with-strong-emqx-dashboard-password')
        ->toContain('NODE_RED_MQTT_USERNAME=node-red-migration')
        ->toContain('NODE_RED_MQTT_PASSWORD=replace-with-node-red-mqtt-password')
        ->toContain('IOT_DEVICE_MQTT_USERNAME=device-client')
        ->toContain('IOT_DEVICE_MQTT_PASSWORD=replace-with-device-mqtt-password')
        ->toContain('NODE_RED_MQTT_BROKER_HOST=emqx');

    expect($exampleEnvironment)
        ->not->toBeFalse()
        ->toContain('TRUSTED_PROXIES=')
        ->toContain('TRUSTED_HOSTS=')
        ->toContain('REVERB_BROADCAST_HOST=')
        ->toContain('REVERB_PUBLIC_HOST=')
        ->toContain('IOT_MQTT_HOST=emqx')
        ->toContain('NODE_RED_MQTT_BROKER_HOST=emqx');
});

it('uses ELWIDS as the application brand in every environment and production build fallback', function (): void {
    $exampleEnvironment = file_get_contents(base_path('.env.example'));
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $workflow = file_get_contents(base_path('.github/workflows/docker-production.yml'));

    expect($exampleEnvironment)
        ->not->toBeFalse()
        ->toContain('APP_NAME=ELWIDS')
        ->toContain('SMS_GATEWAY_MASK=ELWIDS');

    expect($productionEnvironment)
        ->not->toBeFalse()
        ->toContain('APP_NAME=ELWIDS')
        ->toContain('SMS_GATEWAY_MASK=ELWIDS');

    expect($dockerfile)
        ->not->toBeFalse()
        ->toContain('ARG VITE_APP_NAME="ELWIDS"');

    expect($workflow)
        ->not->toBeFalse()
        ->toContain("VITE_APP_NAME=\${{ vars.VITE_APP_NAME || 'ELWIDS' }}");

    expect(config('app.name'))->toBe('ELWIDS')
        ->and(config('services.sms.mask'))->toBe('ELWIDS');
});

it('configures laravel for reverse proxies and internal reverb broadcasting', function (): void {
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
    $applicationConfig = file_get_contents(config_path('app.php'));
    $broadcastingConfig = file_get_contents(config_path('broadcasting.php'));
    $reverbConfig = file_get_contents(config_path('reverb.php'));

    expect($bootstrap)
        ->not->toBeFalse()
        ->toContain('$middleware->trustProxies(')
        ->toContain('Request::HEADER_X_FORWARDED_PROTO')
        ->toContain('$middleware->trustHosts(');

    expect($applicationConfig)
        ->not->toBeFalse()
        ->toContain("'trusted_proxies'")
        ->toContain("'trusted_hosts'");

    expect($broadcastingConfig)
        ->not->toBeFalse()
        ->toContain("env('REVERB_BROADCAST_HOST', env('REVERB_HOST'))")
        ->toContain("env('REVERB_BROADCAST_SCHEME', env('REVERB_SCHEME', 'https'))");

    expect($reverbConfig)
        ->not->toBeFalse()
        ->toContain("env('REVERB_PUBLIC_HOST', env('REVERB_HOST'))")
        ->toContain("env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'https'))");
});
