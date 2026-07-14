# APCOM Local Source Normalization Plan

## Status

Phase 9C.6.5C.3 is a generic, strictly read-only local-source normalization
planner. It is immediately usable for APCOM as Supplier #1. An explicitly
authorized local C.3 profiler run has completed, but its operational report,
source file, source path, and report checksum are intentionally not stored in
Git. The run created no configuration and changed no records.

The tool does not approve an import, a feed profile, mappings, matching, or
Catalog Sync. Every result requires human review.

## Command

```powershell
.\.tools\php\php.exe artisan suppliers:plan-local-source-normalization `
  --supplier=apcom `
  --source=<explicit-local-xml-path> `
  --source-format=xml `
  --expected-sha256=<local-file-sha256> `
  --full-file `
  --expected-supplier-id=<captured-supplier-id> `
  --expected-schedule-enabled=false `
  --expected-import-enabled=true `
  --expected-schedule-type=<captured-schedule-type> `
  --expected-staged-count=<captured-staged-count> `
  --expected-linked-count=<captured-linked-count> `
  --expected-unlinked-count=<captured-unlinked-count> `
  --expected-last-import-at=<captured-last-import-at> `
  --output=json
```

Only an explicitly supplied local XML file is accepted. HTTP, HTTPS, FTP,
stream wrappers, directories, symlinks, malformed XML, unsupported formats,
and SHA-256 mismatches fail safely. The command outputs only field paths,
counts, bounded diagnostics, hashes, and policy proposals. It never emits raw
source values, image URLs, credentials, or production source paths.

## Baseline Locks And Refusal Conditions

Before profiling, the command requires and rechecks the supplier ID, schedule
enabled state, import enabled state, schedule type, staged count, linked count,
unlinked count, and last-import timestamp. Supplier resolution must be exactly
one existing supplier; missing or ambiguous identifiers fail safely.

The command refuses when:

- the supplied baseline does not match current supplier or staging state;
- `schedule_enabled` is not `false`;
- an import run or import job is active or has an unknown status;
- CREATE is not enabled, or UPDATE, Sync All, or automatic sync is enabled;
- the local source is invalid, changes during profiling, or does not match its
  SHA-256 fingerprint;
- negative or non-numeric price diagnostics are found; or
- any protected-table count or fingerprint changes while the command runs.

The source record count is compared with existing `supplier_products` for the
selected supplier. A difference greater than 20 percent is a review warning,
never a reason to reconcile or modify data. Field roles with profiler
confidence below `0.8` also require review.

## Proposed Normalization Policy

The plan is a proposal only. It does not create an executable import
configuration or persist a feed profile.

- Supplier SKU: trim Unicode whitespace, normalize NFC where available,
  preserve the source semantics, and never invent or silently truncate a value.
- EAN/GTIN: trim whitespace, preserve leading zeroes, accept digits only, and
  report format and duplicate diagnostics without checksum correction.
- MPN: trim Unicode whitespace and normalize NFC, retain source case for
  storage, and use case-normalized comparisons only as diagnostics.
- Price: parse a locale-independent decimal without currency conversion, VAT
  inference, or silent rounding. Zero price needs review; negative or
  non-numeric values block the plan.
- Currency: propose uppercase ISO-code handling only. No default currency or
  conversion is inferred.
- Quantity and availability: propose safe integer parsing and a non-persisted
  availability mapping. Negative quantity blocks; missing/unknown values stay
  unresolved until approved.

## Non-Offer Ownership Boundaries

Supplier product name, brand, and description observations are metadata or
comparison inputs only. They do not overwrite catalog name, slug, SEO,
descriptions, localized content, workflow, categories, attributes, or media.

Category fields are candidate mapping inputs only. The planner records current
mapping counts but creates, approves, rejects, or applies no mapping. Attribute
interpretation is deferred to a separate reviewed phase. Image fields are
detected as paths only; no image URL is emitted, fetched, validated,
downloaded, attached, or imported.

## Collision Diagnostics

The report counts exact, case-normalized, and whitespace-normalized duplicate
groups for supplier SKU, EAN, and MPN. It also reports missing primary
identifiers and marks brand-plus-MPN as diagnostic only. It never links,
unlinks, merges, deletes, repairs, resolves duplicates, or changes a supplier
product or catalog product.

## Operational Sequence

After explicit approval for an operational local-file run only:

1. Confirm APCOM remains frozen and no import is active.
2. Capture fresh expected supplier/staging state and the local file SHA-256.
3. Run the planner against the explicitly supplied local file.
4. Review field coverage, normalization proposals, collision counts, and all
   warnings/blockers with a human reviewer.
5. Decide whether a separate feed-profile or controlled staging phase should
   be designed and approved.

No later phase is authorized by a successful plan. The `--apply` mode is
absent. The planner does not fetch feeds, write `supplier_products`, write
products, modify mappings or attributes, run Catalog Sync, dispatch jobs,
enable schedules, or download images.

## Official APCOM Semantics Follow-up

Phase 9C.6.5C.3A added the local-only
`suppliers:reconcile-local-source-staging` command and the versioned
`apcom-official-v1` semantics registry. Its first operational read-only run
failed closed on a real-source stock semantics discrepancy, without changing
records. `partno` remains the authoritative supplier SKU, normalized matching
and EAN remain diagnostics only, and MPN, quantity, currency, VAT, and DAC/FD
price selection remain unresolved.

Phase 9C.6.5C.3A.1 adds `apcom-observed-stock-v1` as a non-persistent,
unresolved numeric-stock review profile. It permits hashed SKU/EAN
reconciliation when numeric stock values are non-negative integers, but it
does not approve quantity or availability mapping, import, profile persistence,
or schedule re-enable. See [APCOM Observed Stock Semantics Discrepancy](APCOM_OBSERVED_STOCK_SEMANTICS_DISCREPANCY.md).
