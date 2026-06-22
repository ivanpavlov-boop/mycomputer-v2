# Glossary

## Purpose

Define project-specific terminology so prompts, docs, code reviews, and developer discussions use the same language.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Supplier Import](SUPPLIER_IMPORT.md), [Matching Rules](MATCHING_RULES.md).

## Terms

| Term | Meaning |
| --- | --- |
| Supplier | Distributor/vendor such as APCOM, ASBIS, ALSO, PolyComp. |
| Supplier feed | XML/CSV/API source provided by a supplier. |
| Supplier product | A raw/staged product row from a supplier feed. |
| `supplier_products` staging | Staging table for supplier data before catalog sync. |
| Catalog product | Public `products` record used by the storefront/API. |
| Product offer | Supplier offer for a catalog product, usually in `product_supplier_offers`. |
| Active supplier offer | The chosen supplier offer for price/stock/source metadata. |
| Manual mapping | Explicit link between supplier product and catalog product. |
| Exact match | Safe match by EAN, supplier SKU within same supplier, MPN + Brand, or mapping. |
| Name similarity | Diagnostic-only warning based on similar names. Not a safe match. |
| Exclusion | Rule that prevents a staged supplier product from syncing into catalog. |
| `sync_action` | Preview-only action classification: CREATE, UPDATE, SKIP, CONFLICT, ERROR. |
| CREATE | Preview/write path for creating a new catalog product from eligible supplier row. |
| UPDATE | Preview-only currently; future path for safe updates to existing catalog products. |
| SKIP | Row is not eligible or should not be changed. |
| CONFLICT | Row needs review because automatic decision is unsafe. |
| ERROR | Row evaluation failed. |
| Content lock | Product-level flag protecting curated content from supplier overwrite. |
| Audit log | Record of sync actions, old/new values, user, status, and errors. |
| Rollback | Process for reverting sync changes from audit records. |
| Feature flag | Configuration switch that gates risky sync behavior. |

## Allowed

- Use these terms consistently in docs, PRs, and prompts.
- Add terms when new phases introduce new concepts.

## Forbidden

- Do not call supplier staging rows catalog products.
- Do not call name similarity a safe match.
- Do not call preview diagnostics a sync operation.

## Future Work / Open Questions

- Expand terms when Phase 8 UPDATE sync adds audit and rollback implementation details.
