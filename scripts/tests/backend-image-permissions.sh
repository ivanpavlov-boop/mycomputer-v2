#!/usr/bin/env bash
set -euo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ $# == 1 ]] || { echo 'Usage: bash scripts/tests/backend-image-permissions.sh LOCAL_BACKEND_IMAGE' >&2; exit 1; }

fixture_dir="$(mktemp -d)"
suffix="$(basename "$fixture_dir" | tr '[:upper:]' '[:lower:]')"
base_tag="backend-permission-base:$suffix"
bad_tag="backend-permission-negative:$suffix"

cleanup() {
    docker image rm "$bad_tag" "$base_tag" >/dev/null 2>&1 || true
    rm -rf -- "$fixture_dir"
}
trap cleanup EXIT

base_id="$(docker image inspect --format '{{.Id}}' "$1")"
docker image tag "$base_id" "$base_tag"
bash "$root/scripts/verify-backend-images.sh" --image "$base_id"

# Exercise Compose selection with a dependency that must not be probed.
cat > "$fixture_dir/compose.yaml" <<EOF
services:
  app:
    image: $base_tag
    depends_on: [database]
  queue:
    image: $base_tag
  scheduler:
    image: $base_tag
  database:
    image: nonexistent-permission-test-database:unused
EOF
COMPOSE_FILE="$fixture_dir/compose.yaml" COMPOSE_PROJECT_NAME=permission-test \
    bash "$root/scripts/verify-backend-images.sh"

for path in \
    app/Http/Controllers/Api/V1/ProductController.php \
    app/Http/Resources/ProductCardResource.php \
    app/Services/Reviews/ReviewStatsService.php
do
    cat > "$fixture_dir/Dockerfile" <<EOF
FROM $base_tag
USER root
RUN chown 0:0 $path && chmod 0600 $path
EOF
    docker build --network none --tag "$bad_tag" "$fixture_dir"
    bad_id="$(docker image inspect --format '{{.Id}}' "$bad_tag")"
    if bash "$root/scripts/verify-backend-images.sh" --image "$bad_id" > "$fixture_dir/result.log" 2>&1; then
        cat "$fixture_dir/result.log"
        echo "FAIL: root-owned 0600 file passed: $path" >&2
        exit 1
    fi
    cat "$fixture_dir/result.log"
    grep -F "UNREADABLE_FILE: $path" "$fixture_dir/result.log" >/dev/null
    printf 'EXPECTED_PERMISSION_FAILURE: %s\n' "$path"
    docker image rm "$bad_tag" >/dev/null
done

# A readable file with no expected class must also fail the actual autoloader.
cat > "$fixture_dir/Dockerfile" <<EOF
FROM $base_tag
USER root
RUN printf '<?php\n' > app/Services/Reviews/ReviewStatsService.php
EOF
docker build --network none --tag "$bad_tag" "$fixture_dir"
if bash "$root/scripts/verify-backend-images.sh" --image "$bad_tag" > "$fixture_dir/result.log" 2>&1; then
    echo 'FAIL: missing class passed autoload validation.' >&2
    exit 1
fi
cat "$fixture_dir/result.log"
grep -F 'AUTOLOAD_FAILED: App\Services\Reviews\ReviewStatsService' "$fixture_dir/result.log" >/dev/null

# Negative images have not changed the supplied baseline.
bash "$root/scripts/verify-backend-images.sh" --image "$base_id"
printf 'BACKEND_PERMISSION_REGRESSION_OK\n'
