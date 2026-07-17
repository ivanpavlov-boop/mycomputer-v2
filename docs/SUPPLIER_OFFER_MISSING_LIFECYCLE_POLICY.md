# Supplier Offer Missing Lifecycle Policy

## Purpose

`supplier-offer-missing-policy-v1` defines a supplier-neutral, future planning
contract for deciding when an individual supplier offer may become eligible for
a later deactivation workflow. It is preview-only in Phase 9C.6.5C.3D. It does
not change `supplier_products`, `product_supplier_offers`, products, links, or
catalog visibility.

## Qualified Full Snapshots

Only a successful, full, schema-valid, non-truncated snapshot may advance
presence tracking. It must meet the supplier minimum product count and allowed
product-drop threshold, have no fatal integrity blocker, confirm supplier
identity, and carry a unique fingerprint. A duplicate fingerprint is never
counted twice.

Each supplier owns its own qualification settings, identity mapping, price
validation, availability mapper, lifecycle mapper, and anomaly thresholds.

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
reactivate an offer. They require human review.

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
