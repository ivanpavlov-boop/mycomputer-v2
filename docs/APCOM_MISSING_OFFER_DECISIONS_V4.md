# APCOM Missing Offer Decisions V4

## Status And Authority

`apcom-missing-offer-decisions-v4` is the current documentation-only
decision-closure register for Phase 9C.6.5C.3D. It supersedes V3 only as the current
decision reference. [V3](APCOM_MISSING_OFFER_DECISIONS_V3.md) remains unchanged
as historical evidence, and all previously confirmed V3 decisions remain in
force.

This document records approved policy semantics. It does not add or change a
runtime decision registry, feed profile, supplier import, persistence, Catalog
Sync behavior, schedule, storefront behavior, or executable lifecycle action.

## V4 Delta

V4 closes only these four owner-approved decisions:

- `APCOM-SOURCE-ONLY-001`;
- `APCOM-MPN-001`;
- `APCOM-ZERO-PRICE-001`; and
- `APCOM-SNAPSHOT-FRESHNESS-001`.

No unrelated decision is changed. Cart maximum quantity remains outside this
phase. The APCOM-specific rules in this register must not become defaults for
another supplier.

## Preserved Confirmed Decisions

`APCOM-STAGING-ONLY-001` remains confirmed and unchanged: one source absence is
not EOL. Three qualified consecutive missing snapshots plus 48 hours can make
only the APCOM offer future-deactivation eligible. Any operational preview must
consume an immutable, versioned evidence bundle supplied at runtime and must
not infer that history from `received_at` or `last_seen_at`.

`APCOM-LINKED-STAGING-ONLY-001` remains confirmed: linked Products remain
linked, automatic unlink is prohibited, and catalog availability depends on
all fresh and valid supplier offers.

`APCOM-MISSING-OFFER-REAPPEARANCE-001` remains confirmed for preview: a valid,
exact-SKU, qualified reappearance resets missing tracking and may become future
reactivation eligible. Identifier conflicts remain blocked. Reappearance does
not itself authorize a write or reactivation.

## Approved Decisions

### `APCOM-SOURCE-ONLY-001` - Approved

- An item that exists only in the APCOM source is classified as
  `potential_create`.
- It is visible only as a candidate in preview.
- It must not be automatically created, linked, or synchronized into the
  catalog.
- Any future creation must be a manually selected and explicitly confirmed
  CREATE action.
- Actual CREATE execution requires a separate implementation phase.
- At execution time, the backend must re-check eligibility, permissions, and
  `CATALOG_SYNC_CREATE_ENABLED`.
- This decision does not authorize CREATE implementation or execution, Sync
  All, automatic sync, UPDATE, or supplier content overwrite.

### `APCOM-MPN-001` - Approved

- APCOM `partno` is supplier SKU only.
- APCOM `partno` must not automatically be treated as manufacturer MPN.
- MPN remains empty when no separate reliable manufacturer-provided field is
  available.
- MPN must not be inferred from EAN, Product name, description, or other
  heuristic data.
- A missing or inferred MPN must not trigger automatic matching, linking,
  CREATE, UPDATE, or deduplication.
- Conflicts are classified as `manual_review`.
- Future MPN enrichment requires a separate controlled phase.

### `APCOM-ZERO-PRICE-001` - Approved

- APCOM `fd_price = 0` does not mean that the Product is free.
- The APCOM offer is classified as `manual_review`.
- It must not set a catalog or selling price to zero.
- It is excluded from valid commercial-offer selection and price calculations.
- It must not authorize CREATE, UPDATE, publication, or reactivation.
- It is not a missing offer and must not increment the missing counter.
- It must not trigger delete, unlink, archival, deactivation, hiding, or another
  Product visibility mutation.
- When another fresh and valid supplier offer with a positive price exists, the
  Product continues to depend on that valid offer.
- A later valid positive APCOM price may restore preview eligibility only. Any
  actual reactivation requires a separate explicitly approved implementation
  and controlled execution.

### `APCOM-SNAPSHOT-FRESHNESS-001` - Approved

- The latest successful, complete, and validated APCOM stock/price snapshot is
  fresh for up to and including 24 hours (`age <= 24 hours`).
- After 24 hours (`age > 24 hours`), its stock and `fd_price` are stale.
- A stale APCOM offer is excluded from valid sellable-offer selection and price
  calculations.
- Staleness is not a missing offer and must not increment the missing counter.
- Staleness must not trigger deactivation, archival, unlink, delete, hiding, or
  another Product mutation.
- When another fresh and valid supplier offer exists, the Product continues to
  depend on that offer.
- Freshness must use explicit `evaluated_at` and the authoritative
  evidence-snapshot timestamp. `received_at` and `last_seen_at` are not substitutes for
  that timestamp.
- A later fresh snapshot restores eligibility only in preview.
- The 24-hour threshold is APCOM-specific and must not silently become a
  universal supplier default.
- This decision does not authorize a schedule, import, persistence, database
  write, or Catalog Sync execution.

## Missing-State Separation

A source-absent qualified offer is evaluated by the preserved missing-offer
policy. A present zero-price offer and a stale offer are separate non-sellable
states: neither counts as missing, increments the missing counter, nor starts a
Product archival clock. None of these states authorizes automatic catalog
mutation.

## Approval Gate And Execution

Documentation review and merge are prerequisites only. Merging this document
does not authorize implementation. The implementation gate remains
`blocked_pending_implementation_approvals` after documentation merge, and a
separate explicit implementation request is required before any runtime work.

No supplier import, profile persistence, supplier-offer lifecycle write,
Product visibility write, schedule, Catalog Sync execution, retention cleanup,
storefront visibility, sitemap, or noindex implementation is authorized. No
database or queue mutation is authorized. There is no execution authorization
for CREATE, UPDATE, Sync All, automatic sync, linking, unlinking, publication,
reactivation, deactivation, archival, hiding, deletion, or content overwrite.

## Related Contracts

- [Supplier Offer Missing Lifecycle Policy](SUPPLIER_OFFER_MISSING_LIFECYCLE_POLICY.md)
- [Catalog Product Visibility And Archival Policy](CATALOG_PRODUCT_VISIBILITY_ARCHIVAL_POLICY.md)
- [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md)
- [Supplier Onboarding Framework](SUPPLIER_ONBOARDING_FRAMEWORK.md)
