# Checkout Individual And Company Billing Fix

## Incident

The first controlled staging Order attempt exposed a Checkout billing defect:
the separate billing-address field remained visible and required for an
individual customer even when company invoicing was not selected. No Order was
submitted during that attempt.

Staging was returned to the verified `confirmation_only` state:

```text
LEGAL_CONTENT_APPROVED=true
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=true
```

This is an incident record, not a committed runtime activation. Committed
defaults remain disabled.

## Corrected Contract

The storefront defaults to individual Checkout and sends an explicit
`is_company=false`. Company name, VAT identifier and a separate billing address
are hidden and cleared. The individual Order and dedicated Customer billing
snapshots use the same validated shipping-address representation already
submitted for address or office delivery.

Selecting `Желая фактура на фирма` sends `is_company=true` and reveals:

- `Име на фирма`, required;
- `ЕИК / ДДС номер`, nullable under the existing contract; and
- `Адрес за фактуриране`, required.

The backend is the final authority. Individual requests exclude stale company
fields and derive `billing_address` from `shipping_address`; company requests
retain their explicitly submitted billing data. A legacy caller that omits
`is_company` fails safe to individual mode and cannot persist stale company
values.

Changing billing mode clears the in-memory Checkout idempotency key. Checkout
replay, stock handling, legal acceptance, dedicated Customer snapshots,
shipping, payment selection and confirmation capability remain unchanged.

## Release Safety

The route matrix remains:

| State | `/cart` | `/checkout` | `/checkout/success` |
| --- | ---: | ---: | ---: |
| closed | 404 | 404 | 404 |
| confirmation only | 404 | 404 | 200 |
| open test fixture | 200 | 200 | 200 |
| invalid | fail closed | fail closed | fail closed |

At local completion, public commerce remained disabled pending merge,
deployment and explicit staging verification; the local change alone did not
claim a successful real Order, staging verification, public launch or closure
of `CART-023`. It was subsequently merged, CI verified, deployed and verified
through the controlled COD Checkout. The billing correction contributed to
CART-023 remediation, and staging was returned to `confirmation_only` without
authorizing a public launch.

No migration, Product mutation, Supplier mutation, `supplier_products`
mutation, Catalog Sync behavior change, Sync All, automatic sync, card
activation, leasing activation or abandoned-Cart recovery activation is
included.
