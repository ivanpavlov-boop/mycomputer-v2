# Public Commerce Legal Approval Gate

Commerce Phase 1E.2A is merged, CI verified, deployed and staging verified in
the closed release state. Legal Content Finalization and Explicit Approval is
complete locally only. It finalizes the Bulgarian legal documents and records
project-owner approval of their exact source bytes; it does not activate legal
approval or public commerce at runtime.

## Committed Legal Content

The manifest at
`frontend/app/data/legal/legal-content-manifest.json` is structurally
`approved` and records:

- Terms version `bg-terms-v1.0-2026-07-30`, effective `2026-07-30`;
- Privacy version `bg-privacy-v1.0-2026-07-30`, effective `2026-07-30`;
- lowercase SHA-256 hashes of the exact Bulgarian source files;
- approval role `project_owner`, approval date `2026-07-30`;
- `legal_counsel_review=not_claimed`.

The matching machine-readable evidence is
`docs/legal/LEGAL_CONTENT_APPROVAL_2026-07-30.json`. This is project-owner
approval, not lawyer certification, regulatory approval or operational
activation.

Approved pages are public, indexable and available independently of the
commerce state:

- `/obshti-usloviya`
- `/politika-za-poveritelnost`

English legal routes remain unavailable.

## Integrity And Runtime Separation

`LegalContentRegistry::isManifestValid()` fails closed unless the manifest,
strict ISO dates, exact routes, source files, source hashes, approval metadata
and approval audit record all agree. Editing a source file, version or date
without updating the reviewed evidence invalidates the manifest.

Operational approval remains separate:

```text
LEGAL_CONTENT_APPROVED=false
PUBLIC_COMMERCE_ENABLED=false
PUBLIC_COMMERCE_CONFIRMATION_ENABLED=false
ABANDONED_CART_RECOVERY_ENABLED=false
PAYMENT_CARD_ENABLED=false
PAYMENT_LEASING_ENABLED=false
```

`LegalContentRegistry::isApproved()` requires both a valid approved manifest
and `LEGAL_CONTENT_APPROVED=true`. Committed defaults therefore leave the
preflight in `closed` state with only `legal_content_approved` blocked when
other operational checks pass.

## Consent Audit

Every genuinely new canonical Order stores, inside the checkout transaction:

- `legal_accepted_at`;
- `terms_version`;
- `privacy_version`;
- `legal_acceptance_locale`.

All values are server-controlled. The frontend sends only the required `terms`
acceptance boolean. Client-supplied versions, timestamp or locale are
prohibited. Replay reuses the original Order and acceptance values. Rollback
removes the uncommitted Order and its acceptance data. Historical Orders remain
nullable and readable.

The final checkout action is labelled
`Поръчка със задължение за плащане`; its submission behavior is unchanged.
The Filament Order form keeps legal acceptance read-only.

## Activation Boundary

A later explicit operational step may set `LEGAL_CONTENT_APPROVED=true` only
after merge, deployment and review of the committed hashes. Public commerce
still requires a separate decision to enable both public-commerce flags.

CART-023, CART-021 and CART-025 remain open. This phase adds no migration and
changes no Product, Supplier, `supplier_products`, Catalog Sync, payment,
shipping or recovery behavior.
