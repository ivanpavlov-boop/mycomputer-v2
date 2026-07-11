# Catalog Sync Phases

## Purpose

Track completed and planned Catalog Sync phases.

Related docs: [Roadmap](ROADMAP.md), [Catalog Sync](CATALOG_SYNC.md), [Sync Safety](SYNC_SAFETY.md).

## Current Status

Phase 8 manual selected UPDATE price/stock sync has been implemented behind a feature flag. Broader sync work remains paused until rollback tooling and additional designs are complete.

## Completed Phases

| Phase | Name | Status |
| --- | --- | --- |
| Phase 1 | Catalog Sync Preview UI | Complete |
| Phase 2 | Supplier product query | Complete |
| Phase 3 | Pricing preview | Complete |
| Phase 4 | Exclusions preview | Complete |
| Phase 5 | Matching visibility | Complete |
| Phase 6 | Sync action preview | Complete |
| Phase 7 | Manual selected CREATE sync | Complete |
| Phase 7.1 | CREATE candidate discovery | Complete |
| Phase 7.2 | CREATE diagnostics | Complete |
| Phase 7.5 | Architecture & Documentation Lock | Complete |
| Phase 7.6 | Catalog Sync Safety Infrastructure | Complete |
| Phase 8 | Manual selected UPDATE for price/stock only | Complete |
| Phase 8.1 | Catalog Sync admin visibility | Complete |
| Phase 8.5B | Product workflow and configurable quality flags | Complete |
| Phase 8.6A | Multilingual foundation | Complete |
| Phase 9A | Product data quality and enrichment queue | Complete |
| Phase 9C.1 | Product attributes core foundation | Complete |
| Phase 9C.2 | Product attributes admin usability and starter structure | Complete |
| Phase 9C.3 | Category attribute sets | Complete |
| Phase 9C.4 | Manual product attribute values admin workflow | Complete |
| Phase 9C.4.1 | Category-driven product specifications editor | Complete |
| Phase 9C.4.2 | Supplier import automatic product creation safety hotfix | Complete |
| Phase 9C.4.3 | Review automatically-created catalog products | Complete |
| Phase 9C.4.4 | Project AI agents and safety playbook | Complete |
| Phase 9C.4.5 | Admin UX verification of category-driven specifications | Complete |
| Phase 9C.5 | Product specification data quality | Complete |
| Phase 9C.5.1 | Legacy product attribute value reconciliation | Complete |
| Phase 9C.5.2 | Reconciled legacy values visibility cleanup | Complete |
| Phase 9C.5.3 | CPU category attribute template | Complete |
| Phase 9C.5.4 | Category specification template coverage plan | Complete |
| Phase 9C.5.5 | Internal taxonomy and supplier category mapping foundation | Complete |
| Phase 9C.5.6 | Supplier category mapping review workflow | Complete |
| Phase 9C.6 | Multi-supplier import discovery foundation | Complete |
| Phase 9C.6.1 | Supplier Import Capability Audit | Complete |
| Phase 9C.6.2 | Supplier Configuration Safety Cleanup | Complete |
| Phase 9C.6.3 | Add Next Supplier Staging Import, Preview Only | Complete |
| Phase 9C.6.4 | Controlled Supplier Staging Import Apply for One Supplier | Complete |
| Phase 9C.6.4.1 | ASBIS Dual-Feed Local Preview and Join | Complete |
| Phase 9C.6.4.1c | ASBIS Full-File Streaming Preview and Apply Readiness Audit | Complete; read-only streaming audit with source fingerprints and no apply mode. |
| Phase 9C.6.4.1d | ASBIS Audit Consistency and Missing-Key Safety | Complete; canonical identifier overlaps, missing-key blockers and reconciliation remain read-only. |

## In Progress

| Phase | Name | Status |
| --- | --- | --- |
| Phase 9C.6.4.2 | Controlled ASBIS Dual-Feed Staging Apply | Implementation in progress; dry-run-first and feature-flagged create-only `supplier_products` staging path. Real apply remains blocked pending reviewed audit fingerprints and explicit operational approval. |

## Paused / Partial Phases

| Phase | Name | Status |
| --- | --- | --- |
| Phase 9C.5.8 | Power/Cables template based on internal taxonomy | Partial/paused intentionally after 6 approved mappings and 67 pending review mappings. Safety verified; broader review waits until all suppliers are staged and visible in discovery. |

## Next Planned Phases

| Phase | Name | Notes |
| --- | --- | --- |
| Phase 9C.6.4.2.1 | Controlled ASBIS Apply Operational Approval | Planned follow-up for a controlled window, post-apply checks, and flag disablement; no broader sync. |
| Phase 9C.6.5 | ASBIS Staging Data Discovery Audit | Audit newly staged ASBIS data before broader mapping review. |
| Phase 9C.6.6 | Multi-Supplier Category Mapping Review | Review mappings in batches using the full multi-supplier picture. |
| Phase 9C.6.7 | Multi-Supplier Identifier Overlap Review | Review exact and possible overlaps before future offer grouping. |
| Phase 9C.7 | Supplier Attribute Mapping Foundation | Preview/planning foundation only until a later explicit approval/write phase. |
| Phase 9C.8 | Product specification data quality polish | Improve admin ergonomics after real queue usage. |
| Phase 9C.9 | Storefront specification display | Display catalog-owned specs only after controlled data quality. |
| Phase 9C.10 | Frontend attribute filters | Only after controlled data quality and approved product values. |
| Phase 9 | Rollback support | Required before broad writes. |
| Phase 10 | Manual Sync All eligible CREATE | Later, after stronger audit controls. |
| Phase 11 | Scheduled preview generation | Preview only before scheduled writes. |
| Phase 12 | Controlled automatic sync | Last, behind feature flags and rollback. |

## Allowed

- Maintain Phase 7.5 docs, Phase 7.6 safety rules, and Phase 8 commercial-field allowlist.
- Maintain read-only Phase 8.1 feature flag and audit visibility.
- Add tests/docs before rollback or broader sync phases.
- Maintain product workflow and quality flags as admin/content controls, not supplier sync expansion.
- Add multilingual docs/config/schema without changing catalog sync execution behavior.
- Use the Product Data Quality Queue for read-only enrichment triage; corrections still happen through existing product edit workflows.
- Maintain Product Attributes as an internal catalog-owned foundation; supplier attribute mapping and frontend filters require later explicit phases.
- Maintain Category Attribute Sets as controlled category-to-attribute assignment rules; they must not populate product values or expose storefront filters by themselves.
- Manage individual product attribute values manually from Product edit pages without auto-filling existing products or syncing supplier XML attributes.
- Use category-assigned attributes as ready Product edit specification fields while keeping empty fields non-mutating and required flags visual only.
- Use Product Specification Data Quality as warning-only reporting based on existing category templates and product values; it must not auto-fill, block saves, or mutate products, `supplier_products`, `product_attribute_values`, `product_attributes`, `attribute_values`, or `category_product_attributes`.
- Use `product-attributes:reconcile-legacy-values` as a dry-run-first, copy-safe maintenance command for legacy out-of-category product attribute values. Apply mode must name one product by SKU or product ID, must preserve legacy values, and must not create attributes, options, category assignments, products, or supplier staging data.
- Mark already-reconciled legacy product attribute values in Product edit as read-only admin visibility only. Legacy values remain visible and preserved; classification must not create, update, hide, or delete product attribute values.
- Use `product-attributes:seed-cpu-template` as a dry-run-first, explicit-apply command for CPU internal attributes, safe CPU options, and assignments to existing CPU categories only. It must not create categories or product values.
- Use `product-attributes:audit-category-template-coverage` as a read-only planning audit for direct, inherited, and missing category specification templates. It must not have an apply mode and must not create or update product values, attributes, options, category assignments, categories, products, or `supplier_products`.
- Use `taxonomy:seed-canonical-families` and `supplier-categories:*` commands as the internal taxonomy and supplier category mapping foundation. Only `canonical_product_families` and `supplier_category_mappings` may be written by their explicit apply modes; mappings must remain `pending_review` and must not apply to products.
- Use the supplier category mapping review workflow to mark only mapping records as approved, rejected, ignored, or pending again. Approval is not an apply action and must not create categories, move products, update `products.category_id`, or trigger Catalog Sync.
- Use `suppliers:audit-discovery` as a read-only multi-supplier staging audit. It reports suppliers, staged products, category mapping status, identifier completeness, and possible overlaps, but has no apply mode and must not mutate products, `supplier_products`, mappings, categories, canonical families, attributes, or category assignments.
- Use `suppliers:audit-import-capabilities` as a read-only supplier import capability audit. It reports feed readiness, supported static drivers, redacted feed config, schedules, and checklist status, but has no apply mode and must not fetch feeds, dispatch jobs, call Catalog Sync, expose secrets, or mutate protected tables.
- Use `suppliers:cleanup-unsafe-schedules` as a dry-run-first supplier configuration safety cleanup. Explicit apply may only disable unsafe supplier schedules for active import-enabled suppliers with missing feed/driver configuration and zero staged rows. It must not run imports, fetch feeds, dispatch jobs, call Catalog Sync, mutate catalog data, mutate staging data, or apply supplier mappings.
- Use `suppliers:preview-staging-import` as a local-file, preview-only parser for the next supplier. It reports detected fields, normalized coverage, identifiers, categories, price/stock coverage, overlaps, row issues, and future staging action labels, but has no apply mode and must not fetch remote feeds, dispatch jobs, call Catalog Sync, mutate staging data, mutate catalog data, or expose supplier feed secrets.
- Use `suppliers:controlled-staging-import` as the ASBIS-only controlled staging apply command. Dry-run is default; apply requires `--apply --confirm-supplier=asbis` and may write only ASBIS `supplier_products` rows matched by supplier and supplier SKU. It must not fetch remote feeds, dispatch jobs, call Catalog Sync, mutate products/categories/mappings/attributes, enable schedules, or store real feed URLs or credentials.
- Use `suppliers:preview-asbis-dual-feed` as a local-only ASBIS ProductList plus PriceAvail join preview. It reports join confidence, normalized rows, unmatched rows, overlap candidates, row issues, and future staging action labels, but has no apply mode and must not fetch remote feeds, dispatch jobs, call Catalog Sync, mutate `supplier_products`, mutate catalog data, create categories, apply mappings, import images, or expose secrets.
- Use `suppliers:audit-asbis-apply-readiness` for a complete local-file streaming audit with exact readiness counts, bounded samples and SHA-256 source fingerprints. Its verdict is advisory only; it has no apply mode and must keep all protected change counters at zero.
- The ASBIS readiness audit must normalize empty identifiers to null, preserve EAN leading zeroes, report overlap groups separately from affected rows, block missing ProductCode/WIC/name rows, and expose reconciliation before any future controlled staging apply is considered.
- Use `catalog:review-auto-created-products` as a dry-run-first corrective command for the three known products created before the Phase 9C.4.2 supplier import safety hotfix. The command must remain allowlisted, idempotent, and limited to review/status fields.
- Use the Project AI Agents and Catalog Sync Safety playbooks as process guardrails only; they do not add autonomous agents, jobs, or runtime behavior.

## Forbidden

- Do not add Sync All.
- Do not enable automatic sync.
- Do not broaden UPDATE beyond price/stock/availability/supplier offer fields.
- Do not let supplier imports overwrite catalog attributes/specifications without a controlled preview and approval phase.

## Future Work / Open Questions

- Exact Phase 8 UI layout.
- Rollback tooling on top of the Phase 7.6 sync batch/log audit trail.
- Rollback execution UX.
