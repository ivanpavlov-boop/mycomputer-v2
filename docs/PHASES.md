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
| Product Data Quality 2A | Unified Product edit quality summary | Complete locally; read-only warning presentation combining scanner issues, specification quality and active manual flags. |
| Product Data Quality 2B | Category and brand quality workflow | Complete locally; read-only queue triage, filters, counts and Product edit summary state with manual remediation through the existing form. |
| Product Data Quality 2C | Image and ALT-text quality workflow | Complete locally; read-only image metadata states, queue triage, counts and Product edit summary with manual remediation through existing image controls. |
| Product Data Quality 2D | SEO and description quality workflow | Complete locally; read-only SEO, Bulgarian description and English-localization completeness states, queue triage, counts and Product edit summary with manual remediation through existing fields. |
| Product Data Quality 2E | Category-template and specification completion | Complete locally; shared read-only template inheritance resolution, exact specification completion states, queue/category triage and Product edit summary with manual remediation through existing editors. |
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
| Phase 9C.9 | Storefront specification display | Complete locally; read-only Product detail presentation of valid catalog-owned values from the effective Category template. |
| Phase 9C.6 | Multi-supplier import discovery foundation | Complete |
| Phase 9C.6.1 | Supplier Import Capability Audit | Complete |
| Phase 9C.6.2 | Supplier Configuration Safety Cleanup | Complete |
| Phase 9C.6.3 | Add Next Supplier Staging Import, Preview Only | Complete |
| Phase 9C.6.4 | Controlled Supplier Staging Import Apply for One Supplier | Complete |
| Phase 9C.6.4.1 | ASBIS Dual-Feed Local Preview and Join | Complete |
| Phase 9C.6.4.1c | ASBIS Full-File Streaming Preview and Apply Readiness Audit | Complete; read-only streaming audit with source fingerprints and no apply mode. |
| Phase 9C.6.4.1d | ASBIS Audit Consistency and Missing-Key Safety | Complete; canonical identifier overlaps, missing-key blockers and reconciliation remain read-only. |
| Phase 9C.6.4.2 | Controlled ASBIS Dual-Feed Staging Apply | Complete; merged dry-run-first, false-by-default, create-only `supplier_products` staging path. Initial attempts rolled back safely; a later controlled v2 apply was reported successful with staging-only, unlinked ASBIS rows. |
| Phase 9C.6.4.2a | ASBIS MySQL Apply Compatibility and Safe Transaction Diagnostics | Complete; canonical v2 payload validation, Unicode-safe name compatibility, canonical `new` status, and safe rollback diagnostics. |
| Phase 9C.6.4.2.1 | ASBIS Post-Apply Verification and Reconciliation Audit | Complete in production on 2026-07-11; verdict `verified`, candidate count 4,844, ASBIS staged count 4,844, total `supplier_products` 6,717, linked ASBIS products 0, and `records_changed=0`. |
| Phase 9C.6.5A | Reusable Supplier Onboarding Framework Discovery & Contracts | Complete and merged; immutable contracts, DTOs, pure normalizers, fingerprints, preview/staging/verification structures, tests, and documentation only. |
| Phase 9C.6.5B | Multi-Supplier Readiness Matrix | Complete locally; read-only machine-readable supplier readiness audit with no production matrix run, feed request, import, write, Catalog Sync, job, or schedule action. |
| Phase 9C.6.5C | APCOM Supplier #1 Legacy Integration Audit & Normalization Discovery | Complete; read-only discovery tooling and the approved deterministic closeout are documented. No re-import, link repair, mapping approval, or Catalog Sync. |
| Phase 9C.6.5C.1 | Controlled Supplier Schedule Freeze for Deterministic Audit | Complete; one guarded `suppliers.schedule_enabled: true -> false` change, with no import, job, Catalog Sync, or protected-table write. |
| Phase 9C.6.5C.2 | APCOM Deterministic Audit Closeout | Complete; read-only pre/post comparison passed with no blockers and two documented warnings. |
| Phase 9C.6.5C.3 | APCOM Local Source Profile and Normalization Plan | Complete and deployed; operationally profiled read-only with no writes or persisted configuration. The source and report remain outside Git; human review remains required. |
| Phase 9C.6.5C.3A | APCOM Official Field Semantics and Hashed Source-to-Staging Reconciliation | Complete/deployed; read-only `apcom-official-v1` tooling was operationally run and safely failed closed on observed non-binary stock values, with zero mutations. |
| Phase 9C.6.5C.3A.1 | APCOM Observed Stock Semantics Discrepancy Handling | Complete and deployed; additive `apcom-observed-stock-v1` keeps numeric stock unresolved while allowing read-only SKU/EAN diagnostics. |
| Phase 9C.6.5C.3A.2 | APCOM Reconciliation Review and Operational Closeout | Complete by documentation closeout; observed-profile reconciliation completed read-only with zero mutations, unresolved stock semantics, and a pending human-decision gate. |

## In Progress

| Phase | Name | Status |
| --- | --- | --- |
| Phase 9C.6.5C.3D | Missing Supplier Offer Lifecycle and Catalog Archival Policy Preview | Tooling implemented locally/in review. Operational lifecycle preview has not run; offer deactivation/reactivation, storefront visibility, sitemap/noindex behavior, retention cleanup, and persistence remain unimplemented. Approval gate is blocked. |

## Completed Documentation Closeout

| Phase | Name | Status |
| --- | --- | --- |
| Phase 9C.6.5C.3B | APCOM Human Decision Register and Preview-only Feed Profile Design | Tooling merged, deployed, and exercised read-only; strict operational contract passed. Human decisions remain pending and no import, persistence, schedule, or Catalog Sync action is approved. |
| Phase 9C.6.5C.3B.1 | APCOM Preview-only Feed Profile Operational Closeout | Completed by documentation and documentation-contract tests; operational evidence is recorded without committing the source or runtime report. |
| Phase 9C.6.5C.3C | APCOM Authoritative Human Decision Evidence and Profile Approval Gate | Completed, merged, deployed, and verified. PR #152 merged at `b2b4fb95f1d2bfe2382fe6cab9a8462fa6f7e277`; CI #322 succeeded. VPS was synced and verified. The approval gate remains blocked for implementation approvals. APCOM schedule remains disabled; UPDATE, Sync All, and automatic sync remain disabled. |

## Next Pending Phase

| Phase | Name | Status |
| --- | --- | --- |
| Phase 9C.6.5C.3D | Missing Supplier Offer Lifecycle and Catalog Archival Policy Preview | Local synthetic policy tooling is in review only. No operational preview, source read, persistence, lifecycle write, storefront visibility, sitemap/noindex behavior, or retention cleanup is authorized. |

## Paused / Partial Phases

| Phase | Name | Status |
| --- | --- | --- |
| Phase 9C.5.8 | Power/Cables template based on internal taxonomy | Partial/paused intentionally after 6 approved mappings and 67 pending review mappings. Safety verified; broader review waits until all suppliers are staged and visible in discovery. |

## Next Planned Phases

| Phase | Name | Notes |
| --- | --- | --- |
| Phase 9C.6.5D | Supplier #3 Selection & Source Profiling | Future; requires a reviewed readiness matrix and explicit human selection. |
| Phase 9C.6.6 | Multi-Supplier Category Mapping Review | Review mappings in batches using the full multi-supplier picture. |
| Phase 9C.6.7 | Multi-Supplier Identifier Overlap Review | Review exact and possible overlaps before future offer grouping. |
| Phase 9C.7 | Supplier Attribute Mapping Foundation | Preview/planning foundation only until a later explicit approval/write phase. |
| Phase 9C.8 | Product specification data quality polish | Improve admin ergonomics after real queue usage. |
| Phase 9C.10 | Frontend attribute filters | Only after controlled data quality and approved product values. |
| Phase 9 | Rollback support | Required before broad writes. |
| Phase 10 | Manual Sync All eligible CREATE | Later, after stronger audit controls. |
| Phase 11 | Scheduled preview generation | Preview only before scheduled writes. |
| Phase 12 | Controlled automatic sync | Last, behind feature flags and rollback. |

## Product Data Quality 2A Scope

Product edit pages now include a unified Bulgarian quality summary that
combines the existing `ProductDataQualityScanner`, category-driven
`ProductSpecificationQualityService` result and active manual quality flags.
The summary is computed during edit-page rendering and is advisory only: it
does not submit form state, block Product saves or workflow transitions,
assign or resolve flags, remediate data, or write Product, supplier, image,
category, attribute or specification records. It does not change Catalog Sync
or permit supplier data to overwrite catalog-owned content.

Later enrichment and storefront work remains separately scoped and
unimplemented.

## Product Data Quality 2B Scope

The Product Data Quality Queue now presents the assigned catalog Category,
Category parent path when available, assigned Brand and one deterministic
combined state: complete, missing Category, missing Brand or missing both.
The queue adds one non-overlapping state filter, searchable specific Category
and Brand filters, and read-only overview counts based on the existing queue
eligibility scope. Assigned inactive or soft-deleted Category and Brand records
remain visible as warnings using their existing domain state.

The unified Product edit quality summary presents the same Category and Brand
state. Correction remains an explicit manual edit through the existing Product
form and its existing validation and authorization. There is no inline or bulk
assignment, automatic categorization, automatic Brand detection, supplier-data
suggestion, automatic remediation, flag resolution, Product mutation during
evaluation, workflow gate, visibility change or Catalog Sync behavior change.

## Product Data Quality 2C Scope

The Product Data Quality Queue and unified Product edit quality summary now
present one deterministic image metadata state: no images, multiple primary
images, missing primary image, all ALT text missing, some ALT text missing or
complete. State priority follows that order, and the queue exposes image count,
primary-image status, ALT coverage, one exact state filter and bounded read-only
counts within the existing queue eligibility scope. Soft-deleted image rows are
excluded by the existing Product image relation.

ALT quality in this phase means only whether the existing plain-text metadata
is present; it does not claim semantic accuracy. Correction remains manual
through the existing Product image repeater and its current upload, deletion,
ordering, primary toggle, ALT field, validation and authorization behavior.
Evaluation performs no database, filesystem or network mutation. There is no
AI, OCR, image recognition, automatic ALT generation, automatic primary-image
selection, supplier image import, remote image check, workflow gate, public
visibility change or Catalog Sync behavior change.

## Product Data Quality 2D Scope

The Product Data Quality Queue and unified Product edit quality summary now
present one deterministic SEO and content state: both Bulgarian descriptions
missing, full description missing, short description missing, both SEO fields
missing, one SEO field missing, weak description, incomplete English
localization or complete. State priority follows that order. The queue exposes
compact SEO `2/2`, Bulgarian-description `2/2` and English-localization `3/3`
scores, one exact combined state filter and bounded read-only counts within the
existing queue eligibility scope.

The English denominator reuses the existing scanner contract: English name,
full description and SEO title. English short description and SEO description
remain available optional form fields and are not silently made required. The
existing `missing_seo`, `weak_description` and `missing_en_translation` scanner
issues remain authoritative for unified issue totals, so detailed field-level
presentation does not double-count them. The established weak-description
thresholds remain unchanged.

Correction remains manual through the existing Product SEO, Bulgarian rich-text
description and English localization fields under their current validation and
authorization. Evaluation normalizes whitespace and empty rich-editor wrappers
in memory only. It does not rewrite stored HTML or localization JSON, mutate
Products, call external services, use supplier content, generate SEO or
descriptions, translate text, detect language, assign or resolve quality flags,
block Product saves or workflow transitions, or alter public visibility, public
SEO, supplier imports or Catalog Sync. Semantic language correctness and
editorial translation quality remain manual multilingual-content concerns.

## Product Data Quality 2E Scope

Category-template coverage and Product specification completion now use one
shared, read-only resolver for direct, inherited and missing templates. Child
category assignments take precedence over duplicate ancestor assignments. The
existing `ProductSpecificationQualityService` remains authoritative for value
validation and now reports separate required, recommended and total scores,
missing and invalid values, and the four exact states `missing_required`,
`needs_data`, `no_category_template` and `good`.

The Product Data Quality Queue exposes compact template-source and completion
columns, one exact specification-state filter, tooltips for missing or invalid
values, and bounded state counts within the existing queue scope. Category
administration exposes template coverage and assignment counts and links to the
existing Category Product Attribute editor. The Product edit summary presents
the same source, scores and issue details. No inline or bulk assignment, value
editor or automatic category/template inference was added.

All evaluation and navigation in this phase is advisory and read-only.
Correction remains a deliberate action through the existing Product
specification and Category Product Attribute editors under their existing
authorization. Evaluation does not create, update, delete, attach or detach
Products, categories, templates, attributes, values, quality flags or supplier
records. It does not block Product saves or workflow transitions, change public
visibility, import images, overwrite category or attribute ownership, or alter
supplier import or Catalog Sync behavior.

## Phase 9C.9 Scope

The public Product detail API now presents grouped specifications from
catalog-owned `product_attribute_values` only. It reuses the shared effective
Category-template resolver and established Product specification value
validation semantics, including direct/inherited template precedence and
duplicate resolution. Only active, visible, valid and non-empty manual catalog
values are displayed. Missing, malformed, out-of-template, inactive,
supplier-derived and legacy reference-only values are omitted.

The Product detail response exposes deterministic customer-facing groups and
items with localized labels and formatted text, numeric, boolean, select,
multiselect, JSON-backed and unit-bearing values. It does not expose internal
quality states, required/recommended flags, template source, supplier metadata,
workflow data, audit data or raw option IDs. Legacy `products.specifications`
remains unchanged and is not added to the public Product detail contract.

Nuxt uses the existing Product detail request and Description/Characteristics
tabs. Characteristics is hidden when no displayable values exist. The new
semantic definition-list presentation is Bulgarian-first, responsive,
accessible and uses normal Vue escaping. This phase creates no Product,
attribute, value or Category-template writes, changes no Product Workflow or
public visibility rule, and changes no Catalog Sync behavior. Phase 9C.10
frontend attribute filters remains separate and unimplemented.

## Phase 9C.6.5A and 9C.6.5B Implemented Scope

This phase is discovery and contract design only. The following local
components are implemented without production wiring:

- reusable normalized supplier payload contract;
- supplier feed driver interface;
- versioned supplier feed profile contract;
- source fingerprint and candidate fingerprint contracts;
- preview/report contract;
- create-only `supplier_products` staging plan contract with updates disabled;
- post-apply verification contract;
- availability and pricing normalization contracts; and
- validation issue/classification value objects.

No generic XML/CSV/JSON driver, custom API adapter, staging apply, supplier
registration, production binding, or onboarding command was added. The
framework remains read-only and local to the contract boundary.

Phase 9C.6.5B adds a read-only multi-supplier readiness matrix. It reports
safe configuration presence, staged-row/mapping counts, capability evidence,
readiness stages, deterministic diagnostic scores, blockers, next safe actions,
effective Catalog Sync flags, and zero protected-table mutation counters. It
does not fetch remote sources, run a preview/import/apply, write any data, call
Catalog Sync, dispatch jobs, change schedules, or select a new supplier.

No supplier #2 has been selected, profiled, or imported under these phases.

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
- Use `suppliers:audit-asbis-post-apply-verification` for read-only post-apply reconciliation. It reconstructs the v2 candidate set and compares source fingerprints, normalized SKUs, canonical rows, raw-data provenance, truncation metadata, availability, pricing, staging counts and protected-table invariants. It has no repair/apply/sync mode and never exposes paths or raw payloads. The 2026-07-11 production verification completed with verdict `verified`; future runs remain separately approved and read-only.
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
