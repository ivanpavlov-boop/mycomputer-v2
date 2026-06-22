# Rollback Plan

## Purpose

Define what must be recorded so future sync changes can be reviewed and reversed.

Related docs: [Sync Safety](SYNC_SAFETY.md), [Catalog Sync](CATALOG_SYNC.md), [Roadmap](ROADMAP.md).

## Current Status

Manual selected CREATE sync exists. Broad UPDATE rollback is not implemented yet. This document defines required data before UPDATE sync is allowed.

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
- CREATE-only sync without UPDATE rollback if CREATE remains selected/manual and audited enough for current pilot needs.
- Planning rollback fields before implementation.

## What Is Forbidden

- Broad UPDATE sync without old/new values.
- Sync All without rollback plan.
- Automatic sync without batch-level audit and rollback.

## Future Work / Open Questions

- Implement sync batch records.
- Link `ProductSyncLog` records to batch IDs.
- Add rollback command or admin action.
- Add rollback tests for price/stock/availability changes.
