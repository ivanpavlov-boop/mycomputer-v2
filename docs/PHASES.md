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
| Commerce Phase 1A | Cart Architecture, Safety and Gap Audit | Complete locally; read-only architecture report, machine-readable gap register and phased remediation plan. No Cart or checkout implementation changed. |
| Commerce Phase 1B.1 | Unified Cart Identity and Ownership Boundary | Complete locally; shared request-level UUID and ownership resolver, atomic anonymous-Cart claim, checkout cross-user rejection and session-authorized shipping subtotal. |
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
| Phase 9C.10 | Frontend attribute filters | Complete locally; read-only catalog-owned attribute filters with stable URL semantics. |
| Phase 9C.10.1 | Configurable filter controls and range slider UX | Complete locally; per-Category controls plus scoped public price sliders without filtering-semantic changes. |
| Phase 9C.10.2 | Preserve attribute filter facets during active price filtering | Complete locally; independent result, attribute-facet and price-facet scopes keep useful filters stable. |
| Phase 9C.10.3 | Preserve price facet across active attribute filters | Complete locally; price discovery keeps non-attribute listing context while active attributes continue to constrain Product results. |
| Phase 9C.10.4 | Fix Category listing component resolution | Complete locally; the Category page uses the registered directory-prefixed Nuxt components without changing listing scope or data behavior. |
| Phase 9C.11 | Public Category tree and Category governance audit | Merged, deployed and staging verified; recursive Category tree and Category Governance Audit verification passed. |
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
frontend attribute filters remains separate. Final manual Phase 9C.9 staging
verification with a published Product that has a populated effective Category
template is still pending and is not completed by Phase 9C.10.

## Phase 9C.10 Scope

The existing public Product and Category Product listings now expose optional,
read-only attribute filter metadata derived only from publicly visible Products,
manual catalog-owned Product attribute values and each Product's effective
direct or inherited Category template. An attribute must be active, visible and
explicitly filterable on both its definition and effective Category assignment.
The initial supported types are select, multiselect, boolean, number and decimal;
free text, JSON and other unbounded or unsupported types are omitted.

The stable query contract uses catalog attribute codes and option slugs:
`attribute_filters[ram][]=16-gb`, `attribute_filters[wifi][]=yes` and
`attribute_filters[weight][min]=1`. Multiple values for one attribute use OR;
different attributes use AND; numeric bounds are inclusive. Unknown attributes,
options, operators, malformed structures and inverted numeric ranges are rejected
without accepting arbitrary columns, operators or JSON paths. Selection and
metadata generation preserve the existing Category, Brand, price, availability,
search, sorting, pagination and public-visibility behavior.

Nuxt reads selected filters from the URL during SSR, sends them in the existing
listing request, and preserves them across reload, sorting, pagination and browser
navigation. Desktop and mobile controls, removable Bulgarian chips, group/all
clearing and empty/error recovery use the existing responsive storefront. Filter
changes reset only pagination. Filter labels and values use normal escaped Vue
interpolation. Per-option counts are intentionally omitted in this phase because
accurate self-excluding counts were not required for the bounded implementation;
the UI displays counts only when a future API supplies accurate values.

Filter evaluation performs no writes and creates no persistent facet cache. It
does not use supplier attributes, `supplier_products`, source payloads, internal
quality/template metadata or supplier metadata. It does not mutate Products,
Categories, templates, attributes or values, change Product Workflow, public
visibility, sitemap/feed/canonical policy, supplier import or Catalog Sync.

## Phase 9C.10.1 Scope

Category-to-Product-Attribute assignments now store one additive
`filter_control_type` value: `auto`, `options`, `yes_no`, `range_slider` or
`min_max`. Existing rows default to `auto`, preserving the Phase 9C.10 display:
select and multiselect use options, boolean uses yes/no, and numeric attributes
use min/max fields. Compatibility is enforced server-side and unsupported
attribute types remain unavailable as public filters.

The existing Category template resolver remains authoritative. Direct child
assignments override inherited controls, while `is_filterable=false` still
removes a filter. Category-specific listings use their effective assignment.
For broad catalog scopes, matching controls are retained; a numeric mix of
`range_slider` and `min_max` deterministically falls back to `min_max`; any
other incompatible mix is omitted.

The public API preserves semantic `type` values and the existing query keys,
and adds only the presentation-level `control`. Nuxt renders escaped options,
accessible yes/no choices, validated min/max inputs and a native dual-handle
range slider. The public price slider uses the existing inclusive `price_min`
and `price_max` semantics. Its bounds use only the current public endpoint
non-attribute scope and exclude the active price limits and Phase 9C.10
`attribute_filters`. Price, attribute-group and price-only clear actions are
explicit.

This phase performs no Product, Product Attribute Value, Category template,
supplier or `supplier_products` mutation. It adds no supplier-derived controls,
persistent facet cache or dependency, and changes no Product Workflow, public
visibility, sitemap/feed policy, supplier import or Catalog Sync behavior.
Phase 9C.9 final manual staging verification remains pending.

## Phase 9C.10.2 Scope

Phase 9C.10.2 corrects the confirmed staging regression where applying
`price_min` or `price_max` narrowed the Product results correctly but also
collapsed otherwise useful attribute filter groups. Product, Category Product
and Brand Product listings now use three explicit independent query scopes:
the result scope applies all active filters, the attribute-facet scope excludes
price bounds and active attribute selections, and the price-facet scope remains
separate from result pagination and sorting.

Useful-filter eligibility remains unchanged and is evaluated against the
unpriced attribute discovery scope. No per-option counts, persistent facet
cache, search index or new API/URL contract was added. The hotfix performs no
Product or supplier mutation, uses no supplier-derived values, and changes no
Product Workflow, public visibility, supplier import or Catalog Sync behavior.
Phase 9C.10.1 and its facet-preservation follow-ups have completed staging
verification. Phase 9C.9 manual staging verification remains separately
pending.

## Phase 9C.10.3 Scope

Phase 9C.10.3 corrects the confirmed staging regression where an active
`attribute_filters` selection could leave one Product, produce equal price
bounds and hide the otherwise useful public price slider. Product, Category
Product and Brand Product listings now calculate price metadata from the
broader non-attribute discovery scope: active `price_min`, `price_max` and
`attribute_filters` are excluded, while endpoint hard scope, Category, Brand,
search, stock, availability and the established non-attribute listing context
remain authoritative.

The Product result query still applies every active attribute and price filter.
The attribute-facet behavior from Phase 9C.10.2 is unchanged. Equal-price or
empty base discovery scopes still return `price_filter=null`; no slider is
forced where it is not useful. The API and URL contracts are unchanged, and
the correction adds no cache, Product or supplier mutation, supplier-derived
metadata, Product Workflow, public-visibility, supplier import or Catalog Sync
behavior change. Phases 9C.10.1 through 9C.10.3 have completed staging
verification. Phase 9C.9 manual staging verification remains separately
pending.

## Phase 9C.10.4 Scope

Phase 9C.10.4 corrects the confirmed Category-page frontend rendering defect
where the API and pagination metadata contained the expected Product, but the
Product card and shared listing controls were absent. The Category page used
unresolved short component names; it now uses the directory-prefixed Nuxt
auto-import names already established by the working general catalog page for
breadcrumbs, loading and error states, sorting, Product grid, empty state and
pagination.

The correction changes no API, URL, Category query or direct-assignment scope.
It does not aggregate Products from child Categories, mutate Products or
Categories, change filter/facet semantics, alter public visibility, add a
backend or database change, or change supplier import or Catalog Sync behavior.
Phases 9C.10.1 through 9C.10.4 have completed staging verification. Phase
9C.9 manual staging verification remains separately pending.

## Phase 9C.11 Scope

Phase 9C.11 renders every active Category returned by the established recursive
navigation endpoint on `/categories`. Root Categories remain the visual groups;
all valid descendants are presented as nested semantic lists with relative
`/c/{slug}` links. Root count, total visible Category count and maximum depth
are calculated from the response. Duplicate or cyclic payload nodes fail
closed without a hardcoded depth limit.

The existing `/c/{slug}` Product query remains exact to the selected
`category_id`. Phase 9C.11 does not aggregate descendant Products or change
Product public visibility.

The Filament Category Governance Audit is read-only and calculates current
hierarchy, direct Product, published direct Product and published subtree
coverage in bounded queries. It reports hierarchy, naming, localization,
ordering and Product-coverage observations with the following stable severity
mapping:

- `critical`: `cycle`, `orphan_parent`, `duplicate_slug`, `missing_slug`,
  `missing_name`;
- `warning`: `unreachable_from_root`, `active_under_inactive_parent`,
  `active_under_deleted_parent`, `duplicate_normalized_name`,
  `suspicious_name_punctuation`, `no_published_products_in_subtree`;
- `info`: `zero_sort_order`, `sibling_sort_order_collision`,
  `no_direct_products`, `no_published_direct_products`,
  `missing_explicit_bg_translation`, `possible_latin_only_public_name`.

Recommendations are Bulgarian manual guidance only. The audit does not rename,
translate, move, reorder, activate, deactivate, create, merge, delete or restore
Categories; assign Products; modify Category templates; persist audit results;
derive public Categories from suppliers; or mutate Products,
`supplier_products`, supplier records or Catalog Sync state. Phase 9C.11 is
merged, deployed and staging verified. Recursive Category tree verification
and Category Governance Audit verification passed. Phase 9C.9 manual staging
verification remains separately pending.

## Commerce Phase 1A Scope

Commerce Phase 1A is complete locally as a read-only Cart architecture and
safety audit. The authoritative report is
[`CART_ARCHITECTURE_SAFETY_AUDIT.md`](CART_ARCHITECTURE_SAFETY_AUDIT.md), with
machine-readable open findings in
[`CART_GAP_REGISTER.json`](CART_GAP_REGISTER.json).

The audit covers Laravel and Nuxt Cart identity, guest and authenticated
lifecycle, Product eligibility, pricing, stock, bundles, promotions, recovery,
checkout, orders, payment and shipping boundaries, concurrency, idempotency,
schema constraints and existing tests. No Cart, checkout, Order, Product,
payment, shipping, promotion, bundle, frontend or Catalog Sync implementation
changed.

Commerce Phase 1B must not start until the Phase 1A findings and proposed
ownership, lifecycle and pricing contracts are reviewed and approved. Commerce
Phase 1C remains the proposed Cart storefront UX phase, and Commerce Phase 1D
remains the proposed checkout readiness and release-gate phase. None of Phases
1B through 1D has started.

## Commerce Phase 1B.1 Scope

Commerce Phase 1B is split into controlled subphases. Phase 1B.1 is complete
locally and adds one shared request-level Cart resolver across regular Cart,
bundle Cart, checkout, shipping, authenticated Cart quote and PC Builder
add-to-Cart operations.

The resolver enforces canonical lowercase UUID Cart sessions, rejects malformed
non-empty values before database access, applies one guest/authenticated
ownership matrix and claims an anonymous Cart under a row lock with a
post-lock ownership check. Checkout now rejects foreign ownership before any
checkout side effect. Shipping uses the resolved Cart session as authority and
treats `cart_id` only as an optional matching assertion.

CART-001 and CART-022 are complete locally. CART-017 is only partially
remediated for session validation; dedicated throttling remains open. CART-003
Cart merge, lifecycle/expiry, pricing, promotion concurrency, recovery and
checkout idempotency remain unchanged and open. Public commerce page routes
remain disabled. No migration, frontend production, Product, supplier or
Catalog Sync behavior changed.

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
- Maintain Product Attributes as an internal catalog-owned foundation. Phase 9C.10 permits only read-only storefront filters over approved catalog-owned values; supplier attribute mapping still requires a later explicit phase.
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
