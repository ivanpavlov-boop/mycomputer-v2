#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".env"
ENV_TEMPLATE=".env.staging.example"

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
    printf '\nERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Missing required command [$1]."
}

wait_for_healthy() {
    local service="$1"
    local timeout="${2:-180}"
    local elapsed=0
    local container_id
    local status

    log "Waiting for ${service} to become healthy..."

    while [ "$elapsed" -lt "$timeout" ]; do
        container_id="$(docker compose ps -q "$service" || true)"

        if [ -n "$container_id" ]; then
            status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || true)"

            if [ "$status" = "healthy" ] || [ "$status" = "running" ]; then
                log "${service} is ${status}."
                return 0
            fi
        fi

        sleep 5
        elapsed=$((elapsed + 5))
    done

    docker compose ps "$service" || true
    docker compose logs --tail=100 "$service" || true
    fail "${service} did not become healthy within ${timeout}s."
}

ensure_env() {
    if [ ! -f "$ENV_FILE" ]; then
        [ -f "$ENV_TEMPLATE" ] || fail "Missing ${ENV_TEMPLATE}."
        cp "$ENV_TEMPLATE" "$ENV_FILE"
        log "Created ${ENV_FILE} from ${ENV_TEMPLATE}."
    fi

    if grep -q 'APP_KEY=base64:CHANGE_ME_GENERATE_WITH_BOOTSTRAP' "$ENV_FILE" || grep -q '^APP_KEY=$' "$ENV_FILE"; then
        require_command openssl
        local key
        key="base64:$(openssl rand -base64 32)"
        sed -i.bak "s#^APP_KEY=.*#APP_KEY=${key}#" "$ENV_FILE"
        rm -f "${ENV_FILE}.bak"
        log "Generated APP_KEY in ${ENV_FILE}."
    fi

    grep -q '^APP_KEY=base64:' "$ENV_FILE" || fail "APP_KEY must be set in ${ENV_FILE}."

    if grep -Eq 'change-this|CHANGE_ME' "$ENV_FILE"; then
        log "WARNING: ${ENV_FILE} still contains placeholder secrets. Replace them before public staging access."
    fi
}

require_command docker
docker compose version >/dev/null
ensure_env

log "Building Docker images..."
docker compose build

log "Starting infrastructure services..."
docker compose up -d mysql redis meilisearch
wait_for_healthy mysql 240
wait_for_healthy redis 120
wait_for_healthy meilisearch 180

log "Starting app container..."
docker compose up -d app
wait_for_healthy app 180

log "Running Laravel staging bootstrap commands..."
docker compose exec -T app composer dump-autoload --optimize
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan storage:link
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
docker compose exec -T app php artisan redis:health
docker compose exec -T app php artisan search:reindex

log "Starting web and background workers..."
docker compose up -d nginx queue scheduler

log "Staging bootstrap complete."
docker compose ps
