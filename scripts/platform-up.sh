#!/bin/bash

set -euo pipefail

PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

usage() {
    cat <<'EOF'
Usage: ./scripts/platform-up.sh [--vite]

Starts the full Docker platform stack defined in compose.yaml:
- laravel.test (web app)
- pgsql (TimescaleDB)
- redis
- nats
- node-red
- mailpit
- reverb
- iot-listen-states
- iot-listen-presence
- iot-ingest-telemetry
- horizon
- scheduler

Options:
  --vite    Also start Vite dev server inside laravel.test
EOF
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

include_vite=0

for arg in "$@"; do
    case "$arg" in
        --vite)
            include_vite=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            usage
            exit 1
            ;;
    esac
done

script_dir="$(cd "${BASH_SOURCE[0]%/*}" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
compose_file="$repo_root/compose.yaml"

if ! command_exists docker; then
    echo "Error: docker is required." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Error: docker compose is required." >&2
    exit 1
fi

if [[ ! -f "$compose_file" ]]; then
    echo "Error: compose file not found at $compose_file" >&2
    exit 1
fi

if [[ ! -f "$repo_root/.env" ]]; then
    echo "No .env file found. Copying .env.example..."
    cp "$repo_root/.env.example" "$repo_root/.env"
    if sed --version >/dev/null 2>&1; then
        sed -i 's/^APP_NAME=.*/APP_NAME="LMU IoT Portal"/' "$repo_root/.env"
    else
        sed -i '' 's/^APP_NAME=.*/APP_NAME="LMU IoT Portal"/' "$repo_root/.env"
    fi
fi

host_uid="$(id -u)"
host_gid="$(id -g)"

if [[ ! -d "$repo_root/vendor/laravel/sail" ]]; then
    echo "Composer dependencies not installed. Installing via Docker..."
    docker run --rm \
        -u "$host_uid:$host_gid" \
        -v "$repo_root:/app" \
        -w /app \
        composer:latest \
        composer install --ignore-platform-reqs --no-interaction
fi

if [[ ! -f "$repo_root/public/build/manifest.json" ]]; then
    echo "Vite manifest not found. Installing npm dependencies and building assets via Docker..."
    docker run --rm \
        -u "$host_uid:$host_gid" \
        -v "$repo_root:/app" \
        -w /app \
        node:lts-alpine \
        sh -c "npm install && npm run build"
fi

first_run=0
if grep -q '^APP_KEY=$' "$repo_root/.env"; then
    first_run=1
fi

echo "Starting Docker platform stack..."
(cd "$repo_root" && docker compose -f compose.yaml up -d --build)

echo "Fixing storage permissions..."
(cd "$repo_root" && docker compose -f compose.yaml exec laravel.test chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache)

if [[ "$first_run" -eq 1 ]]; then
    echo "First-time setup detected. Generating app key..."
    (cd "$repo_root" && docker compose -f compose.yaml exec laravel.test php artisan key:generate --no-interaction)
fi

echo "Running migrations..."
(cd "$repo_root" && docker compose -f compose.yaml exec laravel.test php artisan migrate --no-interaction)

if [[ "$first_run" -eq 1 ]]; then
    echo "Seeding database and initializing PKI..."
    (cd "$repo_root" && docker compose -f compose.yaml exec laravel.test php artisan db:seed --no-interaction)
    (cd "$repo_root" && docker compose -f compose.yaml exec laravel.test php artisan iot:pki:init --no-interaction)
    echo "Restarting containers to pick up new app key..."
    (cd "$repo_root" && docker compose -f compose.yaml restart)
fi

if [[ "$include_vite" -eq 1 ]]; then
    echo "Starting Vite dev server in laravel.test..."
    (cd "$repo_root" && docker compose -f compose.yaml exec -d laravel.test sh -lc "npm run dev -- --host 0.0.0.0 --port ${VITE_PORT:-5173}")
fi

echo
echo "Platform status:"
(cd "$repo_root" && docker compose -f compose.yaml ps)

echo
echo "Platform is up."
