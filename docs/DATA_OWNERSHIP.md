# Data Ownership

## Purpose

Define which system owns each type of catalog data so supplier sync does not overwrite manually curated work.

Related docs: [Content Locks](CONTENT_LOCKS.md), [Catalog Sync](CATALOG_SYNC.md), [Sync Safety](SYNC_SAFETY.md).

## Current Status

Supplier data is staged first. Catalog products are the public product records. Manual admin edits are considered curated catalog content and must be protected.

## Ownership Model

| Data Area | Owner | Current Sync Position |
| --- | --- | --- |
| Supplier cost | Supplier feed / pricing engine | Safe to update through sync. |
| Calculated price | Pricing rules | Safe to update through sync when allowed. |
| Stock / quantity | Supplier feed | Safe to update through sync when allowed. |
| Availability | Supplier feed / availability mapping | Safe to update through sync when allowed. |
| Supplier offer | Supplier offer selection | Safe to update through sync when allowed. |
| Source metadata | Supplier/import system | Safe to update through sync when allowed. |
| Product name | Catalog/editorial | Must not be overwritten automatically. |
| Slug | Catalog/editorial | Must not be overwritten automatically. |
| SEO title/description | Catalog/editorial | Must not be overwritten automatically. |
| Short/full description | Catalog/editorial | Must not be overwritten automatically. |
| Images | Catalog/editorial | Not enabled through current sync. |
| Categories | Catalog/editorial/category mapping | Must not be overwritten automatically. |
| Attributes/specifications | Catalog/editorial/normalization | Must not be overwritten automatically without design. |

## What Supplier Data May Update

- supplier cost
- calculated price
- stock / quantity
- availability
- supplier offer
- source metadata

## What Supplier Data Must Not Automatically Overwrite

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

## Allowed

- Updating safe commercial fields through explicitly allowed sync phases.
- Preserving raw supplier data in staging.
- Using preview diagnostics to show what would happen.

## Forbidden

- Treating supplier text as the owner of Bulgarian product names/descriptions.
- Overwriting manually curated content because a supplier feed changed.
- Replacing images/categories/specifications during current CREATE/diagnostic work without explicit design.

## Future Work / Open Questions

- Phase 8 UPDATE sync must initially update only price/stock/availability/supplier offer fields.
- Image, category, and attribute ownership need separate designs before sync writes.
- More granular locks may be needed for images, categories, and attributes.
