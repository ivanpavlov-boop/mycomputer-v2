# Supplier Import

## Purpose

Document how supplier XML/CSV feeds enter the system and where supplier data is allowed to go.

Related docs: [Architecture](ARCHITECTURE.md), [Catalog Sync](CATALOG_SYNC.md), [Data Ownership](DATA_OWNERSHIP.md), [Supplier Exclusions](SUPPLIER_EXCLUSIONS.md).

## Current Status

- Supplier import is implemented for staged supplier data.
- APCOM XML staging import is working and writes into `supplier_products`.
- XML import preserves supplier payloads in staging.
- Supplier import does not create or update catalog `products` directly.
- Catalog changes must go through Catalog Sync Preview and the currently allowed selected CREATE sync path.

## Current Flow

```text
Supplier XML/CSV feed
-> safe feed download
-> XML/CSV parsing and mapping
-> supplier_products staging
-> Catalog Sync Preview
-> selected manual CREATE sync when eligible
```

## Feed Download

Supplier feeds are downloaded through SSRF-aware protection and feed import services. Production feeds must use configured supplier feed records or environment/configuration values, not hard-coded secrets.

Current configuration knobs:

| Variable | Purpose |
| --- | --- |
| `SUPPLIER_FEED_HTTP_CONNECT_TIMEOUT` | Connection timeout for supplier feed requests. |
| `SUPPLIER_FEED_HTTP_TIMEOUT` | Full HTTP request timeout for supplier feed requests. |

Large feeds may require longer request timeout values. Timeout increases must preserve SSRF protection.

## APCOM Notes

- APCOM feed data is staged in `supplier_products`.
- The real feed has large XML and many image references.
- APCOM category strings may contain multiple category paths.
- The full category string is preserved in `raw_data`.
- `category_name` should contain the selected primary mapped path, not an unbounded raw multi-path string.
- Recent VPS diagnostics scanned `1859` APCOM supplier products and found `0` CREATE candidates.
- Most rows were matched, excluded, already linked, or had no meaningful changes.

## Staging Data

`supplier_products` is the staging table. It may contain:

- supplier ID
- supplier SKU
- EAN
- MPN
- product name
- brand name
- category name
- supplier price
- quantity
- availability/status
- raw payload in `raw_data`
- pricing-related raw fields
- mapped availability fields

## What Is Allowed

- Download supplier feeds through approved services.
- Validate XML/CSV structure.
- Store raw supplier data in staging.
- Update staged supplier rows from feed data.
- Record failed imports and import history.
- Use availability and attribute normalization during staging where implemented.

## What Is Forbidden

- Do not create catalog products directly from supplier import.
- Do not update catalog products directly from supplier import.
- Do not store supplier feed secrets in source control or docs.
- Do not delete catalog products because a supplier feed is empty.
- Do not mark products out of stock from an empty/failed feed without safety checks.

## Failure Handling

Failed supplier feeds must not break the catalog. Import failures should be logged and isolated to the import job/run. Catalog products should only change through the Catalog Sync path.

## Future Work / Open Questions

- More supplier-specific mapping templates.
- More precise import-run linkage across `supplier_products`, failed imports, import history, and sync logs.
- Supplier-specific category normalization before large-scale sync.
- Supplier image import remains not enabled through Catalog Sync.
