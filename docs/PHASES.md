# Catalog Sync Phases

## Purpose

Track completed and planned Catalog Sync phases.

Related docs: [Roadmap](ROADMAP.md), [Catalog Sync](CATALOG_SYNC.md), [Sync Safety](SYNC_SAFETY.md).

## Current Status

Phase 8 manual selected UPDATE price/stock sync has been implemented behind a feature flag. Broader sync work remains paused until rollback tooling and additional designs are complete.

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
| Phase 7.5 | Architecture & Documentation Lock | Complete |
| Phase 7.6 | Catalog Sync Safety Infrastructure | Complete |
| Phase 8 | Manual selected UPDATE for price/stock only | Complete |
| Phase 8.1 | Catalog Sync admin visibility | Complete |
| Phase 8.5B | Product workflow and configurable quality flags | Complete |
| Phase 8.6A | Multilingual foundation | Complete |
| Phase 9A | Product data quality and enrichment queue | Complete |
| Phase 9C.1 | Product attributes core foundation | Complete |
| Phase 9C.2 | Product attributes admin usability and starter structure | Complete |

## Next Planned Phases

| Phase | Name | Notes |
| --- | --- | --- |
| Phase 9 | Rollback support | Required before broad writes. |
| Phase 10 | Manual Sync All eligible CREATE | Later, after stronger audit controls. |
| Phase 11 | Scheduled preview generation | Preview only before scheduled writes. |
| Phase 12 | Controlled automatic sync | Last, behind feature flags and rollback. |

## Allowed

- Maintain Phase 7.5 docs, Phase 7.6 safety rules, and Phase 8 commercial-field allowlist.
- Maintain read-only Phase 8.1 feature flag and audit visibility.
- Add tests/docs before rollback or broader sync phases.
- Maintain product workflow and quality flags as admin/content controls, not supplier sync expansion.
- Add multilingual docs/config/schema without changing catalog sync execution behavior.
- Use the Product Data Quality Queue for read-only enrichment triage; corrections still happen through existing product edit workflows.
- Maintain Product Attributes as an internal catalog-owned foundation; supplier attribute mapping and frontend filters require later explicit phases.

## Forbidden

- Do not add Sync All.
- Do not enable automatic sync.
- Do not broaden UPDATE beyond price/stock/availability/supplier offer fields.
- Do not let supplier imports overwrite catalog attributes/specifications without a controlled preview and approval phase.

## Future Work / Open Questions

- Exact Phase 8 UI layout.
- Rollback tooling on top of the Phase 7.6 sync batch/log audit trail.
- Rollback execution UX.
