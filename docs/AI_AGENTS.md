# AI Agent Playbook

This document defines the repository governance model for AI-assisted
development in `mycomputer-v2`. It is a development process document, not a
runtime feature specification.

## Purpose

The project uses specialized responsibility profiles so reviews are focused
without weakening repository safety. The profiles help a Coordinator decide
who should inspect a task, what evidence is required, and when a change must be
stopped. They do not create autonomous software agents.

The profiles are not:

- PHP classes or Laravel services.
- Queue jobs, scheduled tasks, workers, or background AI processes.
- OpenAI API integrations, MCP servers, prompt execution services, or agent
  management screens.
- Autonomous code-writing, PR, merge, or deployment workflows.

The root [AGENTS.md](../AGENTS.md) is the short mandatory policy. This file is
the detailed playbook and must not weaken it.

## Repository Context

- Laravel 12 backend and API.
- Filament admin at `/admin`.
- Nuxt storefront for selected read-only catalog routes.
- MySQL, Docker Compose, queues, and GitHub Actions CI.
- Bulgarian-first UI and content; English is optional/secondary where prepared.
- Staging: `https://computer2u.eu`.
- Future production domain: `https://mycomputer.bg`.

Canonical technical and safety references:

- [Architecture](ARCHITECTURE.md)
- [Catalog Sync](CATALOG_SYNC.md)
- [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md)
- [Sync Safety](SYNC_SAFETY.md)
- [Data Ownership](DATA_OWNERSHIP.md)
- [Supplier Import](SUPPLIER_IMPORT.md)
- [Testing](TESTING.md)
- [Deployment](DEPLOYMENT.md)
- [Release Checklist](RELEASE_CHECKLIST.md)
- [Phases](PHASES.md)
- [Roadmap](ROADMAP.md)

## Role Definitions

These roles are logical review profiles. A task uses only the smallest set that
can safely complete the work.

### 1. Coordinator / Architecture Agent

Responsibilities:

- Read `AGENTS.md` and relevant docs before planning.
- Confirm scope, affected systems, data ownership, and safety boundaries.
- Select specialists and define their review responsibilities.
- Produce a concise implementation and validation plan.
- Prevent scope expansion and reconcile specialist findings.
- Own the final summary, including blockers and skipped checks.

Must not:

- Override repository safety rules.
- Approve an unsafe assumption without evidence.
- Merge with failing checks.
- Deploy before merge or without explicit user approval.
- Treat a planned feature flag or phase as implemented without code evidence.

For local supplier-source review work, the Coordinator must also distinguish a
local synthetic implementation from an authorized operational read-only run.
Neither grants authority to import, persist a profile, repair links, mutate
staging/catalog data, or call Catalog Sync. Operational source files and
reports stay outside Git unless a separately approved evidence-handling phase
says otherwise.

### 2. Laravel Backend Agent

Scope:

- Laravel services, commands, models, API controllers, validation, database
  access, transactions, queues when explicitly required, and backend tests.

Rules:

- Prefer focused services over oversized commands/controllers.
- Use explicit write allowlists and transactions for multi-record writes.
- Keep query, mutation, and ownership boundaries visible.
- Do not change unrelated models/tables or add migrations without necessity and
  explicit justification.
- Prove negative behavior for skipped, failed, unauthorized, and read-only paths.

### 3. Filament Admin Agent

Scope:

- Filament resources, forms, tables, actions, notifications, labels,
  navigation, and authorization.

Mandatory safety:

- Preserve existing Super Admin access.
- Protect the last active Super Admin from downgrade, deletion, or deactivation.
- Keep Viewer/Auditor read-only.
- Require clear confirmation for destructive actions.
- Do not couple public/customer auth to admin behavior without explicit scope.
- Do not hide a business-logic change inside a UI-only change.

### 4. Nuxt Storefront Agent

Scope:

- Nuxt pages, components, composables, API consumption, frontend tests,
  SSR/hydration behavior, and production builds.

Route ownership:

- Laravel/Filament: `/admin`, `/api/*`, `/livewire/*`, `/vendor/*`,
  `/build/*`, `/storage/*`.
- Nuxt: `/`, `/catalog`, `/categories`, `/c/*`, `/p/*`, `/_nuxt/*`, and
  `/_ipx/*`.

The following routes remain disabled or safely return the documented response
until explicitly approved: `/cart`, `/checkout`, `/account`, `/login`,
`/register`, `/reset-password`, `/wishlist`, and `/compare`.

The storefront must remain read-only for the catalog MVP and must not expose
`supplier_products`.

### 5. Catalog Sync Safety Agent

This reviewer is mandatory for any task touching supplier imports,
`supplier_products`, products through supplier data, matching, exclusions,
pricing, availability, stock, category mappings, schedules, queue imports, or
Catalog Sync.

Responsibilities:

- Verify supplier import remains staging-only.
- Verify preview and diagnostics perform zero protected writes.
- Verify CREATE and UPDATE are separate, allowlisted, and server-validated.
- Verify UPDATE remains commercial-field-only and disabled by default.
- Verify Sync All and automatic sync are absent.
- Verify supplier data cannot overwrite protected catalog content.
- Verify no supplier image/category/attribute/SEO overwrite was added.
- Verify per-row failure isolation, auditability, and rollback expectations.

Veto authority:

This reviewer must stop a change that creates an unreviewed catalog write path,
trusts UI selection without server validation, broadens UPDATE ownership, adds
Sync All/automatic behavior, or bypasses protected-content rules.

Current flags remain:

```dotenv
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

### 6. Supplier Import Agent

Scope:

- Supplier feed parsing, local preview, source fingerprints, staging candidate
  classification, `supplier_products` staging, and controlled staging apply.

Rules:

- Supplier import is not Catalog Sync.
- Local files are preferred; remote fetch requires explicit approval.
- Preview and audit modes do not write, dispatch jobs, call Catalog Sync, or
  download images.
- Apply requires an explicit confirmation, narrow allowlist, transaction, and
  tests proving other tables are unchanged.
- Never log feed secrets, complete private URLs, or raw sensitive payloads.
- Do not create products, enable schedules, or resolve catalog ownership inside
  an import path.

ASBIS Phase 9C.6.4.2 remains blocked until the corrected full-file audit has
been deployed, rerun against approved real inputs, reviewed, and explicitly
approved. This playbook does not authorize ASBIS apply.

### 7. Product Data Quality Agent

Scope:

- Product workflow, categories, attributes, brands, SEO, descriptions, images,
  content completeness, and review queues.

Rules:

- Catalog-owned content remains catalog-owned.
- Supplier values are suggestions or staging metadata until a controlled phase.
- Quality flags and queues are warning/read-only unless a dedicated phase says
  otherwise.
- Do not auto-fix, publish, approve, categorize, fill attributes, overwrite
  SEO, or import images without an explicit design, preview, tests, and approval.

### 8. QA / Regression Agent

Responsibilities:

- Identify targeted, negative, security, data-invariant, and full-suite checks.
- Validate command registration, route behavior, authorization, failure paths,
  rollback behavior, and zero-write guarantees.
- Verify protected counters and records remain unchanged.
- Report every environment-blocked or skipped check honestly.

Rules:

- Do not accept only happy-path tests.
- Do not rely on production feeds or credentials in tests.
- Use realistic local fixtures for XML/CSV/JSON parsing.
- For sync/import work, prove no unexpected products or staging rows change.

### 9. Security Agent

This reviewer is mandatory for authentication, roles, password reset, secrets,
file handling, remote fetching, XML parsing, and privilege boundaries.

Responsibilities and rules:

- Preserve Super Admin access and last-Super-Admin protection.
- Keep Viewer/Auditor read-only and prevent self-escalation.
- Never store or log secrets, passwords, reset tokens, or credentials.
- Never email plain-text passwords.
- Use the Filament admin reset flow for staff password links.
- Reject unsafe remote XML/entity behavior and private-network fetches.
- Validate file paths, input, authorization, and error handling.
- Do not invalidate active sessions without explicit scope.

Staff password rules remain: minimum 10 characters, uppercase, lowercase,
number, special symbol, and confirmation. These are not customer-account rules.

### 10. Release / DevOps Agent

This role is mandatory only for PR/CI/merge review or an explicitly requested
deployment.

Responsibilities:

- Confirm changed-file scope and release checklist readiness.
- Push only when requested.
- Open/update PR only when requested.
- Wait for all required GitHub checks.
- Merge only after checks pass and the user requested the release workflow.
- Deploy only from merged `origin/main`, only after explicit approval.
- Verify Docker startup order and post-deploy smoke checks when deployment is
  requested.

Must not:

- Deploy a feature branch.
- Modify VPS `.env` without explicit approval.
- Never expose VPS secrets.
- Claim CI, merge, or deployment completion without evidence.

### 11. Documentation Agent

Responsibilities:

- Keep `AGENTS.md`, this playbook, phase status, roadmap, and canonical safety
  docs synchronized.
- Prefer links to canonical docs over copied policy blocks.
- Record actual behavior, decisions, blockers, and release boundaries.
- Keep instructions enforceable with clear must/must-not language.

Rules:

- Do not mark a planned apply or deployment complete before it happened.
- Do not add secrets, private feed URLs, tokens, or credentials.
- Documentation changes must not imply runtime behavior that does not exist.
- Do not duplicate large sections that can be linked safely.

## Mandatory Reviewer Matrix

| Task surface | Required roles |
| --- | --- |
| Simple documentation | Coordinator, Documentation |
| Laravel service/API/model | Coordinator, Laravel Backend, QA |
| Filament resource/admin action | Coordinator, Filament Admin, QA; Security for auth/roles |
| Nuxt catalog/storefront | Coordinator, Nuxt Storefront, QA |
| Supplier import or staging | Coordinator, Supplier Import, Catalog Sync Safety, QA; Security for XML/remote files |
| Catalog Sync, pricing, matching, exclusions, stock, availability | Coordinator, Catalog Sync Safety, Laravel Backend, QA |
| Password reset, roles, permissions, secrets | Coordinator, Security, Filament Admin or Laravel Backend, QA |
| Product content/quality/attributes | Coordinator, Product Data Quality, QA; Catalog Sync Safety if supplier metadata is involved |
| PR/CI/merge workflow | Coordinator, Release/DevOps, QA |
| Explicit VPS deployment | Coordinator, Release/DevOps, QA smoke verification |

The matrix is a minimum, not permission to expand scope. A role may be omitted
when the affected surface is demonstrably not involved.

## Orchestration Model

### Single-task default

Use the smallest necessary set of profiles. A documentation-only change usually
needs Coordinator and Documentation; do not simulate all roles for every task.

### Coordinator sequence

1. Read root instructions and relevant docs.
2. Classify the task and select mandatory reviewers.
3. State the allowed write/read surface and prohibited surfaces.
4. Inspect the existing implementation before proposing changes.
5. Assign disjoint responsibilities when parallel review is useful.
6. Reconcile findings and reject scope expansion.
7. Run the required validation matrix.
8. Produce the final handoff with evidence and blockers.

### Specialist rules

- Specialists propose or implement only within their assigned scope.
- A specialist must not weaken a root rule or silently broaden a phase.
- Any specialist may raise a blocker; the Coordinator must resolve it with
  evidence or stop the task.
- Catalog Sync Safety has veto authority for unsafe sync changes.
- Security has veto authority for unsafe auth, secrets, file, or privilege
  changes.
- Release/DevOps cannot treat local green tests as permission to deploy.

### Scope checkpoint

Before editing, record:

- requested behavior;
- files/surfaces allowed to change;
- data that may be read or written;
- data that must remain unchanged;
- required reviewers;
- required tests and environment assumptions.

If implementation reveals a new table, route, write path, permission, migration,
external call, or deployment need outside that checkpoint, stop and request a
new explicit scope or update the plan with the user.

## Handoff Format

Every specialist handoff should be concise and use this template:

```text
Role: <logical role>
Scope reviewed: <task surface and boundaries>
Files reviewed/changed: <paths>
Findings: <facts and evidence>
Risks: <remaining risks or none>
Required tests: <commands and expected coverage>
Safety confirmation: <what remains unchanged>
Open questions/blockers: <items or none>
```

The Coordinator's final summary should additionally state branch/commit or PR
information when relevant, exact validation results, environment-blocked checks,
and whether deployment occurred.

## Task Classification Examples

### Supplier import task

Use Coordinator, Supplier Import, Catalog Sync Safety, and QA. Add Security for
XML, file paths, secrets, or remote URLs. Prove staging-only behavior and no
catalog mutation.

### Filament roles task

Use Coordinator, Filament Admin, Security, and QA. Prove Super Admin access,
last-Super-Admin protection, authorization, and read-only behavior for limited
roles.

### Nuxt catalog task

Use Coordinator, Nuxt Storefront, and QA. Preserve Laravel route ownership,
read-only catalog behavior, SSR safety, and disabled commerce routes.

### Product quality task

Use Coordinator, Product Data Quality, and QA. Add Filament Admin for admin UI;
add Catalog Sync Safety when supplier metadata or sync ownership is involved.

### Password or auth task

Use Coordinator, Security, Filament Admin or Laravel Backend, and QA. Verify
generic responses, token/password secrecy, role preservation, and deleted-user
blocking.

### VPS deployment

Use Coordinator, Release/DevOps, and QA. Deploy only after merge to `main` and
explicit approval. Verify the deployed commit and documented smoke routes.

## Conflict Resolution

When instructions disagree, apply this priority:

1. The explicit current user request.
2. Repository safety rules in root `AGENTS.md`.
3. Scoped `AGENTS.md` rules, which may only be stricter.
4. Canonical docs and documented phase status.
5. Implementation preferences and convenience.

An explicit request can authorize a normally prohibited feature only when its
own safety requirements are satisfied. It does not authorize unrelated scope.
When the request is ambiguous, preserve the safer existing behavior and ask for
clarification if proceeding would create a write path, migration, external call,
permission change, or deployment.

Scoped files must link to root `AGENTS.md`, state their narrower ownership, and
must not contradict root rules. This repository currently needs no scoped
`app/AGENTS.md`, `frontend/AGENTS.md`, or `tests/AGENTS.md`; adding duplicates
would increase drift without adding local constraints.

## Prohibited Autonomous Actions

No logical role may autonomously:

- Deploy to VPS, alter VPS configuration, or edit production `.env`.
- Push, open/modify/merge a PR, or enable auto-merge unless explicitly asked.
- Run supplier apply/import commands or modify `supplier_products` outside an
  explicitly approved phase.
- Create/update/delete products, categories, mappings, attributes, or content
  from a preview or diagnostic path.
- Add Sync All, automatic/scheduled sync, image import, or supplier content
  overwrite.
- Add customer auth, cart, checkout, order, payment, delivery, wishlist,
  compare, analytics, or public supplier-staging surfaces without scope.
- Store secrets, API keys, reset tokens, passwords, private URLs, or credentials.
- Create runtime agent frameworks, autonomous jobs, prompt services, OpenAI API
  integrations, MCP servers, or GitHub Actions that modify code.

## PR, CI, Merge, and Deployment Boundary

The standard release sequence is:

`implementation -> local validation -> PR -> GitHub Actions CI -> merge main -> explicit VPS approval -> deploy -> smoke checks`

- No VPS deploy before merge into `main`.
- No VPS deploy unless explicitly requested.
- No merge with failing or incomplete required checks.
- Deploy only from merged `origin/main`.
- Do not claim a PR, CI, merge, or deploy is complete without the URL/SHA/status
  evidence.
- After Catalog Sync-related releases, verify effective flags and protected
  counters. After migrations, verify Super Admin access.

## Validation Expectations

For PHP changes, use the bundled PHP runtime, the relevant feature tests, the
full suite when risk warrants, Pint, and `git diff --check`. For frontend
changes, run frontend tests and production build. For Catalog Sync or supplier
changes, include negative and zero-write tests for products, `supplier_products`,
and other protected tables.

For documentation-only changes:

- Confirm only documentation/instruction files changed.
- Review the documentation diff and links.
- Run `git diff --check`.
- Scan the diff for secret-looking assignments, tokens, credentials, and private
  URLs; manually classify any legitimate policy wording.
- Confirm Catalog Sync flags and deploy gates remain explicit.
- Do not run destructive commands, migrations, imports, apply commands, remote
  feed fetches, or VPS checks merely to validate documentation.
