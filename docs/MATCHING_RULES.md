# Matching Rules

## Purpose

Document safe product matching so supplier rows are not merged into the wrong catalog product.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Data Ownership](DATA_OWNERSHIP.md), [Supplier Exclusions](SUPPLIER_EXCLUSIONS.md).

## Current Status

Catalog Sync Preview exposes matching visibility. Matching produces `match_type`, `match_confidence`, matched product ID/name where available, and feeds into `sync_action` preview.

## Safe Automatic Matches

Current safe matching sources:

| Match | Rule |
| --- | --- |
| Manual mapping | Supplier product already links to a catalog product. |
| Exact EAN | Same EAN means same product unless multiple matches create conflict. |
| MPN + Brand | MPN must match and brand must match. |
| Supplier SKU | Only within the same supplier. |
| Existing product offer | Existing `product_supplier_offers` mapping can identify the catalog product. |
| Already linked supplier product | `supplier_products.product_id` points to the catalog product. |

## Unsafe / Diagnostic Only

Name similarity is diagnostic/warning only.

Rules:

- Name similarity alone must not become safe UPDATE.
- Name similarity must not silently merge products.
- Ambiguous matches should become `CONFLICT` or diagnostic warning.
- Multiple exact matches must not be resolved blindly.

## Preview Requirements

Preview should show:

- matched product ID
- matched product name
- match type
- match confidence
- sync action
- sync reason

CREATE diagnostics also break down match buckets, including manual mapping, existing supplier mapping, existing product offer, already linked supplier product, fallback/internal match, name similarity, no exact match, and unknown/other.

## What Is Allowed

- Read-only match diagnostics.
- Manual selected CREATE for unmatched eligible rows.
- Future UPDATE only after explicit Phase 8 safety design.

## What Is Forbidden

- Auto-merge by name similarity.
- Use supplier SKU across different suppliers as a global match.
- Silently overwrite catalog data after a match.
- Treat ambiguous matches as safe writes.

## Future Work / Open Questions

- Better conflict review UI.
- Explicit manual mapping workflow.
- More granular match confidence values for supplier offers and historical mappings.
