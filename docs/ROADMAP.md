# Roadmap

## Purpose

Track current phase status and planned Catalog Sync work.

Related docs: [Phases](PHASES.md), [Sync Safety](SYNC_SAFETY.md), [Architecture](ARCHITECTURE.md).

## Current Status

Manual selected UPDATE price/stock sync is implemented behind `CATALOG_SYNC_UPDATE_ENABLED`. Broader sync work remains paused until rollback tooling and additional ownership designs are complete.

## Completed

- Supplier import.
- APCOM staging import.
- Pricing preview.
- Exclusions preview.
- Matching preview.
- Sync action preview.
- Manual selected CREATE sync.
- CREATE candidate discovery.
- Read-only CREATE diagnostics.
- Catalog action filtering.
- Table scroll panel.
- Sticky header where safe.
- Counter layout.
- Catalog sync feature flags and selected CREATE audit trail.
- Manual selected UPDATE price/stock sync with audit old/new commercial values.
- Catalog Sync Preview feature flag visibility.
- Read-only Filament Catalog Sync Batches and Catalog Sync Logs views.
- Product workflow for manual product approval and publishing.
- Configurable non-blocking Product Quality Flags.
- Multilingual foundation for BG primary and EN secondary content.
- Product Data Quality Queue for read-only enrichment triage.
- Product Attributes core foundation for internal catalog specifications.
- Product Attributes admin usability and controlled starter attribute structure.
- Category Attribute Sets for controlled existing category-to-attribute assignments.
- Manual Product edit workflow for product-specific attribute values.
- Category-driven Product edit specifications editor for manually maintaining category-assigned values.

## Current Safety Position

- Selected CREATE sync is enabled.
- Selected UPDATE price/stock sync is feature-flagged and disabled by default unless `CATALOG_SYNC_UPDATE_ENABLED=true`.
- Sync All is not enabled.
- Automatic sync is not enabled.
- Scheduled sync is not enabled.
- Image import through sync is not enabled.
- Diagnostics are read-only.
- Manual products start as drafts and must be explicitly reviewed/published.
- Supplier-created products do not require manual approval by default.
- Product enrichment gaps are surfaced in a read-only admin queue; fixes still use existing product edit permissions.
- Product attributes are catalog-owned internal definitions. Category Attribute Sets can assign existing attributes to existing categories, and admins can manually maintain individual product values from Product edit pages, including category-assigned specification fields. Supplier attribute mapping and frontend filters are not enabled yet.

## Next

1. Keep Phase 7.5 documentation lock current.
2. Keep feature flag/audit visibility read-only.
3. Rollback tooling based on `catalog_sync_batches` and `catalog_sync_logs`.
4. Keep feature flags locked down before broader sync work.
5. Conflict/manual mapping workflow.
6. Sync All later.
7. Automatic sync later.
8. Nuxt i18n route integration and localized sitemap expansion.
9. Data enrichment workflow refinements after queue usage is observed.
10. Controlled supplier attribute mapping preview and approval.
11. Storefront specification display and later attribute filters.

## Phase 8 Initial UPDATE Scope

UPDATE sync must initially update only:

- price
- supplier cost
- stock / quantity
- availability
- active supplier offer

UPDATE sync must not update:

- name
- slug
- descriptions
- SEO
- images
- categories
- attributes/specifications

## Phase 9C Attribute Foundation Scope

Phase 9C.1 adds internal Product Attributes, controlled options, category assignment rules and typed product attribute value storage. Phase 9C.2 improves the Filament admin experience and adds the manual `product-attributes:seed-starter` dry-run/apply command for a starter internal attribute library. Phase 9C.3 adds `product-attributes:assign-category-sets` for controlled assignment of existing internal attributes to existing categories. Phase 9C.4 adds manual product-specific value management from Product edit pages. Phase 9C.4.1 makes category-assigned attributes easier to maintain as ready Product edit specification fields while keeping empty fields non-mutating. These phases do not parse supplier XML attributes, do not sync supplier attributes, do not expose frontend filters, and do not automatically mutate existing products or `supplier_products`.

## Future Work / Open Questions

- Manual mapping workflow.
- Conflict review queue.
- Rollback UI.
- Supplier image import strategy.
- Controlled Sync All for eligible CREATE rows only.
- Scheduled preview generation before any scheduled writes.
- English content completion workflow and translation completeness reports.
