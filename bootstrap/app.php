<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $environmentValue = static function (string $key): ?string {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            return $value;
        };

        $environmentList = static function (string $key) use ($environmentValue): array {
            $value = $environmentValue($key);

            if ($value === null) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn (string $item): string => trim($item),
                explode(',', $value)
            )));
        };

        $trustedProxies = $environmentList('TRUSTED_PROXIES');

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: in_array('*', $trustedProxies, true) ? '*' : $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
            );
        }

        $trustedHosts = $environmentList('TRUSTED_HOSTS');

        if ($trustedHosts !== []) {
            $middleware->trustHosts(at: $trustedHosts);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
