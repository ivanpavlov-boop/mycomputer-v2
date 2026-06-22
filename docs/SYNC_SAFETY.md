# Sync Safety

## Purpose

Define mandatory safety rules for real catalog write operations.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Data Ownership](DATA_OWNERSHIP.md), [Rollback Plan](ROLLBACK_PLAN.md), [ADR](ADR.md).

## Current Status

Only manual selected CREATE sync is enabled. UPDATE sync, Sync All, automatic sync, scheduled sync, and image sync are not enabled.

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

## Planned Feature Flags

These flags are documented as desired safety controls. Treat them as planned unless verified in code.

```dotenv
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

## What Is Allowed

- CREATE can be enabled independently.
- Read-only preview/diagnostics can run without write flags.
- Future UPDATE may be added only with explicit scope and tests.

## What Is Forbidden

- UPDATE sync before Phase 8 design.
- Sync All before audit/rollback/feature flags.
- Automatic sync before controlled scheduled preview and rollback exist.
- Image/category/attribute sync without explicit ownership design.

## Future Work / Open Questions

- Implement actual feature flags if not already present.
- Add rollback-backed audit tables before broad UPDATE.
- Add emergency kill switch documentation to deployment checklist.
