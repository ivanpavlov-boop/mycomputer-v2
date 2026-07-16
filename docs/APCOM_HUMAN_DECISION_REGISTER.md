# APCOM Human Decision Register

## Status

Phase 9C.6.5C.3B.1 completed, merged, and synced its read-only operational
preview. This v1 register remains a historical immutable contract.

The versioned register key is `apcom-human-decisions-v1`. It is an in-code,
read-only review contract. It is not stored in a database, cache, file, or
supplier configuration.

## Purpose

The register makes the remaining APCOM business decisions explicit before any
future feed profile can become executable. It does not select a price, infer
stock or availability, approve an import, enable a schedule, or call Catalog
Sync.

Every entry contains a stable decision ID, subject, decision state, source
field or action, proposed and approved roles, evidence requirement/reference,
rationale, human-review marker, execution/write/persistence flags, blocking
marker, and notes. Entries are emitted in deterministic decision-ID order and
contain no raw source values, identifiers, URLs, credentials, or tokens.

## Decision States

| State | Meaning | Execution |
| --- | --- | --- |
| `confirmed` | Evidence-backed source-to-staging review fact. | Still non-executable in C3B. |
| `diagnostic_only` | May be counted or compared for review. | Cannot link, merge, create, update, or delete. |
| `review_only` | May appear as an aggregate review candidate. | Requires a human decision. |
| `pending` | No business decision has been made. | Blocks a future executable profile. |
| `prohibited` | Explicitly forbidden in this phase. | Never allowed. |

The validator rejects duplicate IDs, unknown states, confirmed entries without
rationale, a missing pinned SHA-256 requirement for the local source decision,
any executable pending/diagnostic/review entry, any allowed prohibited entry,
catalog or staging writes, profile persistence, and missing required decisions.

## Confirmed And Diagnostic Facts

- `APCOM-ID-001`: `xml.product.partno` is the authoritative source-to-staging
  supplier SKU identity for exact preview reconciliation only.
- `APCOM-SOURCE-001`: the `xml.product` source must be an explicitly supplied
  local file pinned by an operator-provided SHA-256 value.
- `APCOM-ID-002`: EAN/GTIN is diagnostic only. It cannot authorize linking,
  merging, identity replacement, CREATE, UPDATE, or DELETE.
- `APCOM-LIFECYCLE-001`: `eol` is review only and cannot automatically change
  lifecycle, publication, activity, or availability.

## Pending Decisions

The following decisions remain blocking and intentionally unresolved:

- `APCOM-STOCK-001`: numeric `stock` is not approved as quantity or binary
  availability;
- `APCOM-QUANTITY-001` and `APCOM-AVAILABILITY-001`: no catalog quantity or
  availability mapping exists;
- `APCOM-MPN-001`: no approved MPN field exists;
- `APCOM-PRICE-001`: DAC and FD are observed price candidates only and no
  selected commercial price is approved;
- `APCOM-CURRENCY-001`, `APCOM-VAT-001`, and `APCOM-GREEN-TAX-001`: commercial
  semantics remain unresolved;
- `APCOM-SOURCE-ONLY-001`: source-only SKU rows are preview-only potential
  CREATE classifications, not creation authorization;
- `APCOM-STAGING-ONLY-001` and `APCOM-LINKED-STAGING-ONLY-001`: source absence
  is a classification only and cannot authorize deletion or unlinking; and
- `APCOM-EOL-REVIEW-001` and `APCOM-ZERO-PRICE-001`: EOL and zero-price rows
  remain human-review groups.

## Explicit Prohibitions

The register explicitly prohibits automatic supplier import
(`APCOM-PROHIBIT-AUTO-IMPORT-001`), schedule enablement
(`APCOM-PROHIBIT-SCHEDULE-001`), Sync All (`APCOM-PROHIBIT-SYNC-ALL-001`),
automatic sync (`APCOM-PROHIBIT-AUTO-SYNC-001`), UPDATE sync
(`APCOM-PROHIBIT-UPDATE-SYNC-001`), image import
(`APCOM-PROHIBIT-IMAGE-IMPORT-001`), and supplier content overwrite
(`APCOM-PROHIBIT-CONTENT-OVERWRITE-001`). Content protection includes product
name, slug, SEO, descriptions, images, categories, attributes, and workflow
state.

All entries have `automatic_execution_allowed=false`,
`catalog_write_allowed=false`, `staging_write_allowed=false`, and
`profile_persistence_allowed=false`.

## Approval Boundary

A future phase may only use a new, reviewed register version after humans make
the relevant commercial and lifecycle decisions. C3B does not silently promote
pending decisions, persist an approval, or create an executable configuration.

Until then APCOM remains frozen for this review path, UPDATE remains disabled,
Sync All remains disabled, automatic sync remains disabled, and image import is
prohibited.

## Current Operational Closeout Addendum

The Phase 9C.6.5C.3B.1 operational preview completed read-only with a passed
strict contract and 22 blocking decisions. The evidence and aggregate result
are recorded in APCOM_PREVIEW_ONLY_FEED_PROFILE_OPERATIONAL_CLOSEOUT.md.
Human decisions remain pending; the register remains non-persistent and
non-executable.

## V2 Addendum

Phase 9C.6.5C.3C adds `apcom-human-decisions-v2` without rewriting v1. It
records partial operator-confirmed business semantics while retaining a blocked
approval gate. See [APCOM Authoritative Human Decisions V2](APCOM_AUTHORITATIVE_HUMAN_DECISIONS_V2.md).
