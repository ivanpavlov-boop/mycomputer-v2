# Testing

## Purpose

Define required validation for code, docs-adjacent changes, Catalog Sync work, and release readiness.

Related docs: [Sync Safety](SYNC_SAFETY.md), [Deployment](DEPLOYMENT.md), [Phases](PHASES.md).

## Current Status

The project has a broad Laravel feature test suite and GitHub Actions CI. Catalog Sync has dedicated feature coverage for preview, filters, pricing, exclusions, matching, action preview, selected CREATE sync, discovery, diagnostics, and read-only safeguards.

## Standard Commands

Run all backend tests:

```powershell
.\.tools\php\php.exe artisan test
```

Run Catalog Sync tests only:

```powershell
.\.tools\php\php.exe artisan test tests/Feature/CatalogSyncPreviewTest.php
```

Run Pint check:

```powershell
.\.tools\php\php.exe vendor\bin\pint --test
```

If using global PHP:

```bash
php artisan test
vendor/bin/pint --test
```

## What Is Allowed

- UI-only changes may use feature/view assertions.
- Read-only diagnostics must assert no products or `supplier_products` are modified.
- Write operations must test server-side validation and skipped/failed rows.
- Risky sync behavior requires regression tests.

## What Is Forbidden

- No merge with failing CI.
- Do not skip tests because a change appears small.
- Do not rely on live supplier feeds in tests.
- Do not require production services for normal test execution.

## Catalog Sync Test Expectations

Catalog Sync changes should verify:

- no unintended catalog writes
- no unintended staging writes
- filters are applied in the correct phase
- per-row failures do not crash the whole page/batch
- server-side validation rejects unsafe selected rows
- read-only diagnostics remain read-only

## Future Work / Open Questions

- Add more browser-level QA for Filament pages on VPS.
- Add snapshot-style docs linting if docs grow more complex.
- Add dedicated rollback/audit tests before Phase 8 UPDATE sync.
