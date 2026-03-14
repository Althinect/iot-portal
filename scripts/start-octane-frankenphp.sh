#!/bin/bash

set -euo pipefail

script_dir="$(cd "${BASH_SOURCE[0]%/*}" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"

export XDG_CONFIG_HOME="${XDG_CONFIG_HOME:-$repo_root/storage/octane/xdg/config}"
export XDG_DATA_HOME="${XDG_DATA_HOME:-$repo_root/storage/octane/xdg/data}"

mkdir -p "$XDG_CONFIG_HOME" "$XDG_DATA_HOME"

cd "$repo_root"

if [[ ! -x "$repo_root/frankenphp" || ! -f "$repo_root/public/frankenphp-worker.php" ]]; then
    php artisan octane:install --server=frankenphp --no-interaction
fi

octane_command=(
    php artisan octane:frankenphp
    "--host=${OCTANE_HOST:-0.0.0.0}"
    "--port=${OCTANE_PORT:-8000}"
    "--admin-host=${OCTANE_ADMIN_HOST:-127.0.0.1}"
    "--admin-port=${OCTANE_ADMIN_PORT:-2019}"
    "--workers=${OCTANE_WORKERS:-auto}"
    "--max-requests=${OCTANE_MAX_REQUESTS:-500}"
)

if [[ "${OCTANE_WATCH:-false}" == "true" ]]; then
    octane_command+=(--watch)
fi

if [[ "${OCTANE_POLL:-false}" == "true" ]]; then
    octane_command+=(--poll)
fi

if [[ -n "${OCTANE_CADDYFILE:-}" ]]; then
    octane_command+=("--caddyfile=${OCTANE_CADDYFILE}")
fi

if [[ -n "${OCTANE_LOG_LEVEL:-}" ]]; then
    octane_command+=("--log-level=${OCTANE_LOG_LEVEL}")
fi

exec "${octane_command[@]}"
