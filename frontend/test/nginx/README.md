# Local Image Cache Validation

Run from `frontend`, using locked, platform-native dependencies. The harness
uses only synthetic local HTTP/WebP fixtures and an isolated Nginx prefix.
It does not install software, start system services, contact suppliers or use
the VPS. The output directory must not exist. No test failure is retried.

## Windows Or Linux Nginx

```sh
NGINX_BINARY=/absolute/path/to/nginx \
NGINX_FASTCGI_PARAMS=/absolute/path/to/fastcgi_params \
NGINX_TEST_OUTPUT=/absolute/new/output \
node test/nginx/card-cache.mjs
```

On Windows use the equivalent process-local PowerShell environment variables.
See [Catalog Card Images](../../../docs/CATALOG_CARD_IMAGES.md). The existing
`frontend-card-cache` CI job runs this command on Ubuntu with extracted Nginx
and a required isolated Apache chain (`REQUIRE_APACHE=1`).
It keeps `results.json`, incremental `events.jsonl`, config files and logs.

The actual template supplies both an inner and a downstream Nginx cache.
Short fixture TTLs exercise browser expiry, sparse shared HIT, an aged late
downstream fill, absolute expiry, replacements, privacy vetoes, same-socket
reuse, a held MISS, real upstream timeout, disk reuse after restart and fresh
rollback namespace isolation. The three Nginx starts are fixture lifecycle
checks, not three production runs. Source fetch/transform policy values are
separately tested through the actual handler in `product-card-images.test.ts`.

Optional failure-path check, in another new output directory:

```sh
NGINX_TEST_FAIL_AFTER_WARM_REUSE=1 \
NGINX_BINARY=/absolute/path/to/nginx \
NGINX_FASTCGI_PARAMS=/absolute/path/to/fastcgi_params \
NGINX_TEST_OUTPUT=/absolute/new/failure-output \
node test/nginx/card-cache.mjs
```

Expected: exit 1 with the controlled primary failure retained, held requests
released, Nginx close observed, and cleanup PASS. This is not a skipped assertion
or an application PASS.

## Alpine And Apache Chain

In a separately authorized, already provisioned disposable Alpine environment,
install dependencies from this lockfile for that platform, not from a Windows
`node_modules` copy. Run as an ordinary user, with writable new output/cache/temp
directories. Use the actual `nginx:1.27-alpine` runtime/version for the Nginx gate;
its binary/libraries must be available to the Node harness in the isolated test
environment. An arbitrary Alpine distribution Nginx is not automatically parity
with that image. No Compose stack, database, product storage or host config is
needed. The same harness is portable; the environment must provide its binaries.

For an installed Linux/Alpine Apache with dynamic event MPM and proxy modules:

```sh
APACHE_BINARY=/absolute/path/to/httpd \
APACHE_MODULE_DIR=/absolute/path/to/apache/modules \
REQUIRE_APACHE=1 \
NGINX_BINARY=/absolute/path/to/nginx \
NGINX_FASTCGI_PARAMS=/absolute/path/to/fastcgi_params \
NGINX_TEST_OUTPUT=/absolute/new/apache-chain-output \
node test/nginx/card-cache.mjs
```

The helper validates the actual Apache config, starts one foreground `-X`
process on loopback, and proxies only `/_ipx/cards/` to this harness's Nginx.
It sets `AllowEncodedSlashes NoDecode` and the specific `ProxyPass` route uses
`nocanon`, before any future catch-all. It checks a production-shaped encoded
source URL as an opaque token, never fetching it. The synthetic upstream accepts
only explicitly listed paths, rejecting decoded, double-encoded and collapsed
slash controls. Evidence records the exact upstream path, MISS/HIT counts,
WebP bytes, Date/Cache-Control/absolute expiry, late HIT and synchronous expiry.
It also checks client and backend connection reuse,
then observes SIGTERM/close of its own process. It never changes live Apache
configuration. Missing modules or a supplied invalid executable fail the run.
Without `APACHE_BINARY`, local optional checks record Apache NOT RUN; with
`REQUIRE_APACHE=1`, a missing executable is a failure. The Linux CI job installs
only `apache2-bin` and its dependencies, not the system-service package; the
fixture runs under the ordinary runner user with its own config and PID.

The separate `frontend-card-cache-alpine` job pulls the project's
`nginx:1.27-alpine`, records its resolved digest/image identity and builds
`Dockerfile.alpine` FROM that digest. It adds CI-only Node/npm and locked
platform-native dependencies (ignoring application lifecycle scripts), not
another Nginx binary. It runs the harness as `nginx`, with writable isolated
cache/temp/output paths. `REQUIRE_ALPINE=1` rejects a non-Alpine or root runtime.
Node/Sharp/libvips, Nginx, Alpine release and UID/GID are recorded. Apache is
tested by the Ubuntu job, not claimed by the Alpine job.

This is a real Nginx-container **harness fixture**, not a build or runtime test
of `frontend/Dockerfile`. It does not test live Apache/VPS configuration. CI
retains image/container metadata, logs and success/failure results before
removing only its named disposable container. No cache objects are uploaded.
