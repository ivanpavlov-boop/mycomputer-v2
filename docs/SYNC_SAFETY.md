# Sync Safety

## Purpose

Define mandatory safety rules for real catalog write operations.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Data Ownership](DATA_OWNERSHIP.md), [Rollback Plan](ROLLBACK_PLAN.md), [ADR](ADR.md).

## Current Status

Manual selected CREATE sync is enabled. Manual selected UPDATE sync exists only for price, supplier cost, stock, availability, and supplier offer metadata, and remains disabled unless `CATALOG_SYNC_UPDATE_ENABLED=true`. Sync All, automatic sync, scheduled sync, and image sync are not enabled.

## Required Rules Before Any Write

- Server-side validation before every write.
- Do not trust UI-selected state.
- Per-row try/catch.
- Batch result summary.
- Idempotency checks.
- No destructive actions by default.
- No overwrite of protected content.
- Failed rows must not stop the full batch.

## Required Before UPDATE Sync

- Audit log with old/new values.
- Rollback plan and batch ID.
- Feature flags / kill switches.
- Tests for skipped, failed, and successful rows.
- Clear field allowlist.

## Active Feature Flags

Catalog sync write surfaces are controlled by `config/catalog_sync.php`.

```dotenv
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

Safe defaults:

- selected CREATE sync is enabled by default
- UPDATE sync is disabled
- Sync All is disabled
- automatic/scheduled sync is disabled

If `CATALOG_SYNC_CREATE_ENABLED=false`, manual selected CREATE sync is blocked server-side. The UI may also disable the action, but the server guard is authoritative.

Catalog Sync Preview shows the effective feature flag values from `config/catalog_sync.php` so admins can confirm safety state before using manual actions. The panel is read-only and does not write to `.env` or configuration.

## Active Audit Trail

Manual selected CREATE sync now writes audit records to:

- `catalog_sync_batches`
- `catalog_sync_logs`

Each selected CREATE run creates a batch with:

- user
- mode
- selected count
- created/skipped/failed totals
- batch UUID
- start/completion timestamps

Each selected row records:

- supplier product
- supplier
- catalog product when created
- action
- status
- reason
- safe old/new values
- error message for failures

CREATE logs store no destructive old values because they create new draft catalog products only.

Manual selected UPDATE logs store old/new values only for the commercial fields this phase may change:

- price / regular price / final selling price
- purchase price / supplier raw price / recommended price
- quantity / stock status / availability status
- supplier ID / supplier SKU
- external supplier availability labels
- selected supplier offer ID

Admins can review audit history in Filament through read-only Catalog Sync Batches and Catalog Sync Logs resources. These resources expose list/detail views only; they do not allow create, edit, delete, rollback, Sync All, or automatic sync actions.

## What Is Allowed

- CREATE can be enabled independently.
- UPDATE can be enabled independently for selected price/stock rows only.
- Read-only preview/diagnostics can run without write flags.
- UPDATE must revalidate safe exact matching server-side before every write.

## What Is Forbidden

- UPDATE sync for name, slug, SEO, descriptions, images, categories, attributes, or media.
- Sync All before audit/rollback/feature flags.
- Automatic sync before controlled scheduled preview and rollback exist.
- Image/category/attribute sync without explicit ownership design.

## Future Work / Open Questions

- Add rollback tooling on top of sync batch/log records.
- Add emergency kill switch documentation to deployment checklist.
