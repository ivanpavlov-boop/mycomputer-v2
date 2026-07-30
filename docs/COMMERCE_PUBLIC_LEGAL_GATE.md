# Public Commerce Legal Approval Gate

Commerce Phase 1E.2A adds a review-ready Bulgarian legal-content foundation and
an auditable Order consent record. It does not approve legal content or activate
public commerce.

## Current State

Committed defaults remain:

```text
LEGAL_CONTENT_APPROVED=false
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=false
ABANDONED_CART_RECOVERY_ENABLED=false
PAYMENT_CARD_ENABLED=false
PAYMENT_LEASING_ENABLED=false
```

The legal manifest is
`frontend/app/data/legal/legal-content-manifest.json`. Its committed status is
`draft`, both versions are `draft-1`, and both effective dates are null. Draft
pages show a legal-review banner and use `noindex, nofollow, noarchive`.

## Public Routes

Nginx sends only these exact Bulgarian legal routes to Nuxt in every commerce
state:

- `/obshti-usloviya`
- `/politika-za-poveritelnost`

Trailing slashes redirect with HTTP 308. English legal routes remain blocked.
The footer and checkout consent use these exact routes.

## Approval Authority

`LegalContentRegistry` reads the repository-controlled manifest. Approval
requires all of:

- `LEGAL_CONTENT_APPROVED=true`;
- manifest status `approved`;
- exact Bulgarian routes;
- non-empty Terms and Privacy versions;
- non-empty Terms and Privacy effective dates.

Missing, malformed or incomplete content fails closed. Nuxt and Nginx also
require the derived non-secret approval flag before exposing Cart or checkout.
Confirmation-only rollback remains available for already-created Orders when
its existing release flag is enabled.

## Consent Audit

Every genuinely new canonical Order stores, inside the checkout transaction:

- `legal_accepted_at`;
- `terms_version`;
- `privacy_version`;
- `legal_acceptance_locale`.

All values are server-controlled. The frontend sends only the existing
`terms` acceptance boolean. Client-supplied versions, timestamp or locale are
prohibited. Replay reuses the original Order and acceptance values. Rollback
removes the uncommitted Order and its acceptance data. Historical Orders remain
nullable and readable.

The Filament Order form shows these values in a read-only section. There is no
edit or bulk action for legal acceptance.

## Activation Boundary

A later reviewed PR must replace the draft content, set final versions and
effective dates, set manifest status to `approved`, and receive explicit legal
approval before `LEGAL_CONTENT_APPROVED=true` may be configured.

This phase does not close CART-023 and does not remediate CART-021 or CART-025.
It changes no Product, Supplier, `supplier_products`, Catalog Sync, payment
provider or recovery behavior.
