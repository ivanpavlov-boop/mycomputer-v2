# Abandoned Cart Recovery Capability Security

## Status

Commerce Recovery Phase A remediates `CART-021` locally. Formal closure still
requires PR review, CI, merge, deployment, migration verification, and a
controlled staging security check. Abandoned-Cart recovery and public commerce
remain disabled by default. `CART-025` remains open.

## Capability Contract

- A capability is 32 cryptographically secure random bytes encoded as
  unpadded Base64URL: exactly 43 characters matching
  `[A-Za-z0-9_-]{43}`.
- Only the lowercase 64-character SHA-256 hash is stored in
  `abandoned_cart_records.recovery_capability_hash`.
- Detection creates no capability. A reminder rotates the hash immediately
  before its provider call and keeps the raw value only in process memory.
- A definitive provider failure revokes the just-issued hash.
- Successful restoration clears the hash and expiry in the same transaction
  as the existing restored Cart audit.
- Suppressed, expired, restored, and recovered records retain no active
  capability.

The migration removes `recovery_token` and invalidates every legacy value. It
preserves abandoned-Cart records, snapshots, email history, status, and audit
data. Rollback refuses any lossy non-empty-table conversion.

## Transport Contract

Reminder links use:

```text
{frontend-origin}/cart/recover#<capability>
```

Fragments are not sent to Nginx, Nuxt SSR, or Laravel. On mount, the browser
reads `window.location.hash`, synchronously removes it with
`history.replaceState`, validates the exact format, and submits it only in the
JSON body of:

```text
POST /api/v1/cart/recover
```

The old `/api/v1/cart/recover/{value}` endpoint and old storefront token paths
remain unavailable. The clean page and API are no-store; the page is
`noindex`, `nofollow`, `noarchive`, and `no-referrer`, with a capability-free
canonical URL.

## Failure Contract

Missing, malformed, unknown, expired, suppressed, revoked, consumed,
already-restored, already-recovered, and ownership-invalid capabilities return
the same fail-closed public response:

```text
HTTP 404
code: cart_recovery_unavailable
message: Recovery link is unavailable.
```

The endpoint has a dedicated IP-only rate limiter. It remains behind both the
recovery feature flag and the existing open public-commerce authority.

## Leakage Boundaries

Capability-bearing HTML and template data may reach only the in-memory email
provider call. The sensitive email payload policy excludes the raw URL, HTML,
record serialization, session data, Cart snapshot, customer data, and hash
from `email_logs` and provider logs. Queue jobs and marketing events carry
record IDs only. The capability value object is not stringable, JSON
serializable, queueable, or Eloquent-backed and redacts debug output.

The browser does not place the capability in Pinia, Nuxt state/payload,
localStorage, sessionStorage, cookies, query parameters, route parameters,
DOM attributes, analytics, console output, canonical metadata, or subsequent
navigation.

## Runtime Gate

Committed defaults remain:

```text
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=false
ABANDONED_CART_RECOVERY_ENABLED=false
LEGAL_CONTENT_APPROVED=false
PAYMENT_CARD_ENABLED=false
PAYMENT_LEASING_ENABLED=false
```

Nginx exposes only exact `/cart/recover` when public commerce, legal approval,
and recovery are all explicitly enabled. Invalid values fail closed. The old
`/cart/recover/*` and all English recovery routes remain blocked.

## Scope Boundary

This phase does not activate recovery, email processing, a scheduler, public
commerce, card, or leasing. It does not redesign bundle, coupon, gift, or
promotion snapshot fidelity. `CART-025` remains open. It changes no Product,
Supplier, `supplier_products`, Catalog Sync, Checkout, payment, or legal
acceptance behavior.
