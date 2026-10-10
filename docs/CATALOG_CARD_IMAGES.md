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
8 MiB key zone, 1 GiB configured maximum, 1 GiB minimum free space and seven-day
inactive eviction (`max_size=1g min_free=1g inactive=7d`). Nginx's
cache manager enforces the disk maximum asynchronously, not as an instantaneous
disk quota. Budget disk headroom accordingly. This cache is disposable and is
not mounted as application/product storage.

Variant URLs are separate cache keys. Cold requests share an upstream request
via `proxy_cache_lock` (15-second lock wait/age; 10-second upstream read timeout).
Lock expiry can permit another request if processing exceeds those bounds; this
is not an unbounded/exactly-once guarantee.

### Option A: Separate Browser And Shared Freshness

The owner approved local implementation of Option A and its freshness tradeoffs,
not deployment. Browser freshness is at most 300 seconds. Shared freshness is at
most 172800 seconds (48 hours), only for an explicitly `public` source with valid
max-age/s-maxage or Expires permission. Other sources retain the conservative
300-second ceiling. If both max-age and s-maxage exist, the smaller permission
wins; zero is not missing and cannot fall back to Expires. Missing freshness
defaults to 300 seconds, not 48 hours.

Source age is consumed before these ceilings: the greater of apparent Date age
and Age plus request/response delay, then body transfer/processing time. HTTP
dates must be valid canonical IMF-fixdate; invalid/future Date, invalid Expires,
invalid Age, duplicate/conflicting/unsupported directives and delta-seconds over
2147483647 fail closed. Private, no-store, no-cache, Set-Cookie, any Vary or
Pragma no-cache disable caching. This deliberately rejects more metadata than a
general-purpose HTTP cache. The fixed source request has `Cache-Control: no-cache`
to request upstream validation; client headers and credentials are never used.

For an eligible source with at least 48 hours remaining, the processed response
is `Cache-Control: public, max-age=300, s-maxage=172800, must-revalidate`.
A source with 120 seconds remaining instead gets max-age=120 and s-maxage=120;
elapsed work can reduce both. Uncacheable responses use `private, no-store` and
`X-Accel-Expires: 0`. No stale-while-revalidate or stale-if-error is emitted.

The response Date records completion of processing, with already-consumed
source age deducted from the TTL. `X-Accel-Expires: @<Unix seconds>` fixes the
absolute shared expiry to that Date plus remaining shared freshness. The proxy
passes this Date and absolute expiry through HITs. Browser freshness therefore
does not restart at a late HIT. A second Nginx cache also uses the same absolute
deadline rather than starting another s-maxage interval. Nginx 1.27.5's relative
Cache-Control timer does not itself subtract upstream Date/Age, and must not be
assumed to generate an increasing Age header. The real two-cache test checks
actual Date, Age, Cache-Control, bytes and expiry, not merely header presence.
See the [Nginx cache header precedence](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_cache_valid)
and [1.27.5 upstream implementation](https://github.com/nginx/nginx/blob/release-1.27.5/src/http/ngx_http_upstream.c).

Because X-Accel-Expires takes precedence over native caching metadata, explicit
proxy no-cache maps independently veto restrictive Cache-Control, Set-Cookie,
non-200, non-WebP and any Vary, even with a positive absolute expiry. Incoming
headers and bodies are not forwarded. Client Cache-Control forces BYPASS and
prevents storing that response. Security headers are explicitly repeated because
location-level `add_header` disables server-level inheritance.

Background update and stale serving are explicitly off. At full expiry the next
request synchronously fetches/processes the original. Timeout/error never serves
the expired image or stores an error. There is no source/validator store, warmer,
worker or scheduler. Seven-day retention is only eviction policy, not permission
to serve expired content. Cold/evicted images still incur download/processing.
Same-URL replacement or deletion can remain unseen for the old response's
remaining shared TTL (up to 48 hours); widths are not invalidated atomically.
Changing a URL creates a separate key, not an atomic purge of old variants.

### Namespace And Rollback

The cache key starts with `card-shared-ttl-v1|`, followed by scheme, upstream host
and the complete variant URI. Old five-minute entries are not reused. A future
authorized rollback must restore the previous 300-second policy AND select a
different fresh namespace, for example `card-short-ttl-rollback-v1|`. Do not
reuse either policy's populated namespace or assume a reload removes long-lived
objects. Old objects can be evicted normally. No rollout, purge or rollback is
performed by local tests; the accelerated rollback fixture uses its own prefix.

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
MISS/HIT, cold coalescing, variant separation, aged sparse HITs through two cache
layers, browser expiry before shared expiry, synchronous replacement, real
upstream timeout without stale serving, recovery, privacy/MIME/status exclusions,
HTTP/1.1 socket reuse, an independent warm HIT during a held MISS, restart and
fresh rollback namespace isolation. Incremental events, config, results and
Nginx logs remain in the external directory. On Linux, `NGINX_FASTCGI_PARAMS`
is typically `/etc/nginx/fastcgi_params`; it is not guessed by the test.
The harness also overrides all five Nginx temporary paths inside its own prefix,
so Linux distribution defaults cannot write to system temporary directories.
Failure injection and optional isolated Apache proxy-chain commands are in
[the harness README](../frontend/test/nginx/README.md). Missing Apache is reported
as NOT RUN, not an Apache PASS. Linux/Alpine/container results must also be
recorded separately; Windows is not production-platform parity. No suite uses
the live VPS as a fixture or changes the machine clock.

### Linux CI

The independent `frontend-card-cache` job in `.github/workflows/ci.yml` runs this
same test on Ubuntu 24.04 with Node 22 and `npm ci`. It downloads the Ubuntu
`nginx` and `nginx-common` packages and extracts them under `runner.temp`, without
installing Nginx or starting a system service, Docker or application servers.
It installs CI-only `apache2-bin` and dependencies (not the Apache service
package) and requires the isolated Apache chain; missing modules/binaries fail.
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

The Apache fixture uses `AllowEncodedSlashes NoDecode` and `ProxyPass ... nocanon`
for the exact card route. Strict synthetic paths prove that encoded source URLs
survive without decoding/re-encoding/slash changes. Damaged paths cannot succeed
merely because a generic fixture returns an image for every request. Late HITs,
headers/absolute expiry, image bytes, counts and connection reuse are asserted.

The separate `frontend-card-cache-alpine` job pins its disposable harness build
to the pulled digest of the project's `nginx:1.27-alpine`. It records base and
fixture image/container identities, Alpine/Node/Nginx/Sharp versions, UID/GID,
and tests writable cache/temp paths as the Nginx runtime user. It uses that
image's Nginx, not Ubuntu's or an apk replacement. This is not an actual frontend
application-container test or a live Apache configuration check. The same proxy
assertions must pass; unavailable expected binaries never become an implicit
skip. Local Windows, Linux Apache and Alpine results remain separate, and each
CI result must be tied to the tested commit. Artifacts survive success/failure.

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
