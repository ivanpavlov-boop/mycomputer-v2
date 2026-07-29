# Commerce Checkout and Payment Acceptance

## Status

Commerce Phase 1D.3 is merged, MySQL CI verified, deployed and staging
implementation/privacy/release-gate verified.

Commerce Phase 1D.4 Checkout Customer Snapshot and Profile Ownership Safety is
merged, MySQL CI verified, deployed and staging verified. CART-020 is
remediated.

Commerce Phase 1E.1 Controlled Public Commerce Enablement is complete locally
only. It adds a strict, default-disabled release gate and does not activate
public commerce. CART-023 remains open with
`local_remediation_status: remediated_locally`.

The acceptance layer reuses the existing checkout, confirmation, payment,
retry, ownership, capability, redirect and leasing services. It adds a
server-authoritative customer presentation and deterministic evidence; it does
not create another checkout or retry path.

## Launch Position

| Method | Acceptance position | Public staging position |
| --- | --- | --- |
| Cash on delivery | Accepted | Available when public commerce is later approved |
| Bank transfer | Accepted | Available when public commerce is later approved |
| Manual leasing | Accepted in controlled tests | Disabled |
| Card architecture | Accepted with the in-process fake provider | Disabled; no real provider |

`PAYMENT_CARD_ENABLED=false` and `PAYMENT_LEASING_ENABLED=false` remain the
defaults. The committed public-commerce, confirmation and abandoned-recovery
flags are also false. Public `/cart`, `/checkout`, `/checkout/success`, and new
checkout creation therefore remain disabled by default. CART-023 remains open
until merge, CI, disabled-state staging verification, explicit activation
approval and enabled-state staging verification.

## Customer Snapshot Ownership

The approved commerce ownership semantics are:

- `User` is authenticated account identity.
- `Customer` is a checkout contact snapshot associated with one canonical
  Order.
- Order customer and address fields are the immutable commercial snapshot.

Each genuinely new canonical Order creates one dedicated Customer snapshot
inside the existing checkout transaction. Checkout never searches for or
updates an existing Customer by email, phone, VAT number, company or address.
Authenticated checkout does not update the User, UserProfile, saved addresses,
roles, password or sessions. Same-key and different-key equivalent replays
reuse the original Order and `customer_id`; rollback removes an uncommitted
snapshot.

CART-020 is remediated, merged, MySQL CI verified, deployed and staging
verified. Public commerce, card and leasing remain disabled.

## Controlled Release Gate

The Phase 1E.1 release matrix is documented in
[`COMMERCE_PUBLIC_RELEASE_GATE.md`](COMMERCE_PUBLIC_RELEASE_GATE.md). Nginx,
Nuxt and `POST /api/v1/checkout` share strict `closed`,
`confirmation_only`, `open` and `invalid` semantics. Invalid configuration
fails closed.

Confirmation-only rollback closes new Cart and checkout entry and new Order
creation while preserving the existing capability-protected confirmation API
and authorized guest/account payment retry. English commerce, account/auth,
wishlist, compare and abandoned-Cart recovery remain blocked.

The read-only `commerce:release-preflight --json` command currently reports
missing real Terms and Privacy routes as launch blockers. No placeholder legal
content is introduced.

## Customer Presentation

`PaymentActionPresentationService` derives one safe presentation from the
trusted Order, latest transaction and attempt, method/provider availability,
retry policy, redirect policy and request ownership context. Its only action
types are `none`, `continue_payment`, and `retry_payment`.

The response contains Bulgarian status text, a safe message, optional approved
instructions, an optional allowlisted HTTPS redirect and the currency. It
contains no capability, idempotency key, database payment ID, provider
transaction ID, raw provider response, internal failure code or customer PII.

Checkout confirmation uses guest context. Account detail uses direct
`order.user_id` ownership; email-only fallback visibility never grants a retry
action. There is no Nuxt account Order detail page in the current repository,
so this phase adds no new public account route.

## Browser Actions

The checkout success page uses one shared payment action panel:

- offline and terminal states show information only;
- approved online continuation is an explicit external HTTPS link;
- retry is an explicit button using the existing guest/account composable;
- duplicate clicks are blocked while a request is pending;
- ambiguous failures retain the in-memory key;
- successful or definitive responses clear it through the existing composable;
- no timer, polling, automatic retry or automatic redirect exists;
- raw redirect URLs are never visible;
- purchase analytics remains tied to the confirmed Order, not payment actions.

## Acceptance Evidence

The machine-readable matrix is
[`COMMERCE_CHECKOUT_PAYMENT_ACCEPTANCE.json`](COMMERCE_CHECKOUT_PAYMENT_ACCEPTANCE.json).
It covers CHECKOUT, PAYMENT-PRESENTATION, PAYMENT-RETRY, OWNERSHIP,
CAPABILITIES, CONCURRENCY, ROLLBACK, CUSTOMER-SNAPSHOT, NOTIFICATIONS, BROWSER
and RELEASE-GATE.

Existing dedicated MySQL process-concurrency suites remain authoritative for
same-key/different-key checkout and payment-attempt races, including one
Customer snapshot per canonical Order. The acceptance tests compose those
established contracts rather than copying their fork and barrier machinery.

Commerce Phase 1D.2B and 1D.4 are merged, MySQL CI verified, deployed and
staging verified. CART-008 and CART-020 are remediated. Phase 1E.1 does not
change their checkout contracts or activate public commerce.
