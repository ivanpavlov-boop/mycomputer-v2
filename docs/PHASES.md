# Catalog Sync Phases

## Purpose

Track completed and planned Catalog Sync phases.

Related docs: [Roadmap](ROADMAP.md), [Catalog Sync](CATALOG_SYNC.md), [Sync Safety](SYNC_SAFETY.md).

## Current Status

Feature development is paused at Phase 7.5 for architecture, safety, and documentation lock before Phase 8 UPDATE sync.

## Completed Phases

| Phase | Name | Status |
| --- | --- | --- |
| Phase 1 | Catalog Sync Preview UI | Complete |
| Phase 2 | Supplier product query | Complete |
| Phase 3 | Pricing preview | Complete |
| Phase 4 | Exclusions preview | Complete |
| Phase 5 | Matching visibility | Complete |
| Phase 6 | Sync action preview | Complete |
| Phase 7 | Manual selected CREATE sync | Complete |
| Phase 7.1 | CREATE candidate discovery | Complete |
| Phase 7.2 | CREATE diagnostics | Complete |
| Phase 7.5 | Architecture & Documentation Lock | In progress in this documentation pass |

## Next Planned Phases

| Phase | Name | Notes |
| --- | --- | --- |
| Phase 8 | Manual selected UPDATE for price/stock only | Must not update content/images/categories/attributes. |
| Phase 9 | Audit log and rollback support | Required before broad writes. |
| Phase 10 | Manual Sync All eligible CREATE | Later, after stronger audit controls. |
| Phase 11 | Scheduled preview generation | Preview only before scheduled writes. |
| Phase 12 | Controlled automatic sync | Last, behind feature flags and rollback. |

## Allowed

- Complete Phase 7.5 docs and safety rules.
- Add tests/docs before Phase 8.

## Forbidden

- Do not start Phase 8 inside Phase 7.5.
- Do not add Sync All.
- Do not enable automatic sync.

## Future Work / Open Questions

- Exact Phase 8 UI layout.
- Audit log schema.
- Rollback execution UX.
- Feature flag implementation.
