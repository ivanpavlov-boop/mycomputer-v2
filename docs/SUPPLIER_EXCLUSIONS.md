# Supplier Exclusions

## Purpose

Document rules that prevent staged supplier products from becoming catalog sync candidates.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Supplier Import](SUPPLIER_IMPORT.md), [Matching Rules](MATCHING_RULES.md).

## Current Status

Supplier exclusion rules exist and are evaluated in Catalog Sync Preview. Excluded rows become `SKIP` and remain in staging.

## Supported Rule Areas

Rules may target combinations of:

- supplier
- category
- brand
- SKU
- EAN
- MPN
- product name contains
- zero stock
- EOL products where supported by current feed/status data
- missing EAN
- min/max price
- priority
- active/inactive status
- reason/notes

Only rely on conditions verified in the current Filament resource/service.

## Effects

- Excluded supplier products remain in `supplier_products`.
- Excluded rows show as `SKIP` in preview.
- Exclusion reason should be visible in preview.
- Exclusions affect sync eligibility, not staging import.

## What Is Allowed

- Exclude products from catalog sync.
- Keep excluded supplier products in staging for diagnostics and future review.
- Use exclusions for APCOM categories/brands/products that should not enter catalog.

## What Is Forbidden

- Do not delete `supplier_products` because they match an exclusion.
- Do not delete catalog products because a supplier product becomes excluded.
- Do not stop feed staging because a future sync exclusion exists.
- Do not use exclusions as hidden destructive sync rules.

## Future Work / Open Questions

- More reporting around which exclusion rules affect large supplier feeds.
- Dedicated review workflow for excluded-but-interesting products.
- Supplier-specific default exclusion templates for future suppliers.
