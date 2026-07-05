# Catalog Sync

## Purpose

Document the current supplier-to-catalog control layer before Phase 8 UPDATE sync.

Related docs: [Supplier Import](SUPPLIER_IMPORT.md), [Pricing Rules](PRICING_RULES.md), [Supplier Exclusions](SUPPLIER_EXCLUSIONS.md), [Matching Rules](MATCHING_RULES.md), [Sync Safety](SYNC_SAFETY.md), [Catalog Sync Safety Playbook](CATALOG_SYNC_SAFETY.md), [Rollback Plan](ROLLBACK_PLAN.md).

## Current Status

- Catalog Sync Preview exists in Filament.
- Query-only supplier product preview exists.
- Pricing preview exists.
- Exclusion preview exists.
- Matching visibility exists.
- Sync action preview exists.
- Manual selected CREATE sync exists.
- CREATE candidate discovery exists.
- Read-only CREATE diagnostics exist.
- Catalog action filtering exists.
- Table scroll panel, sticky header, and counter layout exist.
- Feature flags exist for CREATE, UPDATE, Sync All, and automatic sync.
- Manual selected CREATE sync writes `catalog_sync_batches` and `catalog_sync_logs` audit records.
- Manual selected UPDATE sync exists for price, stock, availability, supplier cost, and supplier offer metadata only.
- Catalog Sync Preview shows the effective feature flag status from configuration.
- Filament exposes read-only Catalog Sync Batches and Catalog Sync Logs admin views.
- Supplier/catalog-sync-created products default to the `published` workflow state.
- Manual selected UPDATE preserves product workflow status and protected content fields.

Only selected CREATE sync and feature-flagged selected UPDATE price/stock sync are implemented. Sync All, automatic sync, scheduled sync, and image import are not enabled.

## Current Flow

```text
supplier_products staging
-> Catalog Sync Preview
-> pricing rules
-> exclusion rules
-> matching
-> sync_action preview
-> manual selected CREATE sync
-> manual selected UPDATE price/stock sync
-> catalog sync batch/log audit
-> catalog products
```

## Preview Actions

| Action | Meaning | Write enabled now |
| --- | --- | --- |
| `CREATE` | No safe catalog match exists and minimum data is present. | Yes, selected rows only. |
| `UPDATE` | Safe existing catalog match exists. | Yes, selected rows only, behind `CATALOG_SYNC_UPDATE_ENABLED`, price/stock fields only. |
| `SKIP` | Excluded, no meaningful change, or not eligible. | No write. |
| `CONFLICT` | Ambiguous or unsafe automatic decision. | No write. |
| `ERROR` | Row evaluation failed. | No write. |

## Current Filters

- batch
- supplier
- catalog action
- discovery mode
- discovery scan limit
- stock
- category contains
- brand contains
- name / SKU / EAN / MPN search

The Catalog action filter is applied after row evaluation because `sync_action` is computed from exclusions, matching, eligibility checks, and action preview logic.

## Discovery Modes

| Mode | Behavior |
| --- | --- |
| Current batch | Evaluates the selected batch only. |
| Find CREATE candidates | Scans a safe capped number of staged products for eligible CREATE rows. |

Supported discovery scan limits: `1000`, `2000`, `5000`.

## CREATE Diagnostics

When CREATE candidates are not found, diagnostics show:

- scanned rows
- selected scan limit
- CREATE candidates found
- skipped rows
- matched/update rows
- excluded rows
- match type summary
- skip reason summary
- unmatched-but-not-CREATE reason summary
- up to 10 sample diagnostic rows

Sample diagnostic rows show:

- supplier product ID
- supplier SKU
- EAN
- name
- match type
- sync action
- sync reason
- excluded
- exclusion reason

## Corrective Review Command

Phase 9C.4.2 removed an old supplier import path that could create catalog
products automatically after staging supplier rows. Before that hotfix was
deployed, three Arlo catalog products were created from supplier data while
automatic catalog sync was disabled.

Phase 9C.4.3 adds a dry-run-first corrective command so those known products
can be moved out of `published` and into manual review without deleting them or
changing supplier staging data:

```bash
php artisan catalog:review-auto-created-products
php artisan catalog:review-auto-created-products --apply
php artisan catalog:review-auto-created-products --apply --status=pending_review
```

Known SKU allowlist:

- `VMA3600-10000S`
- `VMC4460P-100EUS`
- `VMC4260P-100EUS`

The command defaults to dry-run and reports the products found, missing SKUs,
current status, proposed status, and safety counters. Apply mode requires
explicit `--apply`, is idempotent, and may only update review/status fields for
the allowlisted products. It does not delete products, mutate
`supplier_products`, mutate `product_attribute_values`, mutate
`category_product_attributes`, or change product content, SEO, images,
categories, attributes, price, stock, or supplier offer metadata.

Do not manually delete these products from the database. Review them in the
admin after running the dry-run and only apply the status move when the output
matches the expected three products. Supplier imports remain staging-only and
future catalog CREATE sync remains manual and controlled through Catalog Sync
Preview.

See [Catalog Sync Safety Playbook](CATALOG_SYNC_SAFETY.md) for the incident
summary, staging-only supplier import rule, required PR checks, and AI/Codex
review guardrails.

## Important Interpretation Rules

- Unmatched does not automatically mean CREATE.
- Unmatched rows may be excluded, missing required data, conflicting, or otherwise unsafe.
- Name similarity is diagnostic/warning only.
- Name similarity alone must not become a safe UPDATE.
- Matching must be explainable in the preview.

## What Is Allowed

- Read-only diagnostics.
- Read-only feature flag visibility in Catalog Sync Preview.
- Read-only review of Catalog Sync Batches and Catalog Sync Logs in Filament.
- Manual selected CREATE sync for eligible rows.
- Manual selected UPDATE sync for eligible rows when `CATALOG_SYNC_UPDATE_ENABLED=true`.
- Per-row try/catch for selected CREATE writes.
- Per-row try/catch for selected UPDATE writes.
- Server-side revalidation before writes.
- Visible created/skipped/failed summary.
- Batch/log audit records for manual selected CREATE sync.
- Batch/log audit records for manual selected UPDATE sync.
- `CATALOG_SYNC_CREATE_ENABLED=false` can disable CREATE sync without removing preview access.
- `CATALOG_SYNC_UPDATE_ENABLED=false` disables UPDATE sync without removing preview access.

## What Is Forbidden

- UPDATE sync for content, images, categories, attributes, or media.
- Sync All.
- Automatic sync.
- Scheduled sync.
- Image import through sync.
- Trusting UI-selected state without server-side validation.
- Direct supplier import writes to catalog products.
- Supplier sync changing product workflow status except the initial CREATE default.

## Future Work / Open Questions

- Rollback tooling before broader writes.
- Sync All only after explicit design, auditability, and rollback.
