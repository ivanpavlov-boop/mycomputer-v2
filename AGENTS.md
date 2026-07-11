# AGENTS.md

Guidance for AI-assisted development in `mycomputer-v2`.

## Project

MyComputer.bg / COMPUTER2U is a Laravel 12 application with Filament admin,
Nuxt storefront, MySQL, Docker Compose, Laravel queues, and GitHub Actions CI.
Bulgarian is the primary language; English is a secondary language prepared for
the storefront and admin where implemented. Staging is `computer2u.eu`; the
future production domain is `mycomputer.bg`.

Before changing code, read this file and the relevant canonical docs:

- [Architecture](docs/ARCHITECTURE.md)
- [Catalog Sync](docs/CATALOG_SYNC.md)
- [Sync Safety](docs/SYNC_SAFETY.md)
- [Catalog Sync Safety](docs/CATALOG_SYNC_SAFETY.md)
- [Data Ownership](docs/DATA_OWNERSHIP.md)
- [Supplier Import](docs/SUPPLIER_IMPORT.md)
- [Testing](docs/TESTING.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Release Checklist](docs/RELEASE_CHECKLIST.md)
- [Phases](docs/PHASES.md)
- [AI Agent Playbook](docs/AI_AGENTS.md)

The detailed role model and orchestration rules are in [docs/AI_AGENTS.md](docs/AI_AGENTS.md).

## Required Workflow

1. Coordinator confirms scope and reads the relevant docs.
2. Select the smallest necessary specialist roles.
3. Implement only the requested scope.
4. Run targeted tests, then the appropriate regression suite.
5. Run Pint for PHP changes and `git diff --check`.
6. Open a PR only when explicitly requested.
7. Wait for all required CI checks; never merge failing CI.
8. Deploy only from merged `origin/main`, only after explicit approval.
9. Run documented post-deploy smoke checks when deployment is requested.

No agent may deploy from a feature branch or recommend deployment before merge.
No agent may merge, push, create a PR, or deploy when the user has prohibited it.

## Core Commands

Use the bundled PHP runtime on this workstation:

```powershell
.\.tools\php\php.exe artisan test
.\.tools\php\php.exe vendor\bin\pint --test
.\.tools\php\php.exe .\.tools\composer.phar test
cd frontend
cmd /c npm run test -- --run
cmd /c npm run build
cd ..
git diff --check
```

Documentation-only changes normally require scope, link, diff, secret-pattern,
and `git diff --check` validation instead of an unnecessary full application
test run. Code, configuration, migrations, routes, workflows, and frontend
changes require the relevant tests and build checks.

## Catalog Sync Safety

The controlled flow is:

`supplier feed -> supplier_products staging -> preview -> pricing, exclusions, matching -> sync_action -> manually selected sync`

- Supplier import must stage data in `supplier_products`; it must not directly
  create or update catalog products.
- Manual selected CREATE is the only enabled catalog creation path.
- Manual selected UPDATE is limited to price, supplier cost, quantity/stock,
  availability, and selected supplier-offer metadata.
- UPDATE remains disabled by default:
  `CATALOG_SYNC_UPDATE_ENABLED=false`.
- Keep `CATALOG_SYNC_SYNC_ALL_ENABLED=false` and
  `CATALOG_SYNC_AUTO_ENABLED=false`.
- No Sync All button, command, or automatic path may be added without an
  explicit controlled phase. Automatic sync, scheduled sync, and image sync
  are not enabled.
- Real writes require server-side revalidation, explicit allowlists, per-row
  failure isolation, and audit/result summaries.
- Preview and diagnostic commands must not write protected tables.
- The Catalog Sync Safety Agent has veto authority over unsafe sync changes.

Supplier data must not automatically overwrite product name, slug, SEO,
descriptions, images, categories, attributes/specifications, or localized
manual content. Supplier image/category/attribute/SEO changes require their own
reviewed phase.

Do not implement ASBIS Phase 9C.6.4.2 without an explicit request and approved
design. The current ASBIS readiness audit remains read-only and Phase 9C.6.4.2
is blocked until the corrected real audit is deployed, rerun, reviewed, and
explicitly approved.

## Admin and Security Safety

- Existing active Super Admin access must be preserved.
- The last active Super Admin must not be downgraded, deleted, or deactivated.
- Prefer soft deletion for staff users; deleted users cannot log in, access
  Filament, or reset passwords.
- Only Super Admin manages users and roles unless a documented permission says
  otherwise. Viewer/Auditor remains read-only.
- Do not invalidate active sessions without an explicit requirement.
- Staff password rules require at least 10 characters, uppercase, lowercase,
  number, special symbol, and confirmation.
- Never email or log plain-text passwords. Never log reset tokens or secrets.
- Password reset links for staff must use the Filament admin reset flow.

## Nuxt Route Ownership

Laravel/Filament owns `/admin`, `/api/*`, `/livewire/*`, `/vendor/*`,
`/build/*`, and `/storage/*`.

Nuxt owns only the safe read-only storefront routes `/`, `/catalog`,
`/categories`, `/c/*`, `/p/*`, `/_nuxt/*`, and `/_ipx/*`.

`/cart`, `/checkout`, `/account`, `/login`, `/register`, `/reset-password`,
`/wishlist`, and `/compare` remain disabled or safely return the documented
placeholder/404 until explicitly approved. Do not expose `supplier_products`.

## Role Selection

Use the smallest necessary set of logical roles. The Coordinator reads the
request and selects specialists; specialists do not create runtime agents.

- Laravel Backend: services, commands, models, APIs, validation, database, and
  backend tests.
- Filament Admin: resources, forms, tables, actions, labels, and authorization.
- Nuxt Storefront: pages, components, composables, routes, frontend tests, and
  builds.
- Catalog Sync Safety: mandatory reviewer for supplier, staging, product-sync,
  price/stock, matching, availability, mapping, schedule, or sync changes.
- Supplier Import: feed parsing, local preview, staging classification, and
  controlled staging apply mechanics.
- Product Data Quality: workflow, content, attributes, SEO, images, and
  read-only quality queues.
- QA/Regression: targeted/full tests, negative cases, invariants, and zero-write
  guarantees.
- Security: auth, roles, password reset, secrets, XML/file safety, and privilege
  boundaries.
- Release/DevOps: PR, CI, merge, deployment gates, and smoke verification only
  when that workflow is explicitly requested.
- Documentation: canonical docs, phase status, links, and safety decisions.

Security is mandatory for auth, roles, password reset, secrets, remote fetch,
file handling, or XML parsing. Catalog Sync Safety is mandatory for any supplier
or catalog-sync surface. Release/DevOps is mandatory for PR/CI/merge review or
an explicitly requested deployment.

## Scope Boundaries

Documentation-only changes must remain documentation-only. UI-only changes must
not hide business logic. Do not add migrations, runtime agents, autonomous AI
jobs, OpenAI integrations, MCP servers, customer commerce flows, Sync All,
automatic sync, supplier content overwrite, or image import without an explicit
phase, safety design, tests, and user approval.

When instructions conflict, apply this priority:

1. Current explicit user request.
2. Repository safety rules in this file.
3. Scoped `AGENTS.md` rules, which may only be stricter.
4. Canonical docs and phase status.
5. Implementation preferences.

Scoped instruction files should be added only when they express genuinely
different local rules. They must link back to this file and must not weaken it.
