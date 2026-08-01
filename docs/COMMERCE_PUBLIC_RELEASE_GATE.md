# Controlled Public Commerce Release Gate

## Status

Commerce Phase 1E.1 is merged, MySQL CI verified, deployed and verified in the
closed and controlled open states. One controlled cash-on-delivery Checkout
passed and staging was safely returned to `confirmation_only`. The reversible,
fail-closed release mechanism is verified; public commerce remains disabled.

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
can open. Exact `/cart/recover` additionally requires the recovery flag; its
default remains false. Trailing slashes redirect with 308. Broad
Cart/checkout subpaths, old `/cart/recover/*` token paths, English commerce,
account/auth, wishlist, and compare remain blocked. Nuxt applies the same
fail-closed rules as defence in depth and does not persist or send the release
state to analytics.

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
no recovery write or email. Commerce Recovery Phase A removes plaintext
capability storage locally, invalidates legacy values, uses hash-only one-time
capabilities and fragment-only links, and prevents sensitive EmailLog/provider
logging. The clean page and body-only API remain behind both the recovery flag
and open public-commerce authority. `CART-021` remains formally open with local
remediation pending PR, CI, merge, deployment, migration verification and
controlled staging security verification. `CART-025` remains open. See
[Abandoned Cart Recovery Capability Security](ABANDONED_CART_RECOVERY_CAPABILITY_SECURITY.md).

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

The Bulgarian Terms and Privacy routes contain project-owner-approved,
hash-bound content with effective dates. Runtime legal approval passed for the
controlled staging verification and the preflight returned ready with no
blockers. Committed approval defaults remain false; lawyer or regulatory
approval is not claimed. See
[Public Legal Pages and Approval Gate](COMMERCE_PUBLIC_LEGAL_GATE.md).

## Controlled Verification Closure

The controlled `open` route matrix returned HTTP 200 for `/cart`, `/checkout`
and `/checkout/success`. One canonical cash-on-delivery Order, one dedicated
Customer snapshot and one Order Item were verified with idempotency, billing
normalization and legal acceptance. COD created no payment attempt. The
localized confirmation passed the final HTTP 200 checks, and the temporary
verification capability was deleted without creating another Order or
Customer.

Staging was then returned to `confirmation_only`: `/cart` and `/checkout`
return 404 while `/checkout/success` returns 200. `CART-023` is remediated.
This is controlled technical verification, not permanent public activation.
See [CART-023 Closure](CART_023_CLOSURE.md).

## Future Permanent Launch

Do not execute these steps from a feature branch.

1. Obtain a separate explicit permanent-launch decision.
2. Confirm the legal manifest, runtime approval and blocker-free preflight.
3. Re-run the closed and `confirmation_only` matrices before activation.
4. Set public and confirmation flags true only for the approved environment;
   keep recovery false.
5. Force-recreate `app`, `queue`, `scheduler`, `frontend`, and `nginx`.
6. Run the approved route, Checkout and monitoring smoke matrix.
7. Use `confirmation_only` immediately if any launch check fails.

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
