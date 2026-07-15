# APCOM Reconciliation Review and Operational Closeout

## Status

Phase 9C.6.5C.3A.2 is completed by this documentation closeout.

- APCOM remains Supplier #1.
- ASBIS remains Supplier #2.
- Supplier #3 remains unselected.
- APCOM `schedule_enabled` remains `false`.
- APCOM `import_enabled` remains `true`.
- No manual import is approved.
- No automatic unfreeze exists.
- No schedule re-enable is approved.

The closeout records a successfully completed, read-only operational
reconciliation. It does not approve importing APCOM data, persisting a feed
profile, interpreting stock, or changing the existing staging and catalog
state.

## Purpose

This phase closes the review of the authorized APCOM source-to-staging
reconciliation. It records the evidence, aggregate results, risk groups, and
human-decision gates without implementing the decisions that remain open.

The closeout does not approve:

- import or re-import;
- stock, quantity, or availability interpretation;
- price, currency, VAT, or Green Tax selection;
- feed-profile persistence;
- linking, unlinking, deletion, or lifecycle changes; or
- Catalog Sync execution.

## Evidence

The following are operator-supplied operational evidence references:

- strict-profile report: `storage/app/imports/apcom-audit/reports/apcom_official_reconciliation_20260714T141523Z.json`
- strict-profile report SHA-256: `86de4ce6d79093954c22eaccfb5ac063d7168cfaf2d0c2228af5fce13baae2cc`
- observed-profile report: `storage/app/imports/apcom-audit/reports/apcom_observed_stock_reconciliation_20260714T160540Z.json`
- observed-profile report SHA-256: `5f89f34bf6033c9d0dc039d84141d901f99d898514248512ab5f561b72a1b735`
- authorized source label: `apcom-product-list-2026-07-14-11-07.xml`
- authorized source SHA-256: `fef5e30eb5e16714014f3654fce34025a14a0fb22750bc01158163b2c14c9ac1`

The runtime reports and source XML remain outside Git and are not required by
the documentation-contract tests. No raw identifiers, names, URLs, bounded
hash samples, credentials, or feed URLs are copied into this repository.

The strict run used `apcom-official-v1`, failed closed on the observed
non-binary stock values, and made no changes. The successful read-only run
used `apcom-observed-stock-v1` and continued reconciliation while retaining a
mandatory stock-semantics review verdict.

## Deployment and baseline checks

PR #148 was merged and deployed before this review closeout. The operator
reported that:

- the application returned HTTP 200;
- the application container was healthy;
- the reconciliation command was registered;
- the APCOM schedule remained disabled;
- active `supplier_import_runs`: `0`;
- active `import_jobs`: `0`;
- Catalog Sync safety flags remained safe;
- protected counts and fingerprints remained stable; and
- every `records_changed` value remained zero.

These are operator-supplied facts only. This phase did not connect to the VPS,
production database, or operational report.

## Operational outcome

The observed-profile runtime result was:

- `schema_version`: `local-supplier-source-staging-reconciliation-v1`;
- `mode`: `local_source_staging_reconciliation`;
- selected profile: `apcom-observed-stock-v1`;
- `success=true`;
- `verdict=reconciliation_requires_stock_semantics_review`;
- `blockers=0`;
- `warnings=7`;
- `read_only=true`;
- `human_review_required=true`;
- stderr was empty; and
- all `records_changed` values were zero.

The seven warnings were:

- documented Green Tax absent in the current snapshot;
- blank EAN requires review;
- zero-price candidate requires review;
- EOL rows require human review;
- stock-semantics discrepancy requires review;
- exact supplier-SKU reconciliation requires review; and
- source/staging count delta requires review.

The run did not create a persisted feed profile or executable import
configuration. It did not execute an import or Catalog Sync, change links, or
modify any protected record.

## Decision status

- stock semantic meaning: unresolved;
- quantity mapping: not approved;
- availability mapping: not approved;
- selected price: unresolved;
- stock semantics approval: pending;
- normalization approval: pending;
- feed profile persistence: not approved;
- import: not approved;
- schedule re-enable: not approved;
- Phase 9C.6.5C.3B remains pending;
- UPDATE remains disabled;
- Sync All remains disabled;
- automatic sync remains disabled; and
- image import is prohibited.

## Source and staging inventory

### Authorized source aggregates

- source rows: `1803`;
- non-blank supplier SKU/`partno`: `1803`;
- blank supplier SKU/`partno`: `0`;
- exact unique supplier SKUs: `1803`;
- exact duplicate groups: `0`;
- exact duplicate rows: `0`;
- non-blank EAN: `1691`;
- blank EAN: `112`;
- `eol=0`: `1700`;
- `eol=1`: `103`; and
- invalid EOL values: `0`.

### APCOM staging aggregates

- staging rows: `1872`;
- blank supplier SKU: `0`;
- blank EAN: `112`;
- linked rows: `989`; and
- unlinked rows: `883`.

These staging rows were not modified. No staging row was inserted, updated,
or deleted, and no `product_id` relationship was changed.

## SKU reconciliation

The authoritative rule was source `partno` to staging `supplier_sku`.
Canonical-safe matching used Unicode leading/trailing trim and NFC
normalization where supported. Case-insensitive comparison, broader
whitespace normalization, and EAN comparison remained diagnostic only.

Exact results:

- exact one-to-one matches: `1786`;
- source-only SKUs: `17`;
- staging-only SKUs: `86`;
- normalized-only candidates: `0`;
- ambiguous normalized candidates: `0`;
- one-source-to-many-staging risks: `0`;
- many-source-to-one-staging risks: `0`;
- source balance valid: `true`; and
- staging balance valid: `true`.

The required balances are:

```text
1786 + 17 = 1803
1786 + 86 = 1872
86 - 17 = 69
```

The original net source/staging count difference was `69`. It did not mean
that there were only `69` staging-only products. The exact result is `17`
source-only records and `86` staging-only records, and both groups require
separate human review.

## Linked-state risk review

Among the `1786` exact SKU matches:

- matched and linked: `951`;
- matched and unlinked: `835`.

Among the `86` staging-only rows:

- staging-only linked: `38`;
- staging-only unlinked: `48`.

### Highest-priority manual review

The `38` staging-only rows that are currently linked to catalog products are
the highest-priority manual-review group. They are absent from the authorized
current XML snapshot. They must not be unlinked, unpublished, deleted, or
deactivated automatically.

### Manual review

The `48` staging-only unlinked rows must not be deleted automatically.
Historical or feed-difference causation remains unknown.

### Future-create review

The `17` source-only rows exist in the current XML snapshot. They must not be
imported or created automatically. They require a future preview-only and
human-reviewed decision.

Absence from the source does not prove unavailable, discontinued, or deleted
state. No product workflow status or supplier-product lifecycle state may be
inferred from absence.

## EAN consistency

For the `1786` exact SKU matches:

- EAN equal: `1674`;
- EAN blank in both: `112`;
- EAN differs: `0`;
- blank in source only: `0`;
- blank in staging only: `0`; and
- cross-SKU EAN conflicts: `0`.

No EAN mismatch was detected for exact SKU matches. The `112` blank-EAN cases
are consistent between source and staging. EAN remains diagnostic only and
must not automatically replace supplier SKU as identity or authorize product
linking.

## Stock semantics discrepancy

APCOM's published claim is binary:

- `stock=0`: out of stock;
- `stock=1`: in stock.

The observed source snapshot was numeric but not binary:

- total: `1803`;
- `stock=0`: `982`;
- `stock=1`: `30`;
- `stock>1`: `791`;
- positive: `821`;
- distinct numeric values: `90`;
- minimum: `0`;
- maximum: `100`;
- blank: `0`;
- non-numeric: `0`;
- fractional: `0`; and
- negative: `0`.

The observed numeric contract is valid for diagnostic parsing, but the
published binary contract does not match this snapshot. The stock semantic
meaning is unresolved. Quantity mapping and availability mapping are not
approved, automatic mapping is prohibited, and human review is required.

The snapshot maximum of `100` is evidence from one source file, not a
business constraint. This documentation does not state that stock is a
confirmed quantity, available quantity, or availability signal, and does not
authorize writing any value into the catalog.

## Stock and EOL combinations

- `stock=0, eol=0`: `982`;
- `stock=1, eol=0`: `22`;
- `stock>1, eol=0`: `696`;
- `stock=0, eol=1`: `0`;
- `stock=1, eol=1`: `8`; and
- `stock>1, eol=1`: `95`.

The EOL total is `103` because `8 + 95 = 103`. All EOL rows require manual
lifecycle review. EOL remains separate from unresolved stock semantics and no
combination authorizes automatic catalog mutation.

No EOL combination may automatically delete, deactivate, unpublish, archive,
unlink, alter product workflow, alter supplier-product status, or alter
catalog availability.

## Price, currency, VAT, and Green Tax

### DAC price

- numeric: `1803`;
- negative: `0`;
- zero: `72`; and
- positive: `1731`.

### FD price

- numeric: `1803`;
- negative: `0`;
- zero: `72`; and
- positive: `1731`.

### Comparison

- equal: `912`;
- DAC higher: `891`; and
- DAC lower: `0`.

The selected price is unresolved. Currency and VAT treatment are unresolved.
Green Tax handling is unresolved; the field is documented by APCOM but was
absent from this snapshot. Zero-price rows require human review.

This closeout does not select DAC or FD, infer EUR, infer VAT inclusion or
exclusion, default Green Tax to zero, infer Green Tax inclusion, or modify
staging or catalog prices.

## Hash-sample policy

Only the aggregate sample policy is documented:

- algorithm: SHA-256;
- namespace: `local-supplier-source-staging-reconciliation-v1`;
- raw values emitted: `false`;
- source-only hash sample count: `17`;
- staging-only hash sample count: `20`;
- normalized-only hash sample count: `0`;
- cross-SKU EAN conflict sample count: `0`;
- source-only samples truncated: `false`; and
- staging-only samples truncated: `true`.

The runtime `bounded_hash_samples` object and its actual hash values are not
copied into Git.

## Explicit prohibitions

This closeout does not authorize or implement:

- importing the `17` source-only rows;
- deleting any of the `86` staging-only rows;
- unlinking the `38` linked staging-only rows;
- inferring availability or lifecycle state from source absence;
- changing product workflow status;
- changing supplier-product status;
- modifying staging rows;
- creating or modifying catalog products;
- changing categories, mappings, attributes, or media;
- selecting or writing a price, currency, VAT, or Green Tax policy;
- importing or downloading images;
- re-enabling the APCOM schedule;
- running Catalog Sync;
- enabling UPDATE;
- adding Sync All;
- enabling automatic or scheduled sync; or
- persisting an executable feed profile.

## Safety result

The reconciliation was read-only. No import, persistence, linking, unlinking,
deletion, Catalog Sync action, queue job, schedule change, product mutation,
supplier-product mutation, category change, mapping approval, attribute
change, or image operation was authorized or executed. The existing staging
data and catalog links remain unchanged. No rollback is required.

APCOM is not approved for automatic imports. The APCOM schedule must remain
disabled. The safe Catalog Sync position remains CREATE enabled, UPDATE
disabled, Sync All disabled, and automatic sync disabled.

## Operational closeout decision

The reconciliation evidence is sufficient for human review. It is not an
approval for operational import, stock normalization, feed-profile
persistence, link repair, lifecycle change, or Catalog Sync. The review
records remain aggregate-only and the existing staging and catalog state is
preserved.

## Next gate

Phase 9C.6.5C.3B - APCOM Human Decision Register and Preview-only Feed Profile
Design is the next pending decision phase. It is not started or completed by
this closeout.

That phase may:

- record an explicit human decision for the price field;
- record currency and VAT treatment from authoritative evidence;
- record Green Tax treatment;
- record stock semantic status if APCOM provides clarification;
- define handling policies for the `17` source-only rows;
- define handling policies for the `86` staging-only rows;
- define handling policies for the `38` linked staging-only rows;
- define handling policies for the `103` EOL rows;
- define handling policies for the `72` zero-price rows;
- design a non-persisted preview-only feed profile;
- produce preview-only comparison output; and
- require human approval.

It must not run import, persist a feed profile, mutate `supplier_products`,
create or update catalog products, link or unlink products, delete staging
rows, re-enable the schedule, run Catalog Sync, enable UPDATE, add Sync All,
enable automatic sync, or import images.
