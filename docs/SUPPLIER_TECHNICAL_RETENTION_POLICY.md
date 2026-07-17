# Supplier Technical Retention Policy

`supplier-technical-retention-policy-v1` records planning targets only. It
does not add cleanup jobs, schedulers, database fields, or deletion commands.

## Current Offer And Meaningful Change History

The intended future storage model keeps one current offer row per
supplier/product identity and records price or stock history only for
meaningful changes, not every duplicate snapshot.

## Retention Targets

| Record class | Planning target |
| --- | --- |
| Detailed technical import logs | 90 days |
| Raw supplier snapshots | 90 days |
| Summarized import runs | 24 months |
| Critical business and audit records | Indefinite |
| Archived catalog products | Indefinite unless separately manually reviewed |

## Product Records

Catalog product records and their audit history remain retained. This policy
does not authorize product deletion, soft deletion, unpublish, link changes,
or catalog-content changes.

## No Cleanup Execution In This Phase

There is no retention cleanup command, job, schedule, or automatic deletion in
Phase 9C.6.5C.3D. Implementation requires a separate migration/schema review,
operational approval, and retention-execution safety design.
