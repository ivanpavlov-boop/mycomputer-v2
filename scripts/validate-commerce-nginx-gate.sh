#!/bin/sh

set -eu

repository_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
template="${repository_root}/deploy/nginx/mycomputer.conf.template"
output="$(mktemp)"
mock_frontend="$(mktemp)"
active_container=""

cleanup() {
    if [ -n "$active_container" ]; then
        docker stop "$active_container" >/dev/null 2>&1 || true
    fi

    rm -f "$output" "$mock_frontend"
}

trap cleanup EXIT

cat >"$mock_frontend" <<'EOF'
server {
    listen 3000;
    server_name _;

    location / {
        return 200 "mock frontend";
    }
}
EOF

http_status() {
    path="$1"
    response="$(docker exec "$active_container" \
        wget -S -O /dev/null --max-redirect=0 "http://127.0.0.1${path}" 2>&1 || true)"
    status="$(printf '%s\n' "$response" | awk '
        /HTTP\/1\.[01] [0-9][0-9][0-9]/ { code = $2 }
        END { print code }
    ')"

    if [ -z "$status" ]; then
        echo "Could not read HTTP status for ${path}." >&2
        return 1
    fi

    printf '%s' "$status"
}

assert_status() {
    path="$1"
    expected="$2"
    actual="$(http_status "$path")"

    if [ "$actual" != "$expected" ]; then
        echo "${path}: expected ${expected}, received ${actual}." >&2
        return 1
    fi
}

validate_state() {
    state="$1"
    commerce_enabled="$2"
    confirmation_enabled="$3"
    legal_approved="$4"
    expected_cart="$5"
    expected_checkout="$6"
    expected_confirmation="$7"
    active_container="commerce-nginx-${state}-$$"

    docker run --rm -d \
        --name "$active_container" \
        --add-host frontend:127.0.0.1 \
        --add-host app:127.0.0.1 \
        -e "PUBLIC_COMMERCE_ENABLED=${commerce_enabled}" \
        -e "PUBLIC_COMMERCE_CONFIRMATION_ENABLED=${confirmation_enabled}" \
        -e "LEGAL_CONTENT_APPROVED=${legal_approved}" \
        -e 'NGINX_ENVSUBST_FILTER=^(PUBLIC_COMMERCE_ENABLED|PUBLIC_COMMERCE_CONFIRMATION_ENABLED|LEGAL_CONTENT_APPROVED)$' \
        -v "${template}:/etc/nginx/templates/default.conf.template:ro" \
        -v "${mock_frontend}:/etc/nginx/conf.d/frontend-mock.conf:ro" \
        nginx:1.27-alpine >/dev/null

    docker exec "$active_container" nginx -T >"$output" 2>&1

    grep -F 'proxy_set_header Host $host;' "$output" >/dev/null
    grep -F 'try_files $uri $uri/ /index.php?$query_string;' "$output" >/dev/null
    grep -F 'fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;' "$output" >/dev/null
    grep -F "set \$public_commerce_enabled \"${commerce_enabled}\";" "$output" >/dev/null
    grep -F "set \$public_commerce_confirmation_enabled \"${confirmation_enabled}\";" "$output" >/dev/null
    grep -F "set \$legal_content_approved \"${legal_approved}\";" "$output" >/dev/null

    if grep -F '${PUBLIC_COMMERCE_' "$output" >/dev/null; then
        echo "Unrendered commerce placeholder in ${state} state." >&2
        return 1
    fi

    assert_status /cart "$expected_cart"
    assert_status /checkout "$expected_checkout"
    assert_status /checkout/success "$expected_confirmation"
    assert_status /obshti-usloviya 200
    assert_status /politika-za-poveritelnost 200
    assert_status /obshti-usloviya/ 308
    assert_status /politika-za-poveritelnost/ 308
    assert_status /en/terms 404
    assert_status /en/privacy 404
    assert_status /en/obshti-usloviya 404
    assert_status /en/politika-za-poveritelnost 404

    docker stop "$active_container" >/dev/null
    active_container=""

    echo "Nginx ${state} state: syntax and route matrix valid"
}

validate_state closed false false false 404 404 404
validate_state confirmation-only false true false 404 404 200
validate_state open true true true 200 200 200
validate_state legal-unapproved true true false 404 404 200
validate_state invalid true false true 404 404 404
