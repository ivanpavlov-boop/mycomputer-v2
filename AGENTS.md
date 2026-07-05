# AGENTS.md

Guidance for coding agents working on `mycomputer.bg` v2.

## Purpose

This file defines project-specific safety rules for Codex and other coding agents. Read it before changing supplier import, catalog sync, pricing, exclusions, matching, deployment, or Filament admin behavior.

Start with these docs:

- [Architecture](docs/ARCHITECTURE.md)
- [Catalog Sync](docs/CATALOG_SYNC.md)
- [Sync Safety](docs/SYNC_SAFETY.md)
- [Data Ownership](docs/DATA_OWNERSHIP.md)
- [Supplier Import](docs/SUPPLIER_IMPORT.md)
- [AI Agents](docs/AI_AGENTS.md)
- [Catalog Sync Safety Playbook](docs/CATALOG_SYNC_SAFETY.md)
- [Release Checklist](docs/RELEASE_CHECKLIST.md)
- [Testing](docs/TESTING.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Roadmap](docs/ROADMAP.md)
- [Phases](docs/PHASES.md)

## Current Status

The supplier-to-catalog flow is intentionally staged and controlled:

Supplier XML/CSV -> `supplier_products` staging -> Catalog Sync Preview -> pricing rules -> exclusion rules -> matching -> `sync_action` preview -> manual selected CREATE/UPDATE sync -> catalog products.

Selected CREATE sync is enabled. Selected UPDATE price/stock sync is feature-flagged and limited to supplier-controlled commercial fields. Sync All, automatic sync, scheduled sync, and image sync are not enabled.

## General Rules

- Before coding, read `AGENTS.md` and the relevant docs.
- Documentation-only changes must remain documentation-only.
- UI-only changes must remain UI-only.
- Supplier import must not directly create or update catalog products.
- Preview must happen before real catalog sync writes.
- Do not add Sync All unless explicitly requested.
- Do not broaden UPDATE sync beyond the documented Phase 8 commercial-field allowlist unless there is a dedicated design and safety plan.
- Do not enable automatic or scheduled catalog sync unless explicitly requested and documented.
- Every PR must run tests and Pint.
- No merge with failing CI.
- No VPS deploy before merge into `main`.
- Do not deploy to VPS unless explicitly requested.
- Follow the release checklist for PR, CI, merge, deploy, and post-deploy smoke tests.
- Return validation results and safety confirmations when handing work back.

## Project Architect

Allowed:

- Clarify architecture in docs.
- Add ADRs for sync, ownership, import, rollback, or deployment decisions.
- Propose future phases in [Roadmap](docs/ROADMAP.md) and [Phases](docs/PHASES.md).

Forbidden:

- Adding write paths without safety docs, tests, and explicit user request.
- Treating planned feature flags as implemented until code exists.

## Supplier Import

Allowed:

- Import XML/CSV supplier data into staging.
- Preserve raw supplier payloads in `supplier_products.raw_data`.
- Improve validation, logging, and safe feed handling.

Forbidden:

- Creating or updating catalog products directly from supplier import.
- Storing live feed secrets in source control or docs.
- Running destructive catalog changes from import jobs.

See [Supplier Import](docs/SUPPLIER_IMPORT.md).

## Catalog Sync

Rules:

- CREATE and UPDATE sync are separate phases.
- Manual selected CREATE sync is currently allowed.
- Manual selected UPDATE sync is allowed only for price, supplier cost, stock, availability, and supplier offer metadata when `CATALOG_SYNC_UPDATE_ENABLED=true`.
- Sync All is not currently allowed.
- Automatic sync is not currently allowed.
- Real write operations require server-side validation.
- Do not trust UI-selected state.
- Per-row try/catch is required for batch actions.
- Batch result summary is required.

See [Catalog Sync](docs/CATALOG_SYNC.md) and [Sync Safety](docs/SYNC_SAFETY.md).

## Data Ownership / Content Safety

Supplier data may update only safe supplier-controlled fields unless explicitly approved.

Supplier data may update:

- supplier cost
- calculated price
- stock / quantity
- availability
- supplier offer
- source metadata

Supplier data must not automatically overwrite:

- product name
- slug
- SEO title
- SEO description
- short description
- full description
- manually edited content
- images
- categories
- attributes/specifications

Existing locks: `lock_name`, `lock_seo`, `lock_descriptions`.

See [Data Ownership](docs/DATA_OWNERSHIP.md) and [Content Locks](docs/CONTENT_LOCKS.md).

## QA / Testing

Run before handing work back:

```powershell
.\.tools\php\php.exe artisan test
.\.tools\php\php.exe vendor\bin\pint --test
```

Catalog Sync changes require feature tests. Risky sync behavior requires regression tests that prove no unintended products or `supplier_products` are modified.

See [Testing](docs/TESTING.md).

## DevOps / Deployment

Rules:

- Deploy only from `origin/main`.
- Deploy only after the PR is merged into `main`.
- Deploy only when the user explicitly requests deployment.
- Start app containers before nginx.
- Verify with `curl -I http://localhost:8080`.
- If nginx cannot resolve upstream `app`, start `app` first, wait, then start nginx.

See [Deployment](docs/DEPLOYMENT.md) and [Release Checklist](docs/RELEASE_CHECKLIST.md).

## AI Agent Playbook

Use [AI Agents](docs/AI_AGENTS.md) for project AI/Codex roles and review checklists.
These roles are documentation and process guidance only; they are not autonomous
production agents, scheduled jobs, background workers, or data-mutating AI code.

## Filament UI

Allowed:

- Improve readability, spacing, filters, tables, and safe diagnostics.
- Add view tests for admin pages when useful.

Forbidden:

- Hiding business logic changes inside UI changes.
- Enabling write actions from the UI without server-side validation and tests.
- Adding UPDATE sync or Sync All buttons unless explicitly requested.

## Safe Change Boundaries

Ask or document clearly before changing:

- Authentication strategy.
- Customer/order/payment behavior.
- Inventory reservation.
- Supplier import execution jobs.
- Search engine choice.
- Nuxt frontend structure.
- Production deployment setup.
- Catalog sync write behavior.
