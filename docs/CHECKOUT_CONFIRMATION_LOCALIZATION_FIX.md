# Checkout Confirmation Localization Fix

## Incident

A controlled staging cash-on-delivery checkout completed successfully. The
resulting Order, dedicated Customer snapshot and single Order Item were
verified, together with Checkout idempotency, the confirmation capability and
the recorded Bulgarian legal acceptance. Individual company fields were null,
the billing snapshot matched the shipping snapshot, and cash on delivery
created no payment attempt.

The confirmation page correctly showed the Order number, total, Bulgarian
payment-method name, masked email and cash-on-delivery action panel. Its compact
summary nevertheless rendered the internal values `pending` directly for both
Order and payment status. No Product or `supplier_products` row changed during
the controlled check.

Staging was returned to the `confirmation_only` release state. Public commerce
remains disabled. This document contains no customer details, capability data,
cookies, session identifiers or test credentials.

## Presentation Fix

The Nuxt confirmation page now maps the existing machine-readable Order status
to a deterministic Bulgarian customer label. Unknown values fail closed to
`Статусът се актуализира` and are never echoed to the customer.

The summary presents the payment method separately with known Bulgarian labels
for cash on delivery, bank transfer, card and leasing. An unknown method uses a
validated customer-facing API name when available, otherwise it fails closed to
`Избран начин на плащане`. It no longer renders a separate payment status or
duplicates the payment action panel.

The existing `PaymentActionPanel` remains authoritative for cash-on-delivery,
bank-transfer, card and leasing state, instructions, retry and continuation
actions. Internal Order, payment and shipping values and the Checkout
confirmation API contract are unchanged.

## Safety And Release State

- The change is merged, CI verified, deployed and staging verified.
- Final confirmation verification returned HTTP 200 with the required
  Bulgarian Order-status, payment-method and cash-on-delivery presentations.
- Raw `pending`, raw `cash_on_delivery` and duplicated payment status were
  absent.
- The temporary verification capability was deleted immediately after the
  successful final check.
- Final verification created no additional Order or Customer.
- The change remains frontend presentation and test/documentation only.
- No backend production code or migration is changed.
- No Order, Customer, Product, Supplier or `supplier_products` row is written.
- Public commerce, card, leasing and abandoned-Cart recovery committed defaults
  remain disabled.
- Catalog Sync behavior and flags are unchanged; UPDATE, Sync All and automatic
  sync remain disabled.
- Staging was returned to `confirmation_only`; public commerce remains
  disabled.
- `CART-023` is remediated after the complete controlled release verification.
- This closure does not authorize permanent public activation or require
  another staging/VPS deployment.
