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
- Commerce Phase 1A Cart Architecture, Safety and Gap Audit, completed locally
  as documentation and documentation-contract tests only.
- Unified Product edit quality summary combining existing scanner issues,
  category specification quality and active manual flags without blocking or
  mutating Product workflow.
- Read-only Category and Brand quality triage in the Product Data Quality Queue,
  with combined assignment states, searchable catalog filters, overview counts
  and manual correction through the existing Product edit form.
- Read-only Product image and ALT-text metadata triage with deterministic image
  states, queue coverage/filter/count visibility and manual correction through
  the existing Product image controls.
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
- Category and Brand quality remains read-only during queue listing, filtering,
  counting and summary rendering. Inactive assignments are warnings; no inline
  or bulk assignment, supplier-derived suggestion, automatic categorization,
  Brand detection, flag resolution, workflow gate or visibility change exists.
- Product image quality evaluates database metadata only. ALT completeness is
  not semantic image validation, and the workflow performs no file check,
  remote request, supplier-image use, automatic ALT generation, automatic
  primary selection, image mutation, workflow gate or visibility change.
- Product SEO and description quality evaluates deterministic field
  completeness plus the existing weak-description scanner rule. The queue and
  Product edit summary show SEO, Bulgarian-description and English-localization
  scores without rewriting rich HTML or localization JSON. There is no
  automatic copywriting, SEO generation, translation, language detection,
  AI/NLP analysis, supplier-content use, inline or bulk content mutation,
  workflow gate, public-visibility change, public-SEO change or Catalog Sync
  change. Semantic language correctness remains a manual editorial concern.
- Product attributes are catalog-owned internal definitions. Category Attribute Sets can assign existing attributes to existing categories, admins can manually maintain individual product values from Product edit pages, Product Specification Data Quality reports missing important category specs without mutating data, the legacy reconciliation command can copy safe values into existing category-assigned targets one explicit product at a time, reconciled legacy values are marked read-only in admin while staying visible, the CPU template command can explicitly prepare CPU attributes/options/category assignments without product values, the category template coverage audit can report direct/inherited/missing template coverage without an apply mode, supplier category mappings can prepare pending-review taxonomy records without applying them to products, multi-supplier discovery can report staging/category/identifier overlap data without any apply mode, supplier import capability audit can report feed/driver/schedule readiness without fetching feeds, dispatching jobs, or exposing secrets, supplier configuration cleanup can disable only unsafe supplier schedules by explicit apply, the next supplier staging import preview can parse local XML/CSV/JSON samples without writes or remote feed access, the controlled ASBIS staging import can write only ASBIS `supplier_products` rows after explicit dry-run-first confirmation, and the ASBIS dual-feed preview can join local ProductList/PriceAvail files without writes or remote feed access. Phase 9C.10 exposes only approved catalog-owned values as read-only storefront filters; supplier attribute mapping remains disabled.

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

Product Data Quality 2E is complete locally. Category-template coverage,
Product specification quality, queue filters/counts, Category admin coverage
and the Product edit summary now share one read-only inheritance and validation
path. The phase adds no automatic remediation, inline assignment, workflow
gate, Product or supplier mutation, migration, public-visibility change or
Catalog Sync behavior change.

Phase 9C.9 Storefront Specification Display is complete locally. The public
Product detail response and Nuxt Characteristics tab now present only valid,
non-empty, catalog-owned values from the effective direct or inherited Category
template. Group and item ordering is deterministic; labels are Bulgarian-first;
boolean, numeric, select, multiselect and unit-bearing values are formatted for
customers. Empty Characteristics tabs are hidden. Internal quality/template,
workflow, audit and supplier metadata is not public, supplier-derived values are
not used, and legacy `products.specifications` is not newly exposed.

The presentation remains read-only and does not mutate Products, Categories,
templates, attributes, values or `supplier_products`. It changes no Product
Workflow, public-visibility, supplier import or Catalog Sync behavior. Phase
9C.9 final manual staging verification with a populated published Product remains
pending and is not claimed complete.

Phase 9C.10 Frontend Attribute Filters is complete locally. Existing Product and
Category Product listing responses now include useful filter metadata for active,
visible and explicitly filterable select, multiselect, boolean and numeric catalog
attributes from effective direct or inherited Category templates. Options and
ranges come only from publicly visible Products with manual catalog-owned values;
supplier data and internal quality/template metadata remain isolated.

The typed URL contract uses stable attribute codes and option slugs, OR semantics
within one attribute, AND semantics across attributes, and inclusive numeric
bounds. Nuxt keeps the URL as the SSR-safe source of truth and provides responsive
desktop/mobile controls, removable Bulgarian chips, pagination reset and safe
empty/error recovery. Existing Category, Brand, price, availability, search,
sorting and pagination behavior remains in place. Per-option counts are omitted
until accurate self-excluding counts can be provided without an unbounded query
design. No Product, Category, template, attribute, value or supplier record is
mutated, and no sitemap/feed, Product Workflow, public-visibility, supplier import
or Catalog Sync behavior changes.

Phase 9C.10.1 Configurable Filter Control Types and Range Slider UX is complete
locally. Each effective Category attribute assignment may now choose `auto`,
`options`, `yes_no`, `range_slider` or `min_max` within a server-enforced type
compatibility matrix. `auto` preserves existing controls. Closest-Category
precedence remains authoritative, and broad catalog scopes use a deterministic
common-control policy: mixed numeric sliders and fields fall back to `min_max`,
while incompatible mixes are omitted.

The API keeps the existing semantic filter `type` and URL query contract while
adding a presentation-only `control`. Nuxt adds native accessible dual-handle
sliders for configured numeric attributes and public price, plus explicit
price-only and attribute-only clearing. Price bounds are computed from the
exact public endpoint non-attribute scope before selected attribute and price
limits. The work is read-only apart from explicit Category
assignment administration and the additive schema field. It creates no Product,
attribute-value or supplier writes, uses no supplier-derived controls, and
changes no Product Workflow, public visibility, sitemap/feed, supplier import
or Catalog Sync behavior. Phase 9C.9 final manual staging verification remains
pending.

Phase 9C.10.2 Preserve Attribute Filter Facets During Active Price Filtering is
complete locally. It fixes the confirmed staging regression by separating the
Product result, attribute-facet discovery and price-facet discovery scopes.
Price bounds continue to narrow Product results, but no longer collapse useful
attribute groups or options; active attribute filters still constrain Product
results without narrowing attribute discovery metadata.

The API and URL contracts remain unchanged. The hotfix adds no option counts,
cache, search indexing, Product or supplier mutation, supplier-derived filters,
Product Workflow change, public-visibility change or Catalog Sync behavior
change. Phase 9C.10.1 and its facet-preservation follow-ups have completed
staging verification. Phase 9C.9 final manual staging verification remains
separately pending.

Phase 9C.10.3 Preserve Price Facet Across Active Attribute Filters is complete
locally. It fixes the confirmed staging regression where a valid attribute
selection narrowed the price discovery scope to one Product and hid the price
slider. Product results still apply every active attribute and price filter,
while price metadata now excludes `price_min`, `price_max` and
`attribute_filters` and retains Category, Brand, search, availability and all
other established non-attribute listing context.

Equal-price and empty base scopes continue to hide the price slider. The API,
URL and frontend contracts remain unchanged. The hotfix adds no cache,
Product or supplier mutation, supplier-derived filters, Product Workflow,
public-visibility, supplier import or Catalog Sync behavior change. Phases
9C.10.1 through 9C.10.3 have completed staging verification. Phase 9C.9 final
manual staging verification remains separately pending.

Phase 9C.10.4 Fix Category Listing Component Resolution is complete locally.
The Category API and pagination payload were correct, but `/c/{slug}` used
unresolved short component names and therefore hid the Product card and shared
listing controls. The page now uses the registered directory-prefixed Nuxt
components for breadcrumbs, loading/error states, sorting, Product grid, empty
state and pagination, matching the working general catalog convention.

This frontend-only correction changes no Category query or direct-assignment
scope and does not aggregate child-Category Products. It adds no backend,
database, Product, Category or supplier mutation and changes no filter/facet,
public-visibility, supplier import or Catalog Sync behavior. Phases 9C.10.1
through 9C.10.4 have completed staging verification. Phase 9C.9 final manual
staging verification remains separately pending.

Phase 9C.11 Public Category Tree and Category Governance Audit is complete
locally. `/categories` now presents the full active recursive Category response
as root cards with nested semantic descendant links and calculates its visible
root, total and depth summary from that response. Duplicate or cyclic payload
nodes fail closed. The exact `/c/{slug}` Product scope remains unchanged and
does not aggregate descendant Products.

The new Filament audit reads current database state directly and reports
hierarchy reachability, path/depth, direct and published Product coverage,
subtree coverage, deterministic naming/order/localization observations and
Bulgarian manual recommendations. Critical issues are cycles, missing parents,
duplicate slugs and missing names/slugs. Warnings cover unreachable or
parent-status problems, normalized-name duplicates, suspicious punctuation and
trees without published Products. Ordering, direct coverage and Bulgarian-name
advisories remain informational.

The audit has no mutation actions, persistent cache or audit storage. It does
not change Categories, Products, Category templates, `supplier_products`,
supplier mappings, public visibility, Product Workflow, supplier import or
Catalog Sync behavior. It does not create supplier-derived Categories or
perform automatic remediation. Phase 9C.11 is merged, deployed and staging
verified. Recursive Category tree verification and Category Governance Audit
verification passed. Phase 9C.9 final manual staging verification remains
separately pending.

Commerce Phase 1A Cart Architecture, Safety and Gap Audit is complete locally.
It records the current Laravel and Nuxt Cart architecture, 26 open findings, an
endpoint and schema review, the existing test inventory, and proposed scopes
for Commerce Phases 1B through 1D. It changed no Cart, checkout, Order, Product,
payment, shipping, promotion, bundle, frontend or Catalog Sync implementation.

Commerce Phase 1B must not start until the Phase 1A findings are reviewed and
approved. Its proposed scope is backend identity, ownership, lifecycle,
pricing, eligibility, stock, concurrency, promotions and recovery safety.
Commerce Phase 1C is proposed for persistent single-source storefront state,
error/loading UX, currency, multi-tab and browser coverage. Commerce Phase 1D
is proposed as the checkout, idempotency, payment, shipping, stock and release
gate. Phases 1B, 1C and 1D have not started.

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
9. Review filtered-URL indexing policy separately; Phase 9C.10 does not change it.
10. Keep storefront specifications catalog-owned and read-only.
11. Rollback tooling based on `catalog_sync_batches` and `catalog_sync_logs`.
12. Keep feature flags locked down before broader sync work.
13. Conflict/manual mapping workflow.
14. Sync All later.
15. Automatic sync later.
16. Nuxt i18n route integration and localized sitemap expansion.
17. Data enrichment workflow refinements after queue usage is observed.

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
