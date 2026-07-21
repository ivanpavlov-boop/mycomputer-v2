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
- Unified Product edit quality summary combining existing scanner issues,
  category specification quality and active manual flags without blocking or
  mutating Product workflow.
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
- Reusable Supplier Onboarding Framework Discovery & Contracts for immutable
  normalized-record, driver, profile, fingerprint, preview, staging-plan,
  verification, price, availability, and validation contracts. This foundation
  is merged; it has no generic production driver or write path.
- Multi-Supplier Readiness Matrix for local read-only capability, staging,
  mapping, and safety-flag evidence. It has not run against production and
  cannot fetch feeds, import, write, call Catalog Sync, dispatch jobs, or
  change schedules.
- Next Supplier Staging Import Preview for local XML/CSV/JSON feed samples with
  detected fields, normalized coverage, overlaps, row issues, and zero-write
  safety counters.
- Category-driven Product edit specifications editor for manually maintaining category-assigned values.
- Project AI agents and safety playbook for Codex/process guardrails.
- Product Specification Data Quality for read-only warning-only reporting of missing important category specifications.
- ASBIS Full-File Streaming Preview and Apply Readiness Audit for exact local
  ProductCode-to-WIC counts, bounded samples and source fingerprints without
  staging writes, remote fetches, jobs or Catalog Sync.
- Controlled ASBIS v2 staging apply and production post-apply verification
  closeout: 4,844 ASBIS staging rows, zero linked products, and zero protected
  records changed.

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
- Product edit shows one read-only, warning-only quality summary. It does not
  block save, review, approval or publishing; assign flags; perform automatic
  remediation; mutate Products or suppliers; or change Catalog Sync behavior.
- Product attributes are catalog-owned internal definitions. Category Attribute Sets can assign existing attributes to existing categories, admins can manually maintain individual product values from Product edit pages, Product Specification Data Quality reports missing important category specs without mutating data, the legacy reconciliation command can copy safe values into existing category-assigned targets one explicit product at a time, reconciled legacy values are marked read-only in admin while staying visible, the CPU template command can explicitly prepare CPU attributes/options/category assignments without product values, the category template coverage audit can report direct/inherited/missing template coverage without an apply mode, supplier category mappings can prepare pending-review taxonomy records without applying them to products, multi-supplier discovery can report staging/category/identifier overlap data without any apply mode, supplier import capability audit can report feed/driver/schedule readiness without fetching feeds, dispatching jobs, or exposing secrets, supplier configuration cleanup can disable only unsafe supplier schedules by explicit apply, the next supplier staging import preview can parse local XML/CSV/JSON samples without writes or remote feed access, the controlled ASBIS staging import can write only ASBIS `supplier_products` rows after explicit dry-run-first confirmation, and the ASBIS dual-feed preview can join local ProductList/PriceAvail files without writes or remote feed access. Supplier attribute mapping and frontend filters are not enabled yet.

## Next Supplier Sequence

1. **ASBIS production closeout — completed.** The controlled v2 staging apply
   and read-only post-apply verification completed with verdict `verified` on
   2026-07-11.
2. **Reusable Supplier Onboarding Framework Discovery & Contracts — complete
   and merged.** Contracts and pure services exist without a generic production
   driver, a new supplier, or any import/apply operation.
3. **Multi-Supplier Readiness Matrix — complete locally.** The read-only matrix
   has no production run yet and cannot perform an operational action.
4. **APCOM deterministic audit closeout — complete.** APCOM remains frozen
   with `import_enabled=true`; its closeout has no blockers but retains two
   evidence warnings.
5. **APCOM Local Source Profile and Normalization Plan - complete and
   deployed.** The reusable planner is local-file-only, requires explicit
   source input and human review. Its authorized operational profile created no
   writes or configuration; source evidence remains outside Git.
6. **APCOM Official Field Semantics and Hashed Source-to-Staging Reconciliation.**
   `apcom-official-v1` is a non-persistent, local read-only contract. Its
   first operational reconciliation safely failed closed on observed
   non-binary stock values. The additive `apcom-observed-stock-v1` profile was
   then reconciled read-only; stock semantics remain unresolved.
7. **APCOM Reconciliation Review and Operational Closeout - complete.** The
   C.3A.2 closeout records exact source/staging and linked-state aggregates,
   EAN consistency, unresolved commercial decisions, zero mutations, and the
   pending human-decision gate. See
   `docs/APCOM_RECONCILIATION_REVIEW_CLOSEOUT.md`.
8. **APCOM Human Decision Register and Preview-only Feed Profile Design -
   complete and deployed.** The operational preview completed read-only and
   passed its strict contract. Human decisions remain pending; the profile is
   not persisted or executable. See
   `docs/APCOM_PREVIEW_ONLY_FEED_PROFILE_OPERATIONAL_CLOSEOUT.md`.
9. **APCOM Preview-only Feed Profile Operational Closeout - complete.** The
   source/staging evidence and zero-mutation guarantees are documented. No
   import, schedule re-enable, or Catalog Sync approval exists.
10. **APCOM Authoritative Human Decision Evidence and Profile Approval Gate -
   next/pending, not started.** It requires authoritative evidence and explicit
   human decisions before any future execution design.
11. Select Supplier #3 only after a reviewed readiness matrix and explicit human
   decision; ASBIS remains Supplier #2.
12. Supplier #3 preview-only integration.
13. Controlled `supplier_products` staging apply.
14. Post-apply verification.
15. Repeat the same controlled sequence for the remaining current suppliers.
16. Supplier category and canonical mappings.
17. Controlled manual CREATE sync.
18. Optional controlled UPDATE pilot later.

Every future supplier must use the same onboarding pipeline rather than an
uncontrolled one-off importer:

```text
Supplier registration
-> capability audit
-> feed profile
-> driver/adapter
-> normalization
-> preview
-> controlled supplier_products staging
-> post-apply verification
-> mappings
-> manual CREATE sync
-> optional controlled UPDATE pilot
```

Phase 9C.6.5A completed and merged the local discovery contract foundation for reusable
normalized supplier payloads, drivers, versioned feed profiles,
source/candidate fingerprints, preview/report, create-only staging planning,
post-apply verification, availability/price normalization, and validation
issues. It did not add production wiring, a generic driver, a supplier, a
command, a migration, a remote fetch, a staging apply, or any catalog write.
Phase 9C.6.5B adds a local read-only readiness matrix with safe configuration
presence, capability/staging/mapping evidence, deterministic score, blockers,
and zero-change counters. It does not run a production matrix, fetch a source,
or mutate any supplier, catalog, mapping, attribute, queue, schedule, or
Catalog Sync surface. Phase 9C.6.5C is the APCOM Supplier #1 Legacy
Integration Audit & Normalization Discovery phase. Its implementation is
local and read-only. Its separately approved deterministic closeout is now
documented. It did not re-import APCOM, repair links, approve a feed profile,
or select Supplier #3. The controlled freeze changed only
`suppliers.schedule_enabled` from true to false; APCOM `import_enabled` remains
true. No Catalog Sync, import, mapping approval, link repair, or catalog
content change occurred.

Phase 9C.6.5C.1 adds the separate dry-run-first
`suppliers:controlled-schedule-freeze` guard. It can only change one
explicitly selected supplier's `schedule_enabled` flag after exact state
locking and operator confirmation; it does not perform an import or audit.
APCOM's staging-safe classification is unchanged. Phase 9C.6.5C.2 performed
the deterministic read-only closeout after the freeze. No automatic unfreeze
is implemented, and APCOM remains frozen pending a separately approved
operational decision.

## Next

1. Keep Phase 7.5 documentation lock current.
2. Keep feature flag and audit visibility read-only.
3. Preserve the completed C3C v2 operational record: PR #152 merged at
   `b2b4fb95f1d2bfe2382fe6cab9a8462fa6f7e277`, CI #322 succeeded, and VPS was
   synced and verified. APCOM schedule remains disabled; Catalog Sync UPDATE,
   Sync All, and automatic sync remain disabled.
4. Review the local/in-review C3D synthetic missing-offer lifecycle policy.
   It does not authorize operational lifecycle preview, offer writes,
   storefront visibility, sitemap/noindex behavior, retention cleanup,
   persistence, schedule enablement, import, or Catalog Sync.
5. Select Supplier #3 only after a reviewed readiness matrix and explicit human
   decision; ASBIS remains Supplier #2.
6. Phase 9C.6.6 Multi-Supplier Category Mapping Review.
7. Phase 9C.6.7 Multi-Supplier Identifier Overlap Review.
8. Phase 9C.7 Supplier Attribute Mapping Foundation.
9. Product Data Quality 2B category and brand quality workflow.
10. Product Data Quality 2C image and alt-text quality.
11. Product Data Quality 2D SEO and description quality.
12. Product Data Quality 2E category-template and specification completion.
13. Storefront specification display and later attribute filters.
14. Product attribute filter design after controlled data quality.
15. Rollback tooling based on `catalog_sync_batches` and `catalog_sync_logs`.
16. Keep feature flags locked down before broader sync work.
17. Conflict/manual mapping workflow.
18. Sync All later.
19. Automatic sync later.
20. Nuxt i18n route integration and localized sitemap expansion.
21. Data enrichment workflow refinements after queue usage is observed.

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

Phase 9C.6.4.1d corrects audit consistency for canonical identifier overlaps,
empty join keys, missing names and row/key reconciliation. It remains
read-only. Phase 9C.6.4.2 is merged as a guarded staging path; Phase
9C.6.4.2a is complete after safely rolled-back production apply attempts.
Phase 9C.6.4.2.1 adds only a read-only local post-apply verifier; production
verification and any operational approval remain pending.

Phase 9C.1 adds internal Product Attributes, controlled options, category assignment rules and typed product attribute value storage. Phase 9C.2 improves the Filament admin experience and adds the manual `product-attributes:seed-starter` dry-run/apply command for a starter internal attribute library. Phase 9C.3 adds `product-attributes:assign-category-sets` for controlled assignment of existing internal attributes to existing categories. Phase 9C.4 adds manual product-specific value management from Product edit pages. Phase 9C.4.1 makes category-assigned attributes easier to maintain as ready Product edit specification fields while keeping empty fields non-mutating. Phase 9C.5 adds read-only Product Specification Data Quality reporting based on existing category templates and product values. Phase 9C.5.1 adds `product-attributes:reconcile-legacy-values`, a dry-run-first copy-safe command that can apply safe target value rows only for one explicit SKU or product ID. Phase 9C.5.2 adds read-only admin visibility labels for legacy values that have already been fully or partially reconciled. Phase 9C.5.3 adds `product-attributes:seed-cpu-template`, a dry-run-first explicit-apply command for CPU attributes, safe CPU options, and assignments to existing CPU categories. Phase 9C.5.4 adds `product-attributes:audit-category-template-coverage`, a read-only planning command for direct, inherited, and missing category template coverage with no apply mode. Phase 9C.5.5 adds internal taxonomy and supplier category mapping records for pending review; supplier mappings do not apply to products or catalog categories. Phase 9C.5.6 adds a Filament review workflow for supplier category mapping records only; approval/rejection/ignore/reset mutate only `supplier_category_mappings` review metadata and do not apply mappings to products or categories. Phase 9C.5.8 is partial/paused intentionally after 6 approved mappings and 67 pending review mappings so template decisions can wait for a full multi-supplier view. Phase 9C.6 adds `suppliers:audit-discovery`, a read-only multi-supplier staging audit with no apply mode. Phase 9C.6.1 adds `suppliers:audit-import-capabilities`, a read-only supplier feed/driver/schedule/config audit with no apply mode, no remote feed fetch, no job dispatch, no Catalog Sync call, and redacted secret output. Phase 9C.6.2 adds `suppliers:cleanup-unsafe-schedules`, a dry-run-first cleanup whose explicit apply mode can only turn off unsafe supplier schedules. Phase 9C.6.3 adds `suppliers:preview-staging-import`, a preview-only local XML/CSV/JSON parser for next-supplier feed samples with no apply mode, no remote feed fetch, no job dispatch, no Catalog Sync call, and zero protected-table writes. Phase 9C.6.4 adds `suppliers:controlled-staging-import`, an ASBIS-only dry-run-first command whose explicit apply mode may write only ASBIS `supplier_products` staging rows and must not mutate catalog products, categories, mappings, attributes, schedules, or Catalog Sync. Phase 9C.6.4.1 adds `suppliers:preview-asbis-dual-feed`, a local-only, read-only ASBIS ProductList/PriceAvail join preview with no apply mode, no remote fetch, no job dispatch, and zero protected-table writes. Phase 9C.6.4.1c adds a complete local-file XMLReader audit with source fingerprints and advisory readiness classification. Mapping review remains paused at 6 approved and 67 pending-review mappings. These phases do not sync supplier attributes, do not expose frontend filters, and do not automatically mutate existing products outside explicitly confirmed staging-only apply phases.

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
- Controlled Supplier Staging Import Apply for One Supplier.
- Multi-Supplier Category Mapping Review.
- Multi-Supplier Identifier Overlap Review.
- Supplier Attribute Mapping Foundation.
- Product specification data quality polish.
- Supplier XML attribute mapping preview.
- Frontend attribute filters only after controlled data quality.
