# Architecture Decision Records

## Purpose

Record important architecture decisions for mycomputer.bg v2. New decisions should be appended, not silently rewritten.

Related docs: [Architecture](ARCHITECTURE.md), [Catalog Sync](CATALOG_SYNC.md), [Sync Safety](SYNC_SAFETY.md).

## Current Status

Initial ADRs cover supplier staging, preview-first catalog changes, CREATE/UPDATE separation, content ownership, and forbidden broad sync behavior.

## ADR-001: Supplier Products Are Imported Into Staging First

Decision: Supplier import writes to `supplier_products` staging and does not directly create or update catalog `products`.

Reason:

- Supplier feeds can be incomplete, wrong, duplicated, or temporarily broken.
- Staging preserves raw data for review and diagnostics.
- Catalog products require explicit sync decisions.

Consequences:

- Import jobs can run without immediately changing public catalog data.
- Catalog Sync Preview is required before writes.

## ADR-002: Catalog Changes Must Go Through Preview

Decision: No supplier data should modify catalog products without Catalog Sync Preview and validation.

Reason:

- Preview exposes pricing, exclusions, matching, sync action, and diagnostics.
- It prevents blind supplier-driven catalog changes.

Consequences:

- Sync work must remain preview-first.
- Future UPDATE sync must be designed as a separate phase.

## ADR-003: CREATE And UPDATE Sync Are Separate Phases

Decision: Manual selected CREATE sync is enabled first. UPDATE sync requires separate safety design.

Reason:

- CREATE and UPDATE have different risks.
- UPDATE can overwrite existing catalog state.

Consequences:

- Phase 8 must not be bundled into CREATE work.
- UPDATE must start with safe commercial fields only.

## ADR-004: Supplier Data Must Not Overwrite Protected Content

Decision: Name, slug, descriptions, SEO, images, categories, and attributes are protected unless explicitly approved.

Reason:

- Catalog content may be translated or curated by admins.
- Supplier text is not the editorial source of truth.

Consequences:

- Content locks must be respected.
- Future sync write scopes must be explicit.

## ADR-005: Sync All And Automatic Sync Are Forbidden Until Designed

Decision: No Sync All or automatic sync may be added without roadmap approval, audit log, rollback, and feature flags.

Reason:

- Broad writes can affect thousands of products.
- Rollback and auditability must exist before broad execution.

Consequences:

- Only selected CREATE sync is enabled now.
- Any broad sync requires a new ADR or roadmap update.

## What Is Allowed

- Add ADRs for new sync safety decisions.
- Update an ADR by appending a superseding decision.

## What Is Forbidden

- Do not implement features that contradict active ADRs.
- Do not silently rewrite historical decisions.

## Future Work / Open Questions

- ADR for Phase 8 UPDATE scope.
- ADR for rollback/audit implementation.
- ADR for future image/category/attribute sync ownership.
