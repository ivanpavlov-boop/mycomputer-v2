# Content Locks

## Purpose

Document lightweight field locks that protect manually curated product content from supplier sync.

Related docs: [Data Ownership](DATA_OWNERSHIP.md), [Catalog Sync](CATALOG_SYNC.md), [Matching Rules](MATCHING_RULES.md).

## Current Status

Products include lightweight lock flags:

| Field | Protects |
| --- | --- |
| `lock_name` | Product name. |
| `lock_seo` | SEO title and SEO description. |
| `lock_descriptions` | Short description and full description. |

These fields exist on `products`, are cast on the `Product` model, and are exposed in the Product Filament form.

## Why Locks Exist

Supplier feeds often provide English, incomplete, or distributor-oriented descriptions. Admins may translate, rewrite, or optimize product content. Future supplier sync must not overwrite that work.

## What Is Allowed

- Admins may enable locks for manually curated products.
- Sync code must check locks before writing protected fields.
- Supplier sync may still update safe commercial fields such as stock, availability, supplier offer, and price where allowed.

## What Is Forbidden

- Supplier sync must not overwrite locked content.
- Locks must not block safe stock/availability/supplier-cost updates.
- Lock support must not be bypassed by UI state.

## Protected Areas

- product name
- SEO title
- SEO description
- short description
- full description

## Planned / Not Implemented Yet

Future locks may be needed for:

- images
- categories
- attributes/specifications

These future locks are not implemented as part of this document.
