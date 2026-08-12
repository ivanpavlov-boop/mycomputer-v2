# Supplier Offer Missing Lifecycle Policy

## Purpose

`supplier-offer-missing-policy-v1` defines a supplier-neutral, future planning
contract for deciding when an individual supplier offer may become eligible for
a later deactivation workflow. It is preview-only in Phase 9C.6.5C.3D. It does
not change `supplier_products`, `product_supplier_offers`, products, links, or
catalog visibility. The operational evidence and semantic contract is defined
for review, but no operational preview or persistence is implemented or
authorized.

## Operational Evidence Bundle

The first operational preview must be input-driven. It accepts an immutable,
versioned snapshot evidence bundle at preview runtime and must not reconstruct
historical evidence from `received_at` or `last_seen_at` alone. The bundle must
identify its schema and policy versions, supplier and comparable supplier scope,
ordered capture times, stable source identities, record-count evidence, source
fingerprints, and validation outcomes.

The evidence bundle must not contain credentials, tokens, private supplier
endpoints, or other secrets. Preview evaluation remains in memory and may emit
only DTO and stdout/JSON output. Lifecycle counters, preview results, audit
rows, and temporary database records are not persisted. No new table,
migration, cache write, or filesystem write is authorized.

## Qualified Full Snapshots

Only a successful, full, schema-valid, non-truncated snapshot may advance
presence tracking. It must meet the supplier minimum product count and allowed
product-drop threshold, have no fatal integrity blocker, confirm supplier
identity, and carry a unique fingerprint. A duplicate fingerprint is never
counted twice. Snapshot order, identity, supplier scope, comparability, capture
time, and fingerprints must validate fail-closed.

Each supplier owns its own qualification settings, identity mapping, price
validation, availability mapper, lifecycle mapper, and anomaly thresholds.
An existing approved supplier-specific freshness policy may be reused. APCOM
freshness rules must not become defaults for another supplier. A supplier with
no approved freshness rule is skipped or classified `manual_review` with a
stable reason code; no universal fallback is authorized.

For APCOM only, [APCOM Missing Offer Decisions V4](APCOM_MISSING_OFFER_DECISIONS_V4.md)
approves the latest successful, complete, validated stock/price snapshot as
fresh through 24 hours inclusive. After 24 hours it is stale. Freshness uses
explicit `evaluated_at` and the authoritative evidence-snapshot timestamp,
never `received_at` or `last_seen_at`. A stale offer is excluded from sellable-
offer selection and price calculation but is not missing, does not advance a
missing counter, and cannot trigger a write or archival action.

## Three-Snapshot Threshold

The threshold is three consecutive qualified snapshots in which the exact
supplier offer is absent. The first and second absence keep the offer active
and require availability confirmation. The third absence reaches the count
threshold but is still not eligible until the duration requirement is met.

## 48-Hour Duration

The first qualified absence starts the duration clock. At least 48 elapsed
hours are required in addition to three qualified absences. At the threshold,
the preview can say that a supplier offer would become future-deactivation
eligible, while `write_allowed` remains false.

## Frozen Snapshots

Failed, partial, malformed, truncated, below-minimum, anomalous, duplicate, or
otherwise unsafe snapshots are frozen. They do not increment or reset missing
counters, do not advance the duration state, and cannot deactivate or
reactivate an offer. Delayed or failed import evidence also freezes evaluation.
Frozen evidence requires human review.

## Present But Non-Sellable Offers

A present offer with valid identity is not missing merely because its price is
zero or invalid. It is non-sellable and receives a separate stable reason; it
resets the missing sequence, but is not reactivation eligible and does not
start a product archival clock. A present out-of-stock offer is also not
missing and resets the missing sequence. Out-of-stock may affect a sellability
or availability recommendation, but does not start an archival clock by
itself.

For APCOM, `fd_price = 0` is specifically `manual_review`, never a free Product
or zero catalog/selling price. It is excluded from valid offer selection and
price calculations and cannot authorize CREATE, UPDATE, publication,
reactivation, deactivation, archival, hiding, deletion, or unlinking.

Duplicate or conflicting offers are ambiguous, are not counted twice, and are
classified `manual_review`.

## Supplier-Offer-Only Deactivation

An absence decision is scoped to one supplier offer only. It never unlinks the
supplier product, unpublishes or deletes the catalog product, changes product
content, or changes a second supplier's offer.

## Reappearance And Reset

`supplier-offer-reappearance-policy-v1` allows a future reactivation preview
only for a qualified full snapshot with an exact supplier SKU, valid price
greater than zero, a valid supplier mapper result, and no identifier conflict
or blocking validation issue. A valid reappearance resets the missing count and
first-missing timestamp. Zero price is review-only; an identifier conflict is
blocked and never links or unlinks anything.

## Absence Is Not EOL

Source absence never means EOL. APCOM `eol` remains a distinct supplier
lifecycle observation. Its stock-cap and EOL interpretation do not apply to
ASBIS or another supplier.

## No Delete Or Unlink

No automatic product deletion, soft deletion, product unpublish, supplier link
change, content overwrite, or Catalog Sync action is authorized by this policy.
Actual lifecycle persistence requires a separate reviewed schema, execution,
and operational-approval phase.

## Deterministic Preview Contract

`evaluated_at` is an explicit input and must not come from an implicit current
clock. The same evidence bundle, `evaluated_at`, and relevant catalog state must
produce the same ordered result and output fingerprint. Sorting, pagination,
and filters must be stable. Output records policy/schema versions, evidence
fingerprints, and a catalog-state fingerprint or equivalent reproducibility
evidence.

The CLI-only report distinguishes qualified and frozen snapshots; present,
missing-candidate, and confirmed-missing offers; deactivation and reactivation
recommendations; active alternatives; no-sellable-offer products; day-60 and
month-24 recommendations; and skipped, blocked, conflict, and ambiguous rows.
Stable recommendation outcomes are `keep_active`, `would_deactivate_offer`,
`would_reactivate_offer`, `would_mark_unavailable`,
`would_mark_archived_noindex`, `would_mark_cold_archive_candidate`, and
`manual_review`.

Existing qualification and reappearance reason codes remain authoritative:
`snapshot_not_successful`, `snapshot_not_full`, `snapshot_schema_invalid`,
`snapshot_truncated`, `minimum_product_count_not_met`,
`maximum_product_drop_exceeded`, `fatal_source_integrity_blocker`,
`supplier_identity_unconfirmed`, `snapshot_fingerprint_missing`,
`duplicate_snapshot_fingerprint`, `snapshot_not_qualified`,
`supplier_sku_mismatch`, `zero_or_invalid_price`,
`supplier_mapper_validation_failed`, `identifier_conflict`, and
`blocking_validation_issue`. Operational evaluation must additionally use
stable reason codes for missing supplier freshness policy, unprovable
continuous absence, active or unknown import state, manual-product exclusion,
manual override, unresolved manual-maintenance protection, non-public workflow
state, duplicate offer, and disabled/deleted records. Exact additional code
names must be reviewed with the implementation contract; they must not be
generated from free-form messages.

Every output has `write_allowed=false`, zero mutation counters, and zero
dispatched-job counters. `SupplierImportActivityInspector` is the fail-closed
concurrency guard. An active import or an import state that cannot be assessed
safely aborts evaluation and must not produce a result presented as valid. No
Filament surface, job, event, schedule, or automatic execution is authorized.
