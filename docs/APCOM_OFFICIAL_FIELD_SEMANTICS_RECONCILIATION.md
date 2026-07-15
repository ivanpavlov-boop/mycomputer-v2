# APCOM Official Field Semantics And Read-only Reconciliation

## Status

Phase 9C.6.5C.3A provides local, deterministic tooling for comparing an
explicit local APCOM XML source to existing APCOM `supplier_products` staging.
PR #147 tooling is merged and deployed. The first C.3A operational
reconciliation ran read-only and safely failed closed because the authorized
snapshot contained non-binary `stock` values. No records changed, no rollback
is required, and no operational report or XML source is stored in Git.

This document records the operator-confirmed `apcom-official-v1` semantics.
It is a read-only contract for review, not an executable supplier mapping or
an approval to import, link, update, delete, or sync anything.

## Current Safe Baseline

- APCOM is Supplier #1; ASBIS is Supplier #2. The next-supplier candidate is
  not selected by this phase.
- APCOM's current documented baseline is supplier ID `5`, active, with
  `schedule_enabled=false`, `import_enabled=true`, and schedule type
  `twice_daily`.
- The documented APCOM staging baseline is `1872` rows: `989` linked and
  `883` unlinked. The captured last-import timestamp is
  `2026-07-13T04:00:56.000000Z`.
- No active APCOM import run or import job was captured for the authorized
  local C.3 source profile.
- Catalog Sync remains safely configured: CREATE enabled, UPDATE disabled,
  Sync All disabled, and automatic sync disabled.

The reconciliation command requires this state to be passed explicitly and
refuses if the supplier baseline, schedule freeze, import activity, or Catalog
Sync flags do not match.

## Authorized C.3 Profile Facts

An explicitly authorized, local-only C.3 profiler run completed outside this
repository. The source basename was
`apcom-product-list-2026-07-14-11-07.xml` with SHA-256
`fef5e30eb5e16714014f3654fce34025a14a0fb22750bc01158163b2c14c9ac1`.

It reported `supplier-local-source-normalization-plan-v1`, success, and
`plan_requires_identifier_review`, with no blockers. The source root was
`xml`, record path `xml.product`, complete UTF-8 parsing, no malformed XML,
and `1803` source records. The source-to-staging net count delta was `-69`
(`-3.6859%`). A net count delta does **not** prove which specific products
were removed or added.

The C.3 report itself, its path, and its report checksum are operational
evidence and are intentionally not committed. That profiler run wrote no
records, created no configuration, and did not create or modify products,
staging rows, mappings, attributes, images, jobs, schedules, or Catalog Sync.

## `apcom-official-v1` Field Contract

Confirmed source semantics:

| Meaning | Official source field |
| --- | --- |
| Authoritative supplier SKU | `partno` |
| EAN/GTIN | `ean` |
| Product name observation | `name` |
| Brand observation | `manufacturer` |
| Supplier category observation | `category` |
| Binary stock status | `stock` |
| Lifecycle EOL indicator | `eol` |
| Promotion indicator | `promo` |
| New-item indicator | `news` |
| Image-path presence only | `images`, `images.image` |
| Customs code, not an identifier | `cncode` |
| Dimensions | `width`, `height`, `depth`, `weight` |
| Supplier grouping | `group` |
| Price candidates, selection unresolved | `dac_price`, `fd_price` |

Explicitly unresolved fields remain unresolved: MPN, quantity, currency, VAT,
and price selection between DAC and FD. `greentax` is documented but was
absent from the captured C.3 source snapshot.

The previous generic `quantity -> stock` heuristic is explicitly superseded by
this official semantics profile. It remains read-only historical context and
does not require rollback because the planner never wrote the inferred value.

The following inferences are prohibited:

- `stock` is never interpreted as quantity;
- `partno` is never treated as MPN;
- `cncode` is never used as an identifier;
- currency, VAT, DAC/FD selection, or greentax are never inferred;
- no image URL is emitted, fetched, validated, downloaded, or imported; and
- source name, brand, category, image, or description observations never
  overwrite catalog content.

## C3B Preview-only Design

The locally implemented C3B design references this contract and the additive
observed-stock review profile. It separates exact `partno` reconciliation from
diagnostic EAN, review-only EOL/zero-price candidates, and unresolved stock and
commercial semantics. It is non-persisted and non-executable; it cannot turn a
field observation into import, link, staging, catalog, image, or Catalog Sync
writes.

Stock/EOL review policy is intentionally non-executable. The official profile
continues to represent the published binary claim, but the first operational
run found a real-source discrepancy and stopped before reconciliation. EOL
remains a separate binary lifecycle review field. No profile auto-deactivates,
deletes, unpublishes, or changes a product.

## Read-only Command

```powershell
.\.tools\php\php.exe artisan suppliers:reconcile-local-source-staging `
  --supplier=apcom `
  --source=<explicit-local-xml-path> `
  --source-format=xml `
  --record-path=xml.product `
  --semantics-profile=apcom-official-v1 `
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

Only local regular XML files are accepted. HTTP(S), FTP, stream wrappers,
directories, symlinks, malformed XML, SHA mismatches, unknown semantics
profiles, state mismatches, active/unknown import activity, and unsafe Catalog
Sync flags fail safely. The command has no apply, persist, import, sync,
schedule, queue, fetch, download, or image option.

The report schema is `local-supplier-source-staging-reconciliation-v1`. It
contains aggregate counts, policy decisions, source/staging count-balance
checks, and bounded domain-separated SHA-256 sample hashes. It never contains
raw source values, product IDs, supplier-product IDs, source paths, URLs,
credentials, or full source records.

Exact normalized-safe `partno` to staging `supplier_sku` matching is the only
authoritative reconciliation rule. NFC/trim/case/whitespace comparison is
diagnostic only. EAN is diagnostic only. Neither can automatically link,
unlink, merge, repair, or modify staging or catalog data.

## Captured Aggregate Semantics

The authorized C.3 profile recorded only these safe aggregate observations:

- `partno`: all `1803` nonblank, exactly unique and normalized unique, maximum
  length `31`;
- `ean`: `1691` nonblank, `112` blank, unique, maximum length `13`;
- `cncode`: all `1803` nonblank, `64` distinct values, duplicates expected,
  and never an identifier;
- both price candidates numeric for `1803` records: `72` zero and `1731`
  positive; `912` equal; DAC higher for `891`; DAC lower for `0`;
- `stock`: numeric for `1803`, with `982` zero and `821` positive; and
- all confirmed fields were present except documented `greentax`.

These aggregates are review evidence only. They do not select a price, assign
currency/VAT, create mappings, alter availability, or enable any import or
Catalog Sync behavior.

## Release And Safety Boundary

The command, registry, profile, and synthetic fixtures are local tooling only.
There is no persistent semantics-profile table, migration, real-source
fixture, production report, real source access, database write, remote fetch,
job dispatch, schedule change, import execution, Catalog Sync call, or
product/catalog mutation in this phase.

`apcom-official-v1` supersedes generic profiler guesses only for the
read-only C.3A report. It does not rewrite, roll back, or reinterpret historic
staging data. Phase 9C.6.5C.3A.1 adds an observed numeric-stock review profile
without weakening this strict official profile. Its authorized operational
reconciliation completed read-only with unresolved stock semantics and no
mutations. See [APCOM Observed Stock Semantics Discrepancy](APCOM_OBSERVED_STOCK_SEMANTICS_DISCREPANCY.md)
and [APCOM Reconciliation Review and Operational Closeout](APCOM_RECONCILIATION_REVIEW_CLOSEOUT.md).

## Current Operational Closeout Addendum

The operational preview and strict contract followed this historical
reconciliation. The result remained read-only, with source/staging evidence
and unresolved commercial semantics documented in
APCOM_PREVIEW_ONLY_FEED_PROFILE_OPERATIONAL_CLOSEOUT.md.
