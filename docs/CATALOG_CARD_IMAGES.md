# Catalog Card Image Delivery

Product cards in `/catalog` and `/c/*` use a dedicated Nuxt Image provider and
`/_ipx/cards/<width>/<encoded-source>` endpoint. The existing gallery provider
and original files are unchanged. Widths are limited to 240, 320, 480, 640 and
1280 pixels, with responsive 1x/2x selection. IPX emits WebP, preserves aspect
ratio and does not enlarge smaller sources. Cards use `object-contain`.

Only the first two cards in the main result grid are eager; only the first has
high fetch priority. Secondary grids default to lazy. There is no image preload
list. Processing errors try the original URL once; an original-image error
shows the existing placeholder. Primary image choice, verbatim nonblank ALT,
name fallback and localized product links are preserved.

Category detail and product requests start together. Their existing locale keys,
query/watch inputs and loading/error branches remain independent.

## Public Source Boundary

The endpoint only reads the existing APCOM product-image namespace or `/storage/`
on the configured public API/site origin. Storage requests are mapped to the
configured internal API origin. It does not fetch arbitrary hosts, accept user
credentials, forward client headers, or follow redirects. Query/fragment/userinfo
sources and ambiguous encoded paths are rejected. The existing IPX alias is
used by synthetic browser fixtures; this is server configuration, not a
client-controlled host override.

Only HTTP 200 JPEG/PNG/WebP/AVIF responses are processed. Source transfer is
limited to 10 MiB, decoding to 40 million pixels, and fetch to five seconds.
Errors are generic and non-cacheable. Source cookies are never sent to clients.

## Shared Cache

Only Nginx's explicit `/_ipx/cards/` location uses `catalog_images`. HTML, API,
prices, availability and commerce remain outside it. The disk cache has an
8 MiB key zone, 256 MiB configured maximum and one-day inactive eviction. Nginx's
cache manager enforces the disk maximum asynchronously, not as an instantaneous
disk quota. Budget disk headroom accordingly. This cache is disposable and is
not mounted as application/product storage.

Variant URLs are separate cache keys. Cold requests share an upstream request
via `proxy_cache_lock` (15-second lock wait/age; 10-second upstream read timeout).
Lock expiry can permit another request if processing exceeds those bounds; this
is not an unbounded/exactly-once guarantee.

Source max-age/s-maxage/Expires/Age are respected with a five-minute ceiling.
Absent freshness metadata defaults to five minutes. Private, no-store, no-cache,
Set-Cookie, any Vary, malformed or ambiguous directives disable caching. Nginx
also retains its native Cache-Control/Set-Cookie protections and independently
rejects non-200/non-WebP/Vary responses. Incoming headers and bodies are not
forwarded from this proxy location. Client Cache-Control forces a bypass and
prevents storing that response. No stale-error serving is enabled.

At expiry the next request fetches and processes the original again. Same-URL
updates can remain visible for the old response's remaining TTL (at most five
minutes). An error does not replace a good object with a cached error. Changing
the source URL creates a separate key. Security headers are explicitly repeated
because location-level `add_header` disables server-level inheritance.

## Validation

From `frontend`, with locked installed dependencies:

```powershell
cmd /c npm run test:unit:ci
cmd /c npm run build
node node_modules/playwright/cli.js test --config=playwright.config.ts --workers=1 --retries=0 --trace=on
```

Browser fixtures use local synthetic images, never supplier requests. The
category fixture holds detail until products arrives, proving concurrency
without performance thresholds. Desktop and mobile check SSR, actual responsive
WebP, ALT/links, fallback, pagination and category transitions.

The real Nginx proxy test does not download/install/start services. Supply an
existing binary and a new external output directory, then run:

```powershell
$env:NGINX_BINARY = '<absolute nginx executable>'
$env:NGINX_FASTCGI_PARAMS = '<absolute fastcgi_params file>'
$env:NGINX_TEST_OUTPUT = '<new external output directory>'
node test/nginx/card-cache.mjs
```

It renders the actual template, changing only local paths, ephemeral loopback
ports/upstreams and disabled fixture flags. It executes `nginx -t`, real proxy
MISS/HIT, cold coalescing, variant separation, expiry, recovery, privacy/MIME/
status exclusions, header isolation and graceful shutdown. The config, results
and Nginx logs remain in the external directory. On Linux, `NGINX_FASTCGI_PARAMS`
is typically `/etc/nginx/fastcgi_params`; it is not guessed by the test.
The harness also overrides all five Nginx temporary paths inside its own prefix,
so Linux distribution defaults cannot write to system temporary directories.

### Linux CI

The independent `frontend-card-cache` job in `.github/workflows/ci.yml` runs this
same test on Ubuntu 24.04 with Node 22 and `npm ci`. It downloads the Ubuntu
`nginx` and `nginx-common` packages and extracts them under `runner.temp`, without
installing a package, starting a system service, Docker or application servers.
Nginx runs as the ordinary runner user on ephemeral loopback ports. There are no
supplier requests, database fixtures, Nuxt builds or browser runs in this job.

The job supplies explicit `NGINX_BINARY`, `NGINX_FASTCGI_PARAMS` and a fresh
`NGINX_TEST_OUTPUT`, then executes `node test/nginx/card-cache.mjs`. A missing
binary, dependency, config failure or proxy assertion fails the job; there is
no skip, retry or continue-on-error. The job has a 15-minute outer limit.

The `frontend-card-cache-<run_id>-<attempt>` artifact is uploaded on success and
failure for seven days: provisioning/version output, stdout/stderr, rendered
config, `fastcgi_params`, `results.json` when produced, and Nginx logs. Cache
objects and installed dependencies are not uploaded. Runtime metadata and
`nginx -V` identify the actual tested versions; diagnostics can be partial if
setup fails or the runner is forcibly stopped.

This covers real Linux config/proxy behavior using Ubuntu's Nginx package, not
the production `nginx:1.27-alpine` container or its filesystem permissions.
Adding the job is not an executed Linux CI PASS. Local portable Windows results
and future CI results must remain separately reported.

## Deployment And Measurement

This change is not a deployment authorization. Follow [Deployment](DEPLOYMENT.md)
after independent review, CI, merge and separate owner approval. Build the Nuxt
image and regenerate the Nginx configuration from the changed template (normally
by recreating the Nginx container through the documented deployment process).
A graceful reload alone does not rerun template substitution. Validate the
rendered Linux/container config, writable disposable cache and security headers
before enabling traffic. No feature flags or catalog records need changing.

Measure category API and products API latency separately, SSR TTFB, cold versus
warm image processing, `X-Image-Cache`, image bytes, request counts, LCP and
resource use on desktop/mobile. External 8-10 second page observations do not
identify database latency. A local Windows proxy PASS does not establish VPS
network/CPU performance or Linux container permission/readiness PASS.
