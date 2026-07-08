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
- Legacy Product Attribute Value Reconciliation for dry-run-first, copy-safe
  proposals from out-of-category legacy values into existing category-assigned
  target attributes.
- Reconciled Legacy Values Visibility Cleanup so Product edit clearly marks old
  out-of-category values that have already been copied into category-driven
  specifications while preserving them for audit/reference.
- CPU Category Attribute Template for controlled CPU internal attributes and
  assignments to existing CPU categories without creating product values.
- Category Specification Template Coverage Plan for read-only discovery of
  direct, inherited, and missing category templates before the next
  product-family template phases.
- Internal Taxonomy and Supplier Category Mapping Foundation for canonical
  product families and pending-review supplier category mappings that do not
  apply to products.
- Multi-Supplier Import Discovery Foundation for read-only supplier staging,
  category, identifier, and overlap visibility before more supplier mapping
  approvals.
- Supplier Import Capability Audit for read-only supplier feed/driver/schedule
  readiness before adding the next staging import.
- Supplier Configuration Safety Cleanup for dry-run-first disabling of unsafe
  supplier schedules before adding the next staging import.
- Category-driven Product edit specifications editor for manually maintaining category-assigned values.
- Project AI agents and safety playbook for Codex/process guardrails.
- Product Specification Data Quality for read-only warning-only reporting of missing important category specifications.

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
- Product attributes are catalog-owned internal definitions. Category Attribute Sets can assign existing attributes to existing categories, admins can manually maintain individual product values from Product edit pages, Product Specification Data Quality reports missing important category specs without mutating data, the legacy reconciliation command can copy safe values into existing category-assigned targets one explicit product at a time, reconciled legacy values are marked read-only in admin while staying visible, the CPU template command can explicitly prepare CPU attributes/options/category assignments without product values, the category template coverage audit can report direct/inherited/missing template coverage without an apply mode, supplier category mappings can prepare pending-review taxonomy records without applying them to products, multi-supplier discovery can report staging/category/identifier overlap data without any apply mode, supplier import capability audit can report feed/driver/schedule readiness without fetching feeds, dispatching jobs, or exposing secrets, and supplier configuration cleanup can disable only unsafe supplier schedules by explicit apply. Supplier attribute mapping and frontend filters are not enabled yet.

## Next

1. Keep Phase 7.5 documentation lock current.
2. Keep feature flag/audit visibility read-only.
3. Phase 9C.6.3 Add Next Supplier Staging Import, Preview Only.
4. Phase 9C.6.4 Multi-Supplier Category Mapping Review.
5. Phase 9C.6.5 Multi-Supplier Identifier Overlap Review.
6. Phase 9C.7 Supplier Attribute Mapping Foundation.
7. Product specification data quality polish.
8. Storefront specification display and later attribute filters.
9. Product attribute filter design after controlled data quality.
10. Rollback tooling based on `catalog_sync_batches` and `catalog_sync_logs`.
11. Keep feature flags locked down before broader sync work.
12. Conflict/manual mapping workflow.
13. Sync All later.
14. Automatic sync later.
15. Nuxt i18n route integration and localized sitemap expansion.
16. Data enrichment workflow refinements after queue usage is observed.

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

Phase 9C.1 adds internal Product Attributes, controlled options, category assignment rules and typed product attribute value storage. Phase 9C.2 improves the Filament admin experience and adds the manual `product-attributes:seed-starter` dry-run/apply command for a starter internal attribute library. Phase 9C.3 adds `product-attributes:assign-category-sets` for controlled assignment of existing internal attributes to existing categories. Phase 9C.4 adds manual product-specific value management from Product edit pages. Phase 9C.4.1 makes category-assigned attributes easier to maintain as ready Product edit specification fields while keeping empty fields non-mutating. Phase 9C.5 adds read-only Product Specification Data Quality reporting based on existing category templates and product values. Phase 9C.5.1 adds `product-attributes:reconcile-legacy-values`, a dry-run-first copy-safe command that can apply safe target value rows only for one explicit SKU or product ID. Phase 9C.5.2 adds read-only admin visibility labels for legacy values that have already been fully or partially reconciled. Phase 9C.5.3 adds `product-attributes:seed-cpu-template`, a dry-run-first explicit-apply command for CPU attributes, safe CPU options, and assignments to existing CPU categories. Phase 9C.5.4 adds `product-attributes:audit-category-template-coverage`, a read-only planning command for direct, inherited, and missing category template coverage with no apply mode. Phase 9C.5.5 adds internal taxonomy and supplier category mapping records for pending review; supplier mappings do not apply to products or catalog categories. Phase 9C.5.6 adds a Filament review workflow for supplier category mapping records only; approval/rejection/ignore/reset mutate only `supplier_category_mappings` review metadata and do not apply mappings to products or categories. Phase 9C.5.8 is partial/paused intentionally after 6 approved mappings and 67 pending review mappings so template decisions can wait for a full multi-supplier view. Phase 9C.6 adds `suppliers:audit-discovery`, a read-only multi-supplier staging audit with no apply mode. Phase 9C.6.1 adds `suppliers:audit-import-capabilities`, a read-only supplier feed/driver/schedule/config audit with no apply mode, no remote feed fetch, no job dispatch, no Catalog Sync call, and redacted secret output. Phase 9C.6.2 adds `suppliers:cleanup-unsafe-schedules`, a dry-run-first cleanup whose explicit apply mode can only turn off unsafe supplier schedules. These phases do not parse supplier XML attributes, do not sync supplier attributes, do not expose frontend filters, and do not automatically mutate existing products or `supplier_products`.

Phase 9C.4.4 adds documentation-only AI agent and safety playbooks. It does not
add autonomous agents, scheduled AI jobs, background workers, runtime code,
Catalog Sync changes, supplier import changes, Product Sync changes, migrations,
commands, jobs, observers, or frontend features.

## Future Work / Open Questions

- Internal category template assignment plan based on reviewed supplier category mappings.
- Conflict review queue.
- Rollback UI.
- Supplier image import strategy.
- Controlled Sync All for eligible CREATE rows only.
- Scheduled preview generation before any scheduled writes.
- English content completion workflow and translation completeness reports.
- Internal Category Template Assignment Plan.
- Add Next Supplier Staging Import, Preview Only.
- Multi-Supplier Category Mapping Review.
- Multi-Supplier Identifier Overlap Review.
- Supplier Attribute Mapping Foundation.
- Product specification data quality polish.
- Supplier XML attribute mapping preview.
- Frontend attribute filters only after controlled data quality.
