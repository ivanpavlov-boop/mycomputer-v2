# CART-023 Controlled Commerce Verification Closure

## Summary

`CART-023` is remediated after the strict public-commerce release gate was
merged, CI verified, deployed and verified through a controlled staging
activation. The enabled-state checkout path was tested once with cash on
delivery and staging was then returned to the safe `confirmation_only` state.
This closure records technical verification; it does not authorize a permanent
public launch.

## Closure Criteria

| Criterion | Result |
| --- | --- |
| Bulgarian legal content approved at runtime | Verified |
| Release preflight reported `ready_for_activation: true` and no blockers | Verified |
| Controlled open routes returned 200 for `/cart`, `/checkout` and `/checkout/success` | Verified |
| One real controlled cash-on-delivery Checkout completed | Verified |
| Exactly one canonical Order was created | Verified |
| One dedicated Customer snapshot and one Order Item were created | Verified |
| Checkout idempotency and confirmation capability were audited | Verified |
| Individual billing normalization matched billing to shipping and kept company fields null | Verified |
| Legal acceptance timestamp, versions and Bulgarian locale were recorded | Verified |
| Cash on delivery created no payment attempt | Verified |
| Customer-facing confirmation localization passed over HTTP 200 | Verified |
| Temporary verification capability was deleted | Verified |
| Staging rollback to `confirmation_only` passed | Verified |

## Controlled Order Audit

The controlled checkout produced one canonical Order, one dedicated Customer
snapshot and one Order Item. It used cash on delivery, created no payment
attempt, and retained one internal payment transaction record under the
existing Checkout contract. The idempotency record was present, and a
confirmation capability was present only for the original confirmation flow.

The checkout was an individual purchase: company name and VAT number were
null, the billing and shipping snapshots matched, and the legal acceptance
timestamp, Terms version, Privacy version and Bulgarian locale were recorded.
No customer identity, contact, address, Order identifier, capability value,
token hash, cookie, session identifier, idempotency value or credential is
recorded in this document.

## Confirmation Verification

The customer-facing confirmation page showed the Bulgarian presentations:

- `Статус на поръчката: Очаква обработка`;
- `Начин на плащане: Наложен платеж`;
- `Плащане при доставка`;
- the Bulgarian explanation that payment is due when the Order is received.

The page did not expose raw `pending`, raw `cash_on_delivery` or a duplicated
payment status. The final verification returned HTTP 200, all required and
forbidden-text checks passed, and the temporary verification capability was
deleted immediately afterwards.

## Final Runtime State

The final verified staging state is:

```text
state: confirmation_only
ready_for_activation: true
blockers: []

/cart: 404
/checkout: 404
/checkout/success: 200

LEGAL_CONTENT_APPROVED=true
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=true
```

These are staging runtime values after safe rollback, not changes to committed
defaults. New public Cart and checkout entry remains disabled; capability-
protected confirmation remains available.

## Safety

- Final confirmation verification created no additional Order or Customer and
  modified no Customer.
- No Product, Supplier or `supplier_products` row was modified.
- Catalog Sync behavior was unchanged: CREATE remains enabled while UPDATE,
  Sync All and automatic sync remain disabled.
- Card, leasing and abandoned-Cart recovery remain disabled.
- Active Super Admin safety remained intact.
- This documentation change performs no database, runtime, infrastructure or
  application change.

## Closure Decision

`CART-023` is remediated because the fail-closed release gate and controlled
enabled-state Checkout path were successfully verified and safely rolled back.
This is not permanent public-commerce activation. A future public launch still
requires a separate explicit release decision. This documentation change
requires no VPS deployment.
