# Order-owned Payment Retry

## Status

Commerce Phase 1D.2B is merged, MySQL CI verified, deployed and staging
schema/security verified.

Card and leasing remain disabled by default:

```text
PAYMENT_CARD_ENABLED=false
PAYMENT_LEASING_ENABLED=false
```

Public Cart and checkout pages remain disabled by the deployment gate.

## API Boundary

Authenticated customers may use:

```text
POST /api/v1/account/orders/{order}/payment-attempts
```

The endpoint requires direct `order.user_id` ownership. Matching email, staff
roles, admin permissions and knowledge of an Order identifier do not authorize
the operation.

Guest checkout may use:

```text
POST /api/v1/checkout/payment-attempts
```

It resolves the Order only through the dedicated `mc_payment_retry` cookie.
The cookie contains a 43-character random capability; only its SHA-256 hash is
stored. It is host-only, HttpOnly, SameSite Lax, valid for 60 minutes and
scoped to the guest retry endpoint. It is never returned in JSON or a URL.

Both endpoints accept an empty body and require a 43-character Base64URL
`Idempotency-Key` generated from 32 random bytes. Only its SHA-256 hash and a
keyed request fingerprint are stored.

## Retry Policy

The Order's existing payment method is authoritative and cannot be switched.
Only an active, available, operational online method explicitly supported by
the policy may create a new attempt. The current production card provider
remains non-operational; controlled tests use the in-process fake provider.

- pending or authorized transaction: return the existing safe result;
- paid transaction: reject as already paid;
- refunded or missing transaction: reject;
- failed or cancelled transaction: allow one explicitly requested attempt;
- cash on delivery, bank transfer and leasing: unsupported for retry.

The Order and relevant attempt/transaction rows are locked before the policy
decision. A stable provider idempotency identity is derived in memory for the
logical attempt and is not stored, logged or returned. There is no automatic
retry, scheduled retry, polling, webhook redesign or ERP payment sync.

## Safe Result

Responses expose only the opaque attempt reference and safe payment
presentation:

```text
reference
status
replayed
payment.status
payment.amount
payment.currency
payment.method code/name
payment.redirect_url
payment.instructions
payment.presentation
```

Redirects require an absolute HTTPS URL on an exact configured host allowlist.
The default production allowlist is empty. Provider/customer identifiers,
database IDs, raw payloads, capability values and idempotency values are not
returned.

## Future Provider Boundary

The current transaction boundary invokes only an in-process test fake. A real
gateway phase must separately design network-call recovery, reconciliation,
credentials, provider-specific idempotency and webhook verification. This
local phase does not authorize a real card or leasing integration.

CART-008 is remediated, deployed and staging verified. CART-023 remains open.

Commerce Phase 1D.3 adds a read-only server-authoritative customer presentation
and explicit frontend actions. The presentation reuses this retry policy,
ownership service, capability service and redirect policy. It does not reveal
an endpoint, capability, idempotency key, payment database ID, provider
transaction ID, raw response or internal failure code.

The frontend never retries or redirects automatically. Ambiguous operations
retain the existing in-memory key; successful or definitive results clear it
through the established composable. Phase 1D.3 is complete locally only and
does not enable public commerce, card or leasing.
