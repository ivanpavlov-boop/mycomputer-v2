# Rollback Plan

## Purpose

Define what must be recorded so future sync changes can be reviewed and reversed.

Related docs: [Sync Safety](SYNC_SAFETY.md), [Catalog Sync](CATALOG_SYNC.md), [Roadmap](ROADMAP.md).

## Current Status

Manual selected CREATE sync exists. Manual selected UPDATE price/stock sync exists behind `CATALOG_SYNC_UPDATE_ENABLED`. Broad UPDATE rollback is not implemented yet.

Phase 7.6 adds the first audit infrastructure:

- `catalog_sync_batches`
- `catalog_sync_logs`

Manual selected CREATE sync records created/skipped/failed rows and safe CREATE summaries. Manual selected UPDATE price/stock sync records old/new commercial values for each row. This is audit support, not a full rollback tool.

Filament exposes read-only Catalog Sync Batches and Catalog Sync Logs views so admins can inspect batch UUIDs, row statuses, reasons, safe old/new values, metadata, and errors before rollback tooling exists.

## Required Audit Data Before UPDATE Sync

Every sync write should store:

- sync batch ID
- user ID
- supplier ID
- supplier product ID
- catalog product ID
- action
- old price
- new price
- old stock
- new stock
- old availability
- new availability
- status
- error message
- timestamps

## Rollback Expectations

- Every UPDATE must be traceable.
- Every changed field must have old and new value.
- Failed rows must not stop the full batch.
- Rollback should be possible by sync batch.
- Rollback should be tested before broad sync features.

## What Is Allowed

- Manual review of existing logs.
- Read-only Filament review of sync batches and logs.
- CREATE-only sync without UPDATE rollback if CREATE remains selected/manual and audited enough for current pilot needs.
- Selected UPDATE price/stock sync if it remains manually selected, feature-flagged, audited, and limited to commercial fields.
- CREATE batch/log records exist for traceability.

## What Is Forbidden

- Broad UPDATE sync without old/new values.
- UPDATE sync that touches content, images, categories, or attributes.
- Sync All without rollback plan.
- Automatic sync without batch-level audit and rollback.

## Future Work / Open Questions

- Build rollback commands/admin actions from `catalog_sync_batches` and `catalog_sync_logs`.
- Decide whether `ProductSyncLog` should also reference catalog sync batches.
- Add rollback command or admin action.
- Add rollback tests for price/stock/availability changes.
