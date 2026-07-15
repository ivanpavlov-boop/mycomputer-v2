# APCOM Observed Stock Semantics Discrepancy

## Status

- PR #147 tooling is merged and deployed.
- The first operational official-semantics reconciliation ran read-only.
- It safely failed closed with `invalid_stock_semantics_detected`.
- No records were changed and no rollback is required.
- Phase 9C.6.5C.3A.1 tooling is completed and deployed.
- The observed-profile operational reconciliation completed read-only with
  `reconciliation_requires_stock_semantics_review`.

This document records operator-supplied aggregate evidence and the resulting
operational closeout facts. It does not load a runtime report, a production
database, or a real APCOM XML file.

## Published Semantics

APCOM's published documentation describes `stock=1` as in stock and `stock=0`
as out of stock. The immutable `apcom-official-v1` profile continues to record
that published binary claim and to reject values outside `0` and `1`.

The strict profile was correct for the published documentation. It is not
rewritten or weakened by this follow-up.

## Observed Source Evidence

The authorized snapshot was reported as aggregate-only evidence:

- total records: `1803`
- `stock=0`: `982`
- `stock=1`: `30`
- `stock>1`: `791`
- distinct numeric values: `90`
- minimum numeric value: `0`
- maximum numeric value: `100`
- all values were non-negative integers

The source is therefore not binary in that snapshot. EOL remains separate and binary: `1700` rows were `eol=0`, `103` were `eol=1`, and no EOL values were blank or invalid.

## Decision

`apcom-observed-stock-v1` is a non-persistent, versioned review contract. It
records `stock` as `observed_numeric_stock_value` with an unresolved semantic
status. It accepts only present, numeric, integer, non-negative values.

It does not approve any of these interpretations:

- `stock` as quantity
- `stock` as binary availability
- `stock > 0` as available
- `stock = 0` as unavailable
- any catalog stock or catalog availability update

Quantity, availability, MPN, selected price, currency, VAT, and green-tax
handling remain unresolved. `eol` remains a separate binary lifecycle flag.

## Strict Profile Preservation

`apcom-official-v1` remains available and strict. It blocks non-binary stock
values, preserving the historical accuracy of the first failed-closed report.

The observed profile is additive. It permits source-to-staging SKU and EAN
diagnostics to continue only when the observed numeric contract is valid; it
does not approve a stock interpretation or an import design.

## Failed Operational Report

The prior read-only strict-profile report is referenced as operator-supplied
metadata only:

- path: `storage/app/imports/apcom-audit/reports/apcom_official_reconciliation_20260714T141523Z.json`
- SHA-256: `86de4ce6d79093954c22eaccfb5ac063d7168cfaf2d0c2228af5fce13baae2cc`
- verdict: `audit_failed`
- blocker: `invalid_stock_semantics_detected`
- protected mutation counters: all zero

The report content is not committed, loaded, or required at runtime.

## Reconciliation Policy

Authoritative identity remains exact trim/NFC-safe source `partno` to staged
`supplier_sku`. Case/whitespace normalization and EAN are diagnostic only.
Neither profile may link, unlink, merge, repair, import, or update data.

Observed-profile reports show aggregate stock evidence and a mandatory
`stock_semantics_discrepancy_requires_review` warning. A successful observed
reconciliation uses `reconciliation_requires_stock_semantics_review`; it is
not an approval of stock, quantity, availability, import, a feed profile, or a
schedule change.

## Safety

The command remains local-file-only and read-only. It has no apply, persistence,
import, link, schedule, queue, remote fetch, image, or Catalog Sync action.
It requires a pinned source SHA-256, an exact supplier/staging baseline, a
disabled APCOM schedule, no active import, and CREATE enabled with UPDATE,
Sync All, and automatic sync disabled.

Before/after guards cover suppliers, staging, catalog, taxonomy, attributes,
import history, and Catalog Sync records. `records_changed` must remain zero.

## C3B Decision Register Boundary

The local C3B decision register records numeric stock as pending and explicitly
keeps quantity and availability mappings unresolved. Its preview-only profile
can count unresolved-stock review candidates, but does not make a stock,
quantity, availability, lifecycle, or commercial decision. C3B is local and in
review only; it is not an operational reconciliation or import approval.

## Historical Next Operational Sequence

The following sequence was the planned sequence before the observed-profile
closeout. It is retained as historical context; the completed result and
aggregate evidence are recorded in
[APCOM Reconciliation Review and Operational Closeout](APCOM_RECONCILIATION_REVIEW_CLOSEOUT.md).

1. Merge and deploy C.3A.1 tooling.
2. Restore the authorized local source into the app container.
3. Confirm its SHA-256.
4. Confirm the APCOM schedule remains disabled.
5. Confirm there is no active import.
6. Confirm the exact staging baseline.
7. Run reconciliation with `apcom-observed-stock-v1`.
8. Save stdout JSON outside Git.
9. Verify all mutation counters remain zero.
10. Review SKU and EAN diagnostics.
11. Do not approve a stock mapping.
12. Do not import or re-enable the schedule.

## Current Operational Closeout Addendum

The subsequent preview-only profile run completed read-only with a passed
strict contract. Numeric stock remains unresolved as quantity or availability;
no import, profile persistence, schedule change, or Catalog Sync was approved.
See APCOM_PREVIEW_ONLY_FEED_PROFILE_OPERATIONAL_CLOSEOUT.md.
