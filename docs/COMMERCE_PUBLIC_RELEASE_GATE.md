# Controlled Public Commerce Release Gate

## Status

Commerce Phase 1E.1 is merged, MySQL CI verified, deployed with all release
flags false, and disabled-state staging verified. It adds a reversible,
fail-closed release mechanism; public commerce remains disabled.

Committed defaults:

```text
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=false
ABANDONED_CART_RECOVERY_ENABLED=false
```

Card and leasing remain disabled. English commerce, customer account/auth,
wishlist, compare, B2B quote, and abandoned-Cart recovery remain outside the
approved launch surface.

## Release States

| State | Public | Confirmation | `/cart` | `/checkout` | `/checkout/success` | `POST /api/v1/checkout` |
| --- | --- | --- | --- | --- | --- | --- |
| `closed` | false | false | 404 | 404 | 404 | generic 404 |
| `confirmation_only` | false | true | 404 | 404 | available | generic 404 |
| `open` | true | true | available | available | available | existing checkout contract |
| `invalid` | true | false or invalid values | 404 | 404 | 404 | generic 404 |

An invalid state always fails closed. The checkout API response does not reveal
configuration, Cart, validation, payment, or Order details and is marked
`Cache-Control: no-store, private`.

Confirmation-only is the emergency rollback state. It stops new checkout
creation while preserving the existing capability-protected confirmation API,
guest payment retry, and direct-owner account payment retry.

## Route Ownership

Nginx renders
`deploy/nginx/mycomputer.conf.template` through the official image template
processor. `NGINX_ENVSUBST_FILTER` permits substitution of only the two public
commerce flags, preserving Nginx variables such as `$host`, `$uri`,
`$query_string`, `$remote_addr`, `$scheme`, and `$realpath_root`.

Only exact Bulgarian `/cart`, `/checkout`, and `/checkout/success` locations
can open. Trailing slashes redirect with 308. Broad Cart/checkout subpaths,
`/cart/recover/*`, English commerce, account/auth, wishlist, and compare remain
blocked. Nuxt applies the same four-state rules as defence in depth and does
not persist or send the release state to analytics.

## Storefront Entry Points

Cart navigation and Cart/add-to-Cart actions render only in the `open` state.
Mutation handlers re-check the gate before calling the Cart store. The guest
quote action is hidden because customer login is not part of this release.
Product prices, availability, catalog, categories, and Product detail remain
visible in every state.

Cart and checkout pages are `noindex, nofollow, noarchive`.
Checkout success additionally uses `no-referrer`. The edge applies
`no-store, private` to exact commerce pages without removing existing security
headers.

## Recovery And Shipping

Abandoned-Cart recovery remains default-disabled. Scheduled commands are not
registered while disabled; direct command, job, and service invocation performs
no recovery write or email. Existing records and historical tokens are not
changed. CART-021 and CART-025 remain open.

Shipping calculation already resolves Cart authority through
`CartContextResolver`. Regression coverage confirms guest and authenticated
ownership, rejects foreign or mismatched Cart identity, and preserves the
explicit safe no-Cart calculation contract. CART-022 is remediated locally,
not deployed or staging verified by this phase.

## Read-only Preflight

Run:

```bash
php artisan commerce:release-preflight --json
```

The command performs no writes and checks configuration validity, required API
routes, COD and bank transfer availability, card/leasing disabled state,
shipping readiness, an active Super Admin, Catalog Sync safety flags,
abandoned-Cart recovery disabled state, and the repository-controlled legal
manifest/source contract.

The Bulgarian Terms and Privacy routes now exist as review-ready drafts.
Activation remains blocked by `legal_content_approved` and
`legal_effective_dates_present`. Draft source files are not evidence of legal
approval. See
[Public Legal Pages and Approval Gate](COMMERCE_PUBLIC_LEGAL_GATE.md).

## Future Deployment

Do not execute these steps from a feature branch.

1. Merge with green CI.
2. Deploy first with all three flags false.
3. Force-recreate `app`, `queue`, `scheduler`, `frontend`, and `nginx`.
4. Verify the closed route matrix and generic checkout 404.
5. Resolve all preflight blockers and obtain explicit human activation approval.
6. Set public and confirmation flags true; keep recovery false.
7. Force-recreate the same services and run the approved smoke matrix.

## Emergency Rollback

Set:

```text
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=true
ABANDONED_CART_RECOVERY_ENABLED=false
```

Force-recreate `app`, `queue`, `scheduler`, `frontend`, and `nginx`. New Cart
and checkout pages and new checkout API submissions close; existing
confirmation and authorized payment-retry capabilities remain available. No
database rollback is required.

## Safety

The gate changes no Cart ownership, idempotency, Customer snapshot, Order
snapshot, stock, promotion, shipment, payment, leasing, or Catalog Sync
contract. It adds no migration, dependency, real provider, secret, Product or
Supplier mutation, Sync All, automatic sync, or Catalog Sync UPDATE enablement.
