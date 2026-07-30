# Cart and Checkout SSR API Base Fix

## Status

Complete locally only. The fix has not been pushed, merged, deployed or
verified on staging. Public commerce remains disabled and CART-023 remains
open.

The current public staging state is `confirmation_only`:

```text
LEGAL_CONTENT_APPROVED=true
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=true
```

## Incident

A controlled staging activation reached the valid `open` release state, but
the smoke test returned:

```text
GET /cart              500
GET /checkout          500
GET /checkout/success  200
```

The Checkout API separately returned the expected HTTP 422 for an empty
payload. It created no Order, Customer or payment attempt. Staging was
immediately returned to the previously verified `confirmation_only` state.

An isolated diagnostic frontend container confirmed that both Cart and
Checkout render when server-side Cart requests use the internal API path. The
diagnostic container was removed.

## Root Cause

`/cart` and `/checkout` call `cart.sync()` during SSR. The Cart store delegates
to `useCartApi()`, which used `config.public.apiBaseUrl` for both server and
browser requests. In production that public value is `/api/v1`, which is
correct in the browser but is not an absolute URL reachable by the Nuxt server
process.

The deployment already provides a separate private runtime value through
`config.apiServerBaseUrl`. No Docker, Nginx or backend workaround is required.

## Fix

Cart API requests now select:

```text
SSR      -> config.apiServerBaseUrl
Browser  -> config.public.apiBaseUrl
Fallback -> public base only when the private server value is absent
```

The public runtime API base remains `/api/v1`. The private API URL is not added
to public runtime configuration, rendered HTML, browser bundles, logs,
analytics or error messages.

Cart identity headers, the SSR-safe Cart cookie, invalid-session GET retry,
required session-response validation, mutation sequencing and checkout
idempotency remain unchanged.

## Release Boundary

The four release states remain unchanged:

| State | `/cart` | `/checkout` | `/checkout/success` |
| --- | --- | --- | --- |
| `closed` | 404 | 404 | 404 |
| `confirmation_only` | 404 | 404 | 200 |
| `open` | 200 | 200 | 200 |
| `invalid` | 404 | 404 | 404 |

Local tests do not authorize activation. Public commerce must remain disabled
until this fix is merged, deployed from `main`, and a separately approved
staging activation repeats the full release preflight and smoke matrix.
