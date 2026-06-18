#!/bin/bash

set -euo pipefail

PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

usage() {
    cat <<'USAGE'
Usage: ./scripts/deploy-production.sh

Pulls the immutable production image and reconciles the Docker Compose stack.

Required:
  .env.production with APP_IMAGE, APP_URL, APP_DOMAIN, database, Redis, Reverb, and NATS settings.

Optional:
  PRODUCTION_COMPOSE_FILE=compose.production.yaml
  PRODUCTION_ENV_FILE=.env.production
USAGE
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

script_dir="$(cd "${BASH_SOURCE[0]%/*}" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
compose_file="${PRODUCTION_COMPOSE_FILE:-compose.production.yaml}"
env_file="${PRODUCTION_ENV_FILE:-.env.production}"

if ! command_exists docker; then
    echo "Error: docker is required." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Error: docker compose is required." >&2
    exit 1
fi

if [[ ! -f "$repo_root/$compose_file" ]]; then
    echo "Error: compose file not found at $repo_root/$compose_file" >&2
    exit 1
fi

if [[ ! -f "$repo_root/$env_file" ]]; then
    echo "Error: production env file not found at $repo_root/$env_file" >&2
    exit 1
fi

compose=(docker compose --env-file "$env_file" -f "$compose_file")

cd "$repo_root"

echo "Pulling production images..."
"${compose[@]}" pull

echo "Starting stateful dependencies..."
"${compose[@]}" up -d --wait --wait-timeout 300 pgsql redis nats

echo "Running database migrations..."
"${compose[@]}" run --rm --no-deps web php artisan migrate --force --no-interaction

echo "Caching Laravel deployment artifacts..."
"${compose[@]}" run --rm --no-deps web php artisan optimize

echo "Signaling Horizon to reload after current jobs finish..."
"${compose[@]}" run --rm --no-deps web php artisan horizon:terminate || true

echo "Reconciling production stack..."
"${compose[@]}" up -d --remove-orphans

echo
echo "Production stack status:"
"${compose[@]}" ps
