# Roadmap

## Purpose

Track current phase status and planned Catalog Sync work.

Related docs: [Phases](PHASES.md), [Sync Safety](SYNC_SAFETY.md), [Architecture](ARCHITECTURE.md).

## Current Status

Manual selected UPDATE price/stock sync is implemented behind `CATALOG_SYNC_UPDATE_ENABLED`. Broader sync work remains paused until rollback tooling and additional ownership designs are complete.

## Completed

- Supplier import.
- APCOM staging import.
- Pricing preview.
- Exclusions preview.
- Matching preview.
- Sync action preview.
- Manual selected CREATE sync.
- CREATE candidate discovery.
- Read-only CREATE diagnostics.
- Catalog action filtering.
- Table scroll panel.
- Sticky header where safe.
- Counter layout.
- Catalog sync feature flags and selected CREATE audit trail.
- Manual selected UPDATE price/stock sync with audit old/new commercial values.

## Current Safety Position

- Selected CREATE sync is enabled.
- Selected UPDATE price/stock sync is feature-flagged and disabled by default unless `CATALOG_SYNC_UPDATE_ENABLED=true`.
- Sync All is not enabled.
- Automatic sync is not enabled.
- Scheduled sync is not enabled.
- Image import through sync is not enabled.
- Diagnostics are read-only.

## Next

1. Keep Phase 7.5 documentation lock current.
2. Fix any diagnostic cosmetic inconsistency if needed.
3. Rollback tooling based on `catalog_sync_batches` and `catalog_sync_logs`.
4. Keep feature flags locked down before broader sync work.
5. Conflict/manual mapping workflow.
6. Sync All later.
7. Automatic sync later.

## Phase 8 Initial UPDATE Scope

UPDATE sync must initially update only:

- price
- supplier cost
- stock / quantity
- availability
- active supplier offer

UPDATE sync must not update:

- name
- slug
- descriptions
- SEO
- images
- categories
- attributes/specifications

## Future Work / Open Questions

- Manual mapping workflow.
- Conflict review queue.
- Rollback UI.
- Supplier image import strategy.
- Controlled Sync All for eligible CREATE rows only.
- Scheduled preview generation before any scheduled writes.
