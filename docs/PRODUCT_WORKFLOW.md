# Product Workflow

## Purpose

The product workflow controls manual catalog publication. It is server-authoritative, role-aware, atomic, and separate from supplier staging and Catalog Sync. Quality flags remain advisory and non-blocking in this phase.

`ProductWorkflowService` is the authoritative transition boundary. Filament action visibility is only a usability aid; every executed transition is authorized and validated again inside the service.

## States

| State | Meaning | Public |
| --- | --- | --- |
| `draft` | Manual work in progress. | No |
| `pending_review` | Submitted for review. | No |
| `changes_requested` | Returned with a required correction note. | No |
| `approved` | Approved and ready for an explicit publish action. | No |
| `published` | Published, subject to all public-scope conditions. | Yes |

Approval never publishes automatically.

## Transition Matrix

| Action | From | To | Required role capability |
| --- | --- | --- | --- |
| Submit for review | `draft`, `changes_requested` | `pending_review` | Edit product content |
| Request changes | `pending_review`, `approved`, `published` | `changes_requested` | Approve products |
| Approve | `pending_review` | `approved` | Approve products |
| Publish | `approved` | `published` | Publish products |
| Hide | `published` | `approved` | Publish products |

No other transition is allowed. Invalid transitions fail with a validation exception, unauthorized transitions fail with an authorization exception, and neither kind of failure mutates the product.

Role behavior:

| Role | Submit | Request changes | Approve | Publish / hide |
| --- | --- | --- | --- | --- |
| Super Admin | Yes | Yes | Yes | Yes |
| Catalog Manager | Yes | Yes | Yes | Yes |
| Product Editor | Yes | No | No | No |
| Product Data Entry | Yes | No | No | No |
| Pricing Manager | No | No | No | No |
| Inventory Manager | No | No | No | No |
| SEO / Marketing | No | No | No | No |
| Order Manager | No | No | No | No |
| Viewer / Auditor | No | No | No | No |

Inactive, soft-deleted, and non-admin users cannot transition products.

## Atomic State Changes

Each transition runs in a database transaction and locks the product row. The service compares the caller's observed state with the locked state, so a stale action fails instead of overwriting a newer transition.

The workflow updates the status and coupled public fields together:

- Submit: sets `submitted_by` and `submitted_at`; keeps the product hidden.
- Request changes: requires `review_notes`, sets `returned_by` and `returned_at`, and hides the product.
- Approve: sets `approved_by` and `approved_at`; keeps the product hidden.
- Publish: validates technical prerequisites, sets `published_by` and `published_at`, then sets `active=true` and `product_status=active`.
- Hide: moves the product to `approved`, sets `active=false` and `product_status=hidden`, and preserves publication metadata.

Actor and timestamp columns record the latest occurrence of their corresponding transition. Existing metadata for other transitions is preserved. Deleted staff accounts remain resolvable in historical actor fields through soft-deleted relations. There is no generic product activity log in the current architecture, so this phase does not invent a speculative history table.

## Workflow-Owned Fields

Normal Filament create and edit payloads cannot set:

- `source`
- `workflow_status`
- `product_status`
- `active`
- `published_at`
- `created_by`
- `submitted_by`, `submitted_at`
- `approved_by`, `approved_at`
- `published_by`
- `returned_by`, `returned_at`
- `review_notes`

The product form displays workflow state and metadata read-only. Workflow changes occur only through the explicit Bulgarian Filament actions, backed by the server-side workflow service.

Normal product form saves are also restricted by field domain on the server: content roles cannot submit price or stock changes, Pricing Manager can submit only pricing fields, and Inventory Manager can submit only stock and availability fields. Product attribute editing and the bulk availability action follow the same capability checks.

Manual Filament creation always forces:

```text
source=manual
workflow_status=draft
product_status=draft
active=false
published_at=null
```

A crafted manual form payload cannot claim `source=supplier_import` or self-publish. Supplier and Catalog Sync creation paths retain their existing controlled defaults and are not routed through the manual form.

Generic product CSV creation follows the manual path as well: newly imported CSV products are explicitly `manual`, `draft`, inactive, and unpublished. CSV price and stock update modes retain their existing scoped behavior, but a product CSV row cannot activate or publish a product.

## Publishability

Publishing requires only the current technical public prerequisites:

- non-empty product name;
- non-empty unique slug;
- non-empty SKU;
- an active category;
- a non-deleted product in the `approved` state.

Images, specifications, English content, SEO completeness, and quality flags are not publication blockers in this phase. English remains optional and Bulgarian fallback behavior is unchanged.

## Public Visibility

`Product::published()` is the canonical public query scope. A product is public only when all conditions hold:

- it is not soft-deleted;
- `active=true`;
- `workflow_status=published`;
- `product_status=active`;
- `published_at` is not null;
- the slug is present;
- the category is active.

Product collections, direct details, category and brand listings, related and accessory products, homepage sections, database search, Meilisearch hydration, sitemaps, and marketing feeds use this boundary. `Product::shouldBeSearchable()` uses the same conditions. A non-public direct product request returns 404 and does not expose workflow metadata.

Soft-deleted products cannot transition or appear publicly. Restoring a formerly published product moves it to the safe `approved` and hidden state while retaining prior publication metadata; restore never republishes automatically.

## Filament Publishing UX

After a successful explicit Publish transition, Filament redirects the administrator to the Product list and keeps a Bulgarian success notification available across the redirect. The notification includes a `Виж в сайта` action that opens the primary Bulgarian `/p/{slug}` storefront page in a new tab.

The Product edit page and Product table also expose `Виж в сайта` only when `Product::isPubliclyVisible()` passes. The storefront URL is generated from the configured application origin, so a future domain change does not require a code change. Unpublished, hidden, invalid-category, or soft-deleted products never receive a storefront action.

Submit for review, Request changes, Approve, and Hide remain on the edit page. Hide refreshes the record and form, removes the storefront actions immediately, and preserves publication history. This phase does not provide draft preview, admin bypass links, or public access to unpublished products.

### Compact Product Table

The default Product table is optimized for high-volume administration. Its nine visible columns are: image, copyable SKU, product name, category, brand, price, a color-coded workflow-status dot with a Bulgarian tooltip, availability with quantity, and `Виж в сайта`.

The Product name column is bounded to 420 px, wraps to at most two visible lines, and retains the complete name in its tooltip. The primary supplier company name appears as its muted description when assigned. `Вносител` remains a separate searchable, sortable, read-only column in the column chooser, hidden by default. Thumbnails are square 52 px images, and the status dot uses a larger compact size while retaining its tooltip and accessible Bulgarian label.

Authorized administrators open the existing Product edit page by selecting a normal row. Copying SKU uses Filament's native copy interaction and does not navigate away. `Виж в сайта` is a dedicated new-tab link only for products that pass `Product::isPubliclyVisible()`; non-public products display `—` and have no storefront URL.

Quality flags, specification quality, promotional and inventory details, manual override, boolean merchandising flags, and update timestamp remain available through the column manager but are hidden by default. Existing filters and bulk actions are unchanged. Restore and permanent deletion stay available to authorized users in the compact row action group. The table eagerly loads its displayed relationships; `Product::isPubliclyVisible()` retains the same public-visibility rule and uses an already-loaded category when available.

## Maintenance Exception

The existing allowlisted `catalog:review-auto-created-products` remediation command remains an explicit maintenance exception. Its mutation now uses the same transaction, stale-state check, row lock, and visibility coupling as the workflow service. A `draft` target uses `product_status=draft`; a `pending_review` target uses `product_status=hidden`. Both targets remain inactive and non-public.

## Catalog Sync Safety

This workflow hardening does not change supplier import or Catalog Sync behavior:

- supplier feeds continue to stage in `supplier_products`;
- controlled manual CREATE remains the catalog creation path;
- UPDATE remains disabled by default;
- Sync All and automatic sync remain disabled;
- supplier data cannot overwrite managed names, slugs, descriptions, SEO, images, categories, attributes, or localized content.

No migration or production data backfill is part of this workflow hardening.

The publishing UX described above does not alter supplier import or Catalog Sync behavior.
