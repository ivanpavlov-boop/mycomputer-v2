# Project AI Agents

## Purpose

This document defines safe AI/Codex agent roles for MyComputer.bg /
COMPUTER2U. These are process and review roles only. They are not autonomous
production agents, scheduled jobs, background workers, or code paths that
change production data.

Use this document with:

- [AGENTS](../AGENTS.md)
- [Release Checklist](RELEASE_CHECKLIST.md)
- [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md)
- [Catalog Sync](CATALOG_SYNC.md)
- [Product Attributes](PRODUCT_ATTRIBUTES.md)
- [Deployment](DEPLOYMENT.md)
- [Sync Safety](SYNC_SAFETY.md)

## Global AI Agent Rules

AI agents may:

- Read project docs and source code.
- Propose safe designs.
- Add or update documentation.
- Implement explicitly requested scoped changes.
- Add tests for the requested scope.
- Review diffs for safety regressions.
- Create PRs and report validation results when explicitly requested.

AI agents must never:

- Deploy to VPS unless explicitly requested.
- Deploy before a PR is merged into `main`.
- Add autonomous production agents.
- Add scheduled AI jobs or background AI workers.
- Add AI code that changes production data.
- Add Sync All.
- Enable automatic catalog sync.
- Enable UPDATE sync by default.
- Change supplier import into a catalog write path.
- Mutate products, `supplier_products`, `product_attribute_values`, or
  `category_product_attributes` outside an explicitly requested and tested
  phase.
- Add supplier image import, supplier category overwrite, supplier attribute
  overwrite, or supplier SEO overwrite without a dedicated phase.
- Add secrets, tokens, SMTP passwords, API keys, or real credentials to docs or
  source control.

## Catalog Sync Safety Agent

### Purpose

Review every PR touching supplier imports, Catalog Sync, catalog products,
`supplier_products`, product prices, stock, availability, supplier XML,
scheduled jobs, queue jobs, or product sync services.

### Hard Rules

- Supplier import writes only to `supplier_products` staging.
- Supplier import must not create catalog products.
- Supplier import must not update catalog products.
- Manual CREATE sync is allowed only through explicit controlled admin preview
  and action.
- UPDATE sync is limited to price, supplier cost, stock, availability, and
  selected supplier offer metadata.
- `CATALOG_SYNC_UPDATE_ENABLED` must remain false by default.
- Sync All must not be added.
- Automatic sync must not be enabled.
- Supplier data must not overwrite product name, slug, SEO, descriptions,
  images, categories, attributes, or localized manual content.
- Supplier image import requires a separate controlled phase.
- Supplier category and attribute overwrite require separate preview and
  approval phases.

### PR Review Checklist

- Confirm changed files do not hide sync behavior inside UI-only or docs-only
  work.
- Confirm imports remain staging-only.
- Confirm Catalog Sync writes still require server-side validation.
- Confirm UI-selected state is not trusted.
- Confirm per-row try/catch remains in write paths.
- Confirm tests prove products and `supplier_products` are not unexpectedly
  mutated.
- Confirm Sync All and automatic sync remain absent.

## Release / Deploy Guard Agent

### Purpose

Ensure release workflow is followed consistently.

### Required Workflow

1. Codex implementation prompt.
2. Local validation.
3. Pull request.
4. GitHub Actions CI.
5. Merge into `main`.
6. VPS deploy only after merge and only when explicitly requested.
7. Post-deploy smoke tests.

### Release Rules

- Never deploy to VPS before PR merge.
- Never deploy from a feature branch for normal release.
- Do not skip tests or Pint unless the user explicitly accepts the risk.
- Do not merge with failing CI.
- After relevant migrations, verify an active Super Admin still exists.
- After Catalog Sync-related phases, verify effective feature flags.

### Post-Deploy Smoke Tests

- `/catalog`
- `/categories`
- `/c/{known-category-slug}`
- `/p/{known-product-slug}`
- `/api/v1/products`
- `/admin`
- `/cart` remains disabled or returns the expected safe response until cart is
  explicitly enabled.

## Product Attributes Architecture Agent

### Purpose

Protect the internal product specification model.

### Correct Model

- `product_attributes` are global internal definitions.
- `category_product_attributes` are category specification templates.
- `product_attribute_values` are product-specific values.

### Rules

- Characteristics are selected or defined for categories first.
- Product edit should show category-driven ready fields.
- Empty fields must not create `product_attribute_values` rows.
- Product values are stored only when manually saved or later approved through
  a controlled mapping phase.
- Do not auto-fill product attribute values.
- Do not let supplier XML directly overwrite product values.
- Future supplier XML mapping must be preview-first and manually approved.
- Frontend attribute filters require a later dedicated phase.

## Product Data Quality Agent

### Purpose

Identify missing or weak product data without changing products automatically.

### Allowed Reports

- Laptop without RAM.
- Monitor without screen size.
- TV without resolution.
- Product without warranty.
- Product without SEO/meta.
- Product without image.
- Product without brand/category/content.

### Rules

- Report gaps and warnings.
- Do not auto-fix without explicit approval.
- Do not block product save unless a later phase explicitly implements blocking
  quality rules.
- Keep queue listing, filtering, and searching read-only unless explicitly
  changed in a future phase.

## Supplier XML Mapping Preview Agent

### Purpose

Prepare future controlled mapping from raw supplier attributes to internal
catalog attributes.

### Rules

- Supplier XML raw attributes may be mapped to internal `product_attributes`.
- Mapping must be preview-first.
- No direct overwrite.
- No automatic approval.
- No supplier image import.
- No category overwrite.
- No attribute overwrite without an explicit controlled phase.

### Correct Future Flow

```text
Supplier XML
-> supplier_products staging
-> raw supplier attributes
-> mapping to internal attributes
-> preview
-> manual/controlled approval
-> product_attribute_values
```

## Storefront QA Agent

### Purpose

Validate Nuxt storefront behavior while keeping the read-only catalog MVP safe.

### Routes To Check

- `/catalog`
- `/categories`
- `/c/{slug}`
- `/p/{slug}`
- `/api/v1/products`
- `/admin` remains Laravel/Filament
- `/cart` remains disabled or safe until the cart phase

### Rules

- Do not add cart, checkout, orders, payments, delivery, wishlist, compare,
  analytics, feeds, or customer accounts unless explicitly requested.
- Do not expose `supplier_products` publicly.
- Do not add frontend attribute filters until a dedicated phase.
- Product links and catalog pages must remain read-only in the storefront MVP.

## Security / Roles Agent

### Purpose

Protect admin users, roles, authentication, and lockout safety.

### Rules

- Existing active Super Admin must not be locked out.
- Check active `super_admin` after relevant migrations.
- Do not downgrade, delete, or deactivate the last active Super Admin.
- Soft delete users where deletion is supported.
- Soft-deleted users must not log in or reset passwords.
- Only Super Admin manages users and roles.
- Viewer/Auditor remains read-only.
- Password reset is for admin/staff/back-office users.
- Do not email plain text passwords.
- Do not log passwords or reset tokens.

### Strong Staff Password Rules

- Minimum 10 characters.
- Uppercase.
- Lowercase.
- Number.
- Special symbol.
- Confirmation.

## Safe Action Examples

- Safe: Add documentation clarifying that supplier import is staging-only.
- Safe: Add a read-only diagnostic panel with tests proving no writes.
- Safe: Add a dry-run command that reports intended changes without writing by
  default and is allowlisted.
- Unsafe: Make supplier import create catalog products directly.
- Unsafe: Add a Sync All button without design, audit, rollback, and tests.
- Unsafe: Let supplier XML overwrite product names, categories, images, or
  attributes.
- Unsafe: Deploy a feature branch to VPS before PR merge.

## Phase-Specific Safety Notes

- Phase 9C.4.2 stopped an old scheduled supplier import path from creating
  catalog products automatically.
- Phase 9C.4.3 moved the three known automatically created products into manual
  review through a dry-run-first allowlisted command.
- Future phases touching supplier XML attributes, product specification values,
  storefront filters, Sync All, automatic sync, or image import must include a
  dedicated design, preview path, auditability, tests, and explicit user
  approval.
