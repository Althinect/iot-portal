#!/bin/bash

set -euo pipefail

PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

usage() {
    cat <<'USAGE'
Usage: ./scripts/backup-production-db.sh

Creates a PostgreSQL custom-format dump from the production Compose pgsql service
and uploads it to S3-compatible object storage.

Required in .env.production:
  BACKUP_S3_BUCKET
  BACKUP_S3_ACCESS_KEY_ID
  BACKUP_S3_SECRET_ACCESS_KEY

Optional in .env.production:
  BACKUP_S3_ENDPOINT_URL
  BACKUP_S3_REGION
  BACKUP_S3_PREFIX

Optional shell env:
  PRODUCTION_COMPOSE_FILE=compose.production.yaml
  PRODUCTION_COMPOSE_FILES=compose.production.yaml:compose.forge.yaml
  PRODUCTION_ENV_FILE=.env.production
  BACKUP_LOCAL_DIR=backups/postgres
  BACKUP_KEEP_LOCAL=false
  BACKUP_S3_IMAGE=amazon/aws-cli:2
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
compose_files_config="${PRODUCTION_COMPOSE_FILES:-${PRODUCTION_COMPOSE_FILE:-compose.production.yaml}}"
env_file="${PRODUCTION_ENV_FILE:-.env.production}"
backup_local_dir="${BACKUP_LOCAL_DIR:-backups/postgres}"
backup_keep_local="${BACKUP_KEEP_LOCAL:-false}"
backup_s3_image="${BACKUP_S3_IMAGE:-amazon/aws-cli:2}"

if ! command_exists docker; then
    echo "Error: docker is required." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Error: docker compose is required." >&2
    exit 1
fi

if [[ ! -f "$repo_root/$env_file" ]]; then
    echo "Error: production env file not found at $repo_root/$env_file" >&2
    exit 1
fi

cd "$repo_root"

IFS=':' read -r -a compose_files <<< "$compose_files_config"
compose=(docker compose --env-file "$env_file")

for compose_file in "${compose_files[@]}"; do
    if [[ -z "$compose_file" ]]; then
        continue
    fi

    if [[ ! -f "$repo_root/$compose_file" ]]; then
        echo "Error: compose file not found at $repo_root/$compose_file" >&2
        exit 1
    fi

    compose+=(-f "$compose_file")
done

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="$repo_root/$backup_local_dir"
backup_filename="postgres-${timestamp}.dump"
backup_path="$backup_dir/$backup_filename"

mkdir -p "$backup_dir"
chmod 700 "$backup_dir"

echo "Creating PostgreSQL backup at $backup_path..."
"${compose[@]}" exec -T pgsql sh -lc \
    'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --compress=9 --no-owner --no-acl' \
    > "$backup_path"

if [[ ! -s "$backup_path" ]]; then
    echo "Error: backup file is empty." >&2
    exit 1
fi

echo "Uploading backup to S3-compatible storage..."
docker run --rm \
    --env-file "$env_file" \
    -e AWS_EC2_METADATA_DISABLED=true \
    -e BACKUP_FILE="/backups/$backup_filename" \
    -e BACKUP_FILENAME="$backup_filename" \
    -v "$backup_dir:/backups:ro" \
    --entrypoint sh \
    "$backup_s3_image" \
    -lc '
        set -eu

        : "${BACKUP_S3_BUCKET:?Set BACKUP_S3_BUCKET in .env.production}"

        if [ -n "${BACKUP_S3_ACCESS_KEY_ID:-}" ]; then
            export AWS_ACCESS_KEY_ID="$BACKUP_S3_ACCESS_KEY_ID"
        fi

        if [ -n "${BACKUP_S3_SECRET_ACCESS_KEY:-}" ]; then
            export AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET_ACCESS_KEY"
        fi

        : "${AWS_ACCESS_KEY_ID:?Set BACKUP_S3_ACCESS_KEY_ID or AWS_ACCESS_KEY_ID in .env.production}"
        : "${AWS_SECRET_ACCESS_KEY:?Set BACKUP_S3_SECRET_ACCESS_KEY or AWS_SECRET_ACCESS_KEY in .env.production}"

        export AWS_DEFAULT_REGION="${BACKUP_S3_REGION:-${AWS_DEFAULT_REGION:-us-east-1}}"

        prefix="${BACKUP_S3_PREFIX:-postgres}"
        prefix="${prefix#/}"
        prefix="${prefix%/}"

        if [ -n "$prefix" ]; then
            backup_key="$prefix/$BACKUP_FILENAME"
        else
            backup_key="$BACKUP_FILENAME"
        fi

        set -- s3 cp "$BACKUP_FILE" "s3://$BACKUP_S3_BUCKET/$backup_key" --no-progress --region "$AWS_DEFAULT_REGION"

        if [ -n "${BACKUP_S3_ENDPOINT_URL:-}" ]; then
            set -- "$@" --endpoint-url "$BACKUP_S3_ENDPOINT_URL"
        fi

        aws "$@"
    '

if [[ "$backup_keep_local" == "true" || "$backup_keep_local" == "1" ]]; then
    echo "Backup uploaded and retained locally at $backup_path."
else
    rm -f "$backup_path"
    echo "Backup uploaded and local copy removed."
fi
