#!/bin/sh

set -eu

mkdir -p \
    "${XDG_CONFIG_HOME:-/app/storage/octane/xdg/config}" \
    "${XDG_DATA_HOME:-/app/storage/octane/xdg/data}" \
    /app/bootstrap/cache \
    /app/storage/app/public \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

set -- php artisan octane:frankenphp \
    "--host=${OCTANE_HOST:-0.0.0.0}" \
    "--port=${OCTANE_PORT:-8000}" \
    "--admin-host=${OCTANE_ADMIN_HOST:-127.0.0.1}" \
    "--admin-port=${OCTANE_ADMIN_PORT:-2019}" \
    "--workers=${OCTANE_WORKERS:-auto}" \
    "--max-requests=${OCTANE_MAX_REQUESTS:-500}"

if [ -n "${OCTANE_LOG_LEVEL:-}" ]; then
    set -- "$@" "--log-level=${OCTANE_LOG_LEVEL}"
fi

exec "$@"
