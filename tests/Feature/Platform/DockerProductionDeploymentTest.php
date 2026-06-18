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
        'horizon',
        'scheduler',
        'nightwatch',
        'pulse-check',
        'pulse-worker',
        'pgsql',
        'redis',
        'nats',
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
        ->and($services['reverb']['command'])->toBe('php artisan reverb:start --host=0.0.0.0 --port=8090')
        ->and($services['horizon']['command'])->toBe('php artisan horizon')
        ->and($services['horizon']['stop_grace_period'])->toBe('1h')
        ->and($services['web']['environment']['REVERB_BROADCAST_HOST'])->toBe('reverb')
        ->and($services['web']['environment']['REVERB_BROADCAST_SCHEME'])->toBe('http')
        ->and($services['web']['environment']['IOT_NATS_PORT'])->toBe(4222)
        ->and($services['web']['volumes'])->toContain('app-storage:/app/storage')
        ->and($services['web']['volumes'])->toContain('app-bootstrap-cache:/app/bootstrap/cache');
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
        ->toContain('docker compose --env-file "$env_file" -f "$compose_file"')
        ->toContain('pull')
        ->toContain('php artisan migrate --force --no-interaction')
        ->toContain('php artisan optimize')
        ->toContain('php artisan horizon:terminate')
        ->toContain('php artisan pulse:restart')
        ->toContain('up -d --wait --wait-timeout 300 pgsql redis nats')
        ->toContain('up -d --remove-orphans');

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
        ->toContain('docker compose --env-file "$env_file" -f "$compose_file"')
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
    $lokiConfig = file_get_contents(base_path('docker/monitoring/loki/config.yaml'));

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
        ->toContain('NIGHTWATCH_ENABLED=false')
        ->toContain('NIGHTWATCH_INGEST_URI=nightwatch:2407')
        ->toContain('PULSE_INGEST_DRIVER=redis')
        ->toContain('PULSE_REDIS_CONNECTION=default')
        ->toContain('GRAFANA_BIND=127.0.0.1')
        ->toContain('GRAFANA_ADMIN_PASSWORD=');

    expect($alloyConfig)
        ->not->toBeFalse()
        ->toContain('discovery.docker "containers"')
        ->toContain('loki.source.docker "containers"')
        ->toContain('http://loki:3100/loki/api/v1/push');

    expect($prometheusConfig)
        ->not->toBeFalse()
        ->toContain('node-exporter:9100')
        ->toContain('cadvisor:8080')
        ->toContain('alloy:12345');

    expect($grafanaDatasources)
        ->not->toBeFalse()
        ->toContain('http://prometheus:9090')
        ->toContain('http://loki:3100');

    expect($lokiConfig)
        ->not->toBeFalse()
        ->toContain('retention_period: 168h')
        ->toContain('schema: v13');
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
        ->toContain('NATS_MQTT_BIND=127.0.0.1')
        ->toContain('IOT_NATS_PORT=4222')
        ->toContain('INGESTION_NATS_PORT=4222');

    expect($exampleEnvironment)
        ->not->toBeFalse()
        ->toContain('TRUSTED_PROXIES=')
        ->toContain('TRUSTED_HOSTS=')
        ->toContain('REVERB_BROADCAST_HOST=')
        ->toContain('REVERB_PUBLIC_HOST=');
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
