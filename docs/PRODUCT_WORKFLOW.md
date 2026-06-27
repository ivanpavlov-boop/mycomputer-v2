# Product Workflow

## Purpose

Phase 8.5B adds an explicit admin workflow for manually curated products without changing supplier import or broad catalog sync behavior.

## Statuses

| Status | Meaning |
| --- | --- |
| `draft` | Manual work in progress. Not publicly visible. |
| `pending_review` | Submitted for Catalog Manager or Super Admin review. |
| `changes_requested` | Returned for correction with optional review notes. |
| `approved` | Approved but not publicly visible yet. |
| `published` | Publicly visible when `active=true` and `published_at` is set. |

## Defaults

- Manually created products default to `draft`.
- Supplier/catalog-sync-created products default to `published`.
- Existing visible products are backfilled as `published`.
- Existing hidden/inactive products keep hidden behavior and are backfilled as `draft`.

## Actions

Filament product edit pages expose workflow actions where allowed:

- Submit for review
- Request changes
- Approve
- Publish
- Unpublish

Only Super Admin and Catalog Manager can approve or publish products. Product editors and data entry users can submit eligible draft/correction products for review.

## Content Safety

Workflow does not allow supplier sync to overwrite manually curated content. Supplier UPDATE remains limited to documented commercial fields:

- price
- supplier cost
- stock / quantity
- availability
- selected supplier offer metadata

Supplier sync must not overwrite product name, slug, SEO, descriptions, images, categories, attributes, or localized manual content.

