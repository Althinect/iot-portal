<?php

declare(strict_types=1);

it('defaults octane to frankenphp', function (): void {
    $octaneConfiguration = file_get_contents(config_path('octane.php'));

    expect($octaneConfiguration)
        ->not->toBeFalse()
        ->toMatch("/'server'\\s*=>\\s*env\\('OCTANE_SERVER',\\s*'frankenphp'\\)/");
});

it('documents the frankenphp octane environment variables in the example file', function (): void {
    $documentedVariables = [];

    foreach (file(base_path('.env.example')) ?: [] as $line) {
        if (preg_match('/^\s*#?\s*([A-Z0-9_]+)=/', $line, $matches) === 1) {
            $documentedVariables[$matches[1]] = true;
        }
    }

    expect($documentedVariables)->toHaveKeys([
        'OCTANE_SERVER',
        'OCTANE_HOST',
        'OCTANE_PORT',
        'OCTANE_ADMIN_HOST',
        'OCTANE_ADMIN_PORT',
        'OCTANE_WORKERS',
        'OCTANE_MAX_REQUESTS',
        'OCTANE_HTTPS',
        'OCTANE_WATCH',
        'OCTANE_POLL',
    ]);
});

it('starts the docker web service with octane and frankenphp', function (): void {
    $composeConfiguration = file_get_contents(base_path('compose.yaml'));
    $startupScript = file_get_contents(base_path('scripts/start-octane-frankenphp.sh'));

    expect($composeConfiguration)
        ->not->toBeFalse()
        ->toContain('command: /var/www/html/scripts/start-octane-frankenphp.sh')
        ->toContain('OCTANE_SERVER: frankenphp')
        ->toContain("OCTANE_WATCH: 'true'")
        ->toContain("OCTANE_POLL: 'true'")
        ->toContain('XDG_CONFIG_HOME: /var/www/html/storage/octane/xdg/config')
        ->toContain('XDG_DATA_HOME: /var/www/html/storage/octane/xdg/data');

    expect($startupScript)
        ->not->toBeFalse()
        ->toContain('php artisan octane:install --server=frankenphp --no-interaction')
        ->toContain('php artisan octane:frankenphp')
        ->toContain('"--admin-host=${OCTANE_ADMIN_HOST:-127.0.0.1}"');
});
