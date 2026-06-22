# Roadmap

## Purpose

Track current phase status and planned Catalog Sync work.

Related docs: [Phases](PHASES.md), [Sync Safety](SYNC_SAFETY.md), [Architecture](ARCHITECTURE.md).

## Current Status

Feature development is paused after Phase 7.6 Catalog Sync Safety Infrastructure before Phase 8 UPDATE sync.

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

## Current Safety Position

- Only selected CREATE sync is enabled.
- UPDATE sync is not enabled.
- Sync All is not enabled.
- Automatic sync is not enabled.
- Scheduled sync is not enabled.
- Image import through sync is not enabled.
- Diagnostics are read-only.

## Next

1. Keep Phase 7.5 documentation lock current.
2. Fix any diagnostic cosmetic inconsistency if needed.
3. Phase 8: manual selected UPDATE for price/stock only.
4. Rollback tooling based on `catalog_sync_batches` and `catalog_sync_logs`.
5. Keep feature flags locked down before Phase 8.
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
