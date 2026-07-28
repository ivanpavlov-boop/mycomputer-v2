# Manual Leasing Applications

## Status

Commerce Leasing Phase A is complete locally only. It has not been pushed,
merged, deployed or enabled in a shared environment.

The feature is disabled by default:

```text
PAYMENT_LEASING_ENABLED=false
```

An active `leasing` payment-method row cannot bypass this configuration gate.
Public Cart and checkout routes remain disabled by the Nginx release gate.

## Purpose

The module records a customer's request to be contacted about a purchase on
installments. It is a manual sales workflow, not a financing decision or an
integration with a financing provider.

When leasing is explicitly enabled and selected during checkout, the customer
may provide:

- requested term in months from the configured allowlist;
- requested down payment, not exceeding the trusted checkout total;
- preferred contact method and optional contact time;
- an optional plain-text note;
- explicit contact consent.

The existing checkout identity, ownership, pricing, stock, idempotency and
transaction boundaries remain authoritative. One Order may have at most one
leasing application, and an idempotent checkout replay returns the original
result without creating a duplicate application or activity.

## Data Minimization

The module deliberately does not collect:

- EGN or other national identifiers;
- identity-card or passport data;
- employment, income or employer information;
- bank-account or card data;
- financing documents;
- provider credentials, tokens or provider response payloads.

Customer and internal notes are plain text, length-limited and stripped of
markup. Staff are warned not to enter sensitive financial or identity data.

## Manual Workflow

Every application starts as `submitted`. Authorized Order-management staff may
review it in Filament under **Продажби > Лизингови заявки** and may:

- assign an active Super Admin or staff member with `manage orders`;
- move it through the allowlisted status transitions;
- add an internal plain-text note.

The supported statuses are:

```text
submitted
contact_pending
contacted
sent_to_partner
approved
rejected
customer_cancelled
expired
```

Application creation, assignment, status changes and notes are recorded in an
append-only activity history. The Filament resource has list and view pages
only: there is no create, edit, delete, restore, force-delete or bulk-delete
surface.

Viewer/Auditor and staff with `view orders` may view applications. Mutation
actions require Super Admin or `manage orders`.

## Notifications

After the checkout transaction commits, a queued listener sends:

- a customer acknowledgment email;
- an internal email to `LEASING_NOTIFICATION_EMAIL`;
- a Filament database notification to active Super Admin and Order-management
  staff.

The messages confirm receipt only. They do not claim approval, quote financing
terms or expose a provider result. Mail and queue failures occur after the
checkout commit and do not roll back a successful Order.

## Provider Boundary

`LeasingPaymentProvider` is intentionally local and non-networked. It creates
no provider transaction ID, no redirect, no approval decision and no external
request. Any future provider integration requires a separate reviewed phase
covering credentials, transport security, provider idempotency, webhook
verification, retries, data retention, customer disclosures and failure
recovery.

This phase adds no calculator, automatic eligibility decision, automatic
status transition, background approval, webhook or schedule.

## Operations

Before enabling in any environment:

1. Apply the two leasing migrations.
2. Configure and test the queue worker and mail transport.
3. Set `LEASING_NOTIFICATION_EMAIL` to the reviewed internal destination.
4. Review the payment-method text and active database state.
5. Explicitly set `PAYMENT_LEASING_ENABLED=true`.
6. Run the documented checkout, replay, notification and Filament smoke checks.

Turning the flag back to `false` removes leasing from public payment discovery
and rejects new leasing checkout submissions. Existing applications remain
available to authorized staff for manual follow-up and audit history.

## Safety Boundaries

This phase does not:

- enable card payments;
- add or restore public payment initiation;
- enable public Cart or checkout routing;
- close CART-008 or CART-023;
- change Product, stock, supplier import or Catalog Sync behavior;
- add Sync All, automatic sync or Catalog Sync UPDATE enablement;
- call a financing provider or download remote content.

CART-008 remains partially remediated. CART-023 remains open.
