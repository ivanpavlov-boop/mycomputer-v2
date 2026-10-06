#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

fail() {
    printf 'BACKEND_IMAGE_CHECK_FAILED: %s\n' "$*" >&2
    exit 1
}

command -v docker >/dev/null || fail 'Docker is required.'

if [[ $# == 0 ]]; then
    command -v jq >/dev/null || fail 'jq is required for Compose image selection.'
    # Resolve build tags from configuration, not images of existing containers.
    # Only image references leave this pipe; never print the resolved environment.
    references="$(docker compose config --no-env-resolution --format json | jq -er '
        . as $project | ["app", "queue", "scheduler"][] as $service |
        $project.services[$service] as $config |
        if $config == null then error("Missing backend service: " + $service)
        else ($config.image // ($project.name + "-" + $service)) end
    ')"
    mapfile -t image_refs <<< "$references"
    [[ ${#image_refs[@]} == 3 ]] || fail 'Expected three backend image references.'
    labels=(app queue scheduler)
elif [[ $# == 2 && $1 == --image ]]; then
    image_refs=("$2")
    labels=(explicit)
else
    fail 'Usage: bash scripts/verify-backend-images.sh [--image LOCAL_IMAGE]'
fi

image_ids=()
for reference in "${image_refs[@]}"; do
    [[ -n $reference && $reference != -* && $reference != *[[:space:]]* ]] || fail 'Invalid image reference.'
    image_id="$(docker image inspect --format '{{.Id}}' "$reference")"
    [[ $image_id =~ ^sha256:[0-9a-f]{64}$ ]] || fail "Invalid local image ID for $reference."
    image_ids+=("$image_id")
done

for index in "${!image_ids[@]}"; do
    printf 'VERIFY_BACKEND_IMAGE service=%s image=%s\n' "${labels[$index]}" "${image_ids[$index]}"
    # No Compose service environment, volumes, application bootstrap or network.
    # Stream the probe so its own file permissions cannot bypass the check.
    docker run --rm -i --pull never --read-only --network none \
        --cap-drop ALL --security-opt no-new-privileges \
        --user www-data --workdir /var/www/html --entrypoint sh \
        "${image_ids[$index]}" -ec '
            test "$(id -un)" = www-data
            test "$(id -u)" -ne 0
            printf "PROBE_USER=%s UID=%s\n" "$(id -un)" "$(id -u)"
            exec php -d display_errors=stderr -d log_errors=0
        ' < "$script_dir/backend-image-probe.php"
done

# Detect retagging during the checks. Deployments must still be serialized;
# this script does not lock Docker or authorize subsequent activation.
for index in "${!image_ids[@]}"; do
    current_id="$(docker image inspect --format '{{.Id}}' "${image_refs[$index]}")"
    [[ $current_id == "${image_ids[$index]}" ]] || fail "Image changed during validation: ${labels[$index]}."
done

printf 'BACKEND_IMAGES_READABLE_OK\n'
