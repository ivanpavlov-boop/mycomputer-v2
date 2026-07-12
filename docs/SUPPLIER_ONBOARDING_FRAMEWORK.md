# Supplier Onboarding Framework

## Status

Phase 9C.6.5A is implemented locally as a discovery and contract foundation.
It defines reusable data contracts and pure normalization/fingerprint services.
It is not production wiring and does not select or onboard a new supplier.

The next phase is **9C.6.5B - Multi-Supplier Readiness Matrix**. It is not
started.

## Intended Pipeline

Every future supplier must use the same reviewed pipeline:

```text
supplier registration
-> capability audit
-> versioned feed profile
-> driver/adapter
-> normalized record
-> read-only preview/report
-> controlled supplier_products staging plan
-> post-apply verification
-> mapping review
-> manual selected CREATE
-> optional guarded UPDATE pilot
```

This phase defines the contracts between these steps. It does not execute the
pipeline.

## Contracts

The contract namespace is `App\\Contracts\\Suppliers\\Onboarding` and the
immutable value objects are in `App\\Data\\Suppliers\\Onboarding`.

### Driver and source

- `SupplierFeedDriverInterface` describes a driver without selecting a runtime
  implementation.
- `SupplierFeedSource` describes a local source and optional expected SHA-256
  fingerprint. Remote HTTP, HTTPS, and FTP locations are rejected.
- `SupplierFeedProfile` stores a versioned, supplier-specific mapping/rule
  profile without feed credentials or remote URLs.
- `DriverInspection` is a bounded, safe inspection result.

### Normalized record

`NormalizedSupplierRecord` uses schema `supplier-normalized-record-v1` and
keeps supplier-owned fields distinct from catalog-owned fields. It carries
identifiers, name/brand/category observations, price/currency, quantity,
availability observations, provenance fingerprints, warnings, and validation
issues. Canonical serialization excludes runtime database identifiers and
source paths.

### Fingerprints and preview

- `SourceFingerprint` accepts SHA-256 digests only.
- `CandidateFingerprintService` hashes canonical normalized records in stable
  order, so the result is independent of input order.
- `PreviewReport` is read-only, bounded, and reports classifications, issue
  samples, source/candidate fingerprints, and zero mutation counters.
- `ValidationIssue` and `PreviewClassification` make blockers and warnings
  explicit without performing a write.

### Staging and verification

`StagingPlan` is a create-only planning structure. Its fixed scope is
`supplier_products-only`, its update count must remain zero, and it has no
apply method. It is a contract for a later controlled staging phase, not a
staging writer.

`PostApplyVerificationResult` describes source, candidate, SKU, canonical row,
provenance, price, availability, truncation/schema, and protected-table
checks. Its verified state requires zero linked catalog products and zero
changes to protected tables.

## Pure Normalization Rules

`PriceNormalizationService` is profile-driven and does not query currency,
tax, pricing, or catalog tables. It validates decimal input, emits a fixed
two-decimal representation without implicit tax assumptions or silent rounding,
and reports negative, overflow, precision, and missing-value issues.

`AvailabilityNormalizationService` applies an explicit profile mapping first,
then conservative standard labels, then a quantity-only fallback using the
existing threshold convention. Unknown external values remain warnings rather
than being silently treated as a safe mapping. It does not query or write
availability tables.

## Security and Safety Boundary

Contract metadata is guarded against passwords, secrets, tokens, credentials,
API keys, private keys, authorization values, and remote feed URLs. Local
source paths are intentionally omitted from serialized source descriptors.

The Phase 9C.6.5A code has no HTTP client, queue dispatch, scheduler, storage
write, Eloquent query, Catalog Sync call, image action, or production service
container binding. There is no generic XML/CSV/JSON driver yet. A fake driver
is used only by tests.

## Explicitly Not Implemented

- no supplier #2 selection or supplier record;
- no remote feed fetch or production credential configuration;
- no preview/import/apply command invocation;
- no `supplier_products` or product write;
- no category, mapping, attribute, image, or SEO write;
- no Catalog Sync call;
- no schedule, job, automatic sync, Sync All, or UPDATE enablement;
- no migration, seeder, route, admin page, or deployment.

ASBIS behavior remains in its existing isolated services and is unchanged.
