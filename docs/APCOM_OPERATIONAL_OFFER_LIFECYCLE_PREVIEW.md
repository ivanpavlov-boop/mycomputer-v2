# APCOM Operational Offer Lifecycle Preview

## Scope

Phase 9C.6.5C.3D adds the first input-driven operationally shaped preview for
APCOM missing-offer lifecycle decisions. The implementation is CLI-only,
deterministic, read-only and non-persistent. It composes the existing snapshot
qualification, missing-offer, reappearance, multi-supplier aggregation,
visibility, deletion and retention policies; it does not create a second
policy engine.

The implementation has not processed real APCOM evidence. It has not been
pushed, merged, deployed or run on staging/VPS. Operational execution remains
separately gated.

## Command

```powershell
php artisan suppliers:preview-apcom-offer-lifecycle `
    --supplier=apcom `
    --evidence=C:\safe\synthetic-evidence.json `
    --expected-sha256=<64-lowercase-hex> `
    --evaluated-at=2026-08-12T12:00:00+03:00 `
    --format=json `
    --limit=20 `
    --no-interaction `
    --no-ansi
```

Only a regular local file is accepted. Remote locations, network paths, stream
wrappers, symbolic links, malformed JSON and a SHA-256 mismatch fail closed.
The reader opens the file once, verifies the opened regular-file identity,
reads at most 8 MiB in bounded chunks and incrementally hashes those exact
captured bytes. Only the bytes that produced the pinned SHA-256 digest are
parsed. The command does not contain apply, persist, import, sync, link,
schedule, cleanup, fetch or download controls.

`evaluated_at` is mandatory and must be an ISO-8601 timestamp with `Z` or an
explicit numeric offset. Policy evaluation never uses the implicit system
clock.

## Evidence Contract

The immutable input schema is:

```text
supplier-offer-lifecycle-operational-evidence-v1
```

It includes:

- the exact policy-version set and supplier scope;
- stable source identity without a path or URL;
- an explicit per-supplier approved freshness policy;
- ordered capture and authoritative snapshot timestamps;
- unique snapshot fingerprints and record-count/drop evidence;
- successful/full/schema-valid/truncation and fatal-integrity evidence;
- supplier identity and comparability evidence;
- exhaustive exact offer-presence observations represented only by
  domain-separated SHA-256 identities;
- optional explicit continuous-zero-active-offer evidence for a catalog
  Product represented by its domain-separated hash.

Source identities are compared as exact decoded JSON strings. Case,
leading/trailing whitespace and Unicode representation are preserved; no
case-folding, trimming or Unicode normalization is applied before the
per-supplier stability check. Reader, direct bundle construction and the
per-supplier identity map share one fail-closed validator: identities must be
valid UTF-8, non-empty, not Unicode-whitespace-only and no longer than 128
Unicode code points. Validation returns the original string unchanged.

The bundle rejects raw supplier SKU, EAN/GTIN, MPN, source paths and raw source
records. `received_at`, `last_seen_at`, `updated_at`, current database presence
and implicit current time are not accepted as historical evidence.

Duplicate snapshot fingerprints remain valid immutable evidence but are
frozen by the existing qualification policy and are never counted twice.

JSON object keys must be unique within every object scope. Validation is
token-aware, decodes JSON string escapes before comparing keys and rejects
escaped-equivalent duplicates. Equal keys in separate sibling objects and
repeated array values remain valid.

Count, quantity, EOL and freshness fields are JSON integers with explicit
database/policy bounds. Drop percentages and prices are JSON integers or
canonical unsigned decimal strings with bounded precision and range. Float,
exponent, signed, whitespace-padded, ambiguous leading-zero, overflow and
over-precise representations fail closed. Product-drop qualification uses
exact normalized decimal-string comparison rather than binary floating point.

## Report Contract

The report schema is:

```text
supplier-offer-lifecycle-operational-preview-v1
```

It contains the evidence and catalog-state fingerprints, ordered snapshot
fingerprints, policy versions, explicit evaluation time, qualification and
offer/product aggregate counts, recommendation and reason-code counts,
bounded hashed samples, the evaluation-only gate, the existing retention plan,
Catalog Sync safety flags, `persisted=false`, `write_allowed=false`, zero
records-changed counters and zero dispatched-job/event counters.

Rows are stable-sorted and canonical JSON output is deterministic for the same
evidence, `evaluated_at` and catalog state. No raw supplier identifiers, source
path, URL, credentials or complete records are emitted.

## V4 Semantics

- One APCOM absence is not EOL. Three consecutive qualified missing snapshots
  and at least 48 elapsed hours may emit `would_deactivate_offer` for the APCOM
  offer only.
- Failed, partial, malformed, truncated, anomalous, duplicate, below-minimum,
  non-comparable or supplier-unconfirmed snapshots freeze tracking. A frozen
  snapshot neither increments nor resets the lifecycle.
- A qualified present offer with an unambiguous exact supplier SKU resets the
  missing count, first-missing timestamp and confirmed-missing state. Presence
  is independent of sellability: zero/invalid price and out-of-stock offers
  reset missing state but remain non-sellable review outcomes. Reactivation is
  stricter and requires a positive exact price plus valid mapping and no
  blocking issue or identifier conflict.
- A source-only row is `potential_create` in preview only. It cannot create,
  match, link or sync a Product.
- APCOM `partno` remains supplier SKU only. MPN is not inferred from it, EAN,
  names, descriptions or heuristics.
- APCOM `fd_price=0` is a present but non-sellable `manual_review` offer. It is
  not free, not missing and cannot start an archival timeline.
- APCOM evidence is fresh through 24 hours inclusive. Evidence older than 24
  hours is stale, excluded from sellable aggregation and classified for
  review, but it is not missing.
- APCOM freshness is not a supplier default. Another supplier requires its own
  explicit approved freshness policy in the evidence bundle; otherwise the
  evaluation fails closed to `manual_review`.
- One fresh valid alternative offer with `in_stock`, `limited`, `on_request` or
  `last_units` keeps the Product recommendation `keep_active`.

## Visibility And Protection

Visibility is recommendation-only. A zero-active-offer timeline is evaluated
only when the bundle explicitly proves continuous qualified absence and its
start timestamp. Otherwise the result is `manual_review` with
`unprovable_continuous_absence`.

The supported recommendations are:

- `keep_active`;
- `would_deactivate_offer`;
- `would_reactivate_offer`;
- `would_mark_unavailable`;
- `would_mark_archived_noindex`;
- `would_mark_cold_archive_candidate`;
- `manual_review`.

Day 0 through complete day 59 plans unavailable/non-purchasable behavior only;
day 60 plans HTTP 200 with `noindex, follow` and sitemap exclusion; 24 complete
months plans a cold-archive candidate. No Product, storefront, query, Scout,
sitemap, robots, noindex, URL, redirect, deletion or retention behavior is
implemented.

Manual Products, explicit manual overrides, unresolved maintenance ownership,
non-public workflows, deleted records, disabled suppliers and incomplete offer
coverage fail closed. Product content, workflow and relations remain untouched.

## Stable Reason Codes

Existing codes are reused where applicable. The orchestration adds only:

- `active_import_state`;
- `unknown_import_state`;
- `missing_supplier_freshness_policy`;
- `unprovable_continuous_absence`;
- `manual_product_excluded`;
- `manual_override`;
- `unresolved_manual_maintenance_protection`;
- `non_public_workflow_state`;
- `duplicate_offer`;
- `disabled_or_deleted_record`;
- `stale_snapshot`;
- `snapshot_not_comparable`;
- `snapshot_chronology_invalid`.

Input and safety gates also use stable validation codes documented by the test
contract, including evidence/schema/hash/source/scope/version/baseline errors.

## Safety Gate

The additive V4 runtime decision register and preview profile authorize only
evaluation. All catalog, staging, lifecycle, visibility, link, profile,
schedule, import, automatic-execution and Catalog Sync write permissions remain
false. V1, V2 and V3 remain available and unchanged as historical contracts.

At both evaluation boundaries the command requires:

- a matching active supplier scope;
- APCOM schedule disabled;
- import activity state `clear` for every scoped supplier;
- exact supplier snapshot count/drop baselines;
- `CATALOG_SYNC_CREATE_ENABLED=true` (informational only);
- `CATALOG_SYNC_UPDATE_ENABLED=false`;
- `CATALOG_SYNC_SYNC_ALL_ENABLED=false`;
- `CATALOG_SYNC_AUTO_ENABLED=false`.

Every real APCOM XML import enters `XmlImportEngine::import()`, which creates
one importer-owned, auto-increment `import_histories.id` generation record in
`started` state before feed reading or staging writes. The same row transitions
to `finished` (including `completed_with_errors`) or `failed`; its ID and
supplier scope do not change. Import History is list/view-only in Filament,
backend policy denies manual mutation, model guards reject unsupported
creation/update/delete, and supplier deletion cannot cascade generation
evidence away. No application prune or direct-delete path is supported.

The preview captures the per-supplier maximum history ID before evaluation.
After the immutable report has been fully assembled from captured values, the
final gate reloads supplier baselines, reruns import activity inspection,
compares the generation, then reloads and compares catalog/protected-state
fingerprints. There are no database, filesystem, relation or external-state
reads after that final fingerprint comparison. An import beginning later
cannot change the already captured report state; an import visible at or before
the final boundary aborts through activity, generation or fingerprint drift.
`completed_with_errors` is terminal/inactive for concurrency only and does not
make its evidence a qualified successful snapshot. Supplier queries and
fingerprint payloads are ordered by stable database ID.

Protected database counts and high-water timestamps are also fingerprinted
before and after evaluation. The report includes a reproducibility fingerprint
of the relevant supplier, staging, offer and Product state. Tests reject
mutation SQL and prove no HTTP request, job, queue, cache or storage write.

No supplier import, feed fetch, Catalog Sync, CREATE/UPDATE execution, Product
or `supplier_products` mutation, offer write, linking, schedule, cleanup, job,
event, migration or persistence is part of this implementation.
