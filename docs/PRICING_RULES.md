# Pricing Rules

## Purpose

Document the current pricing rule behavior used by Catalog Sync Preview and future catalog sync.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Data Ownership](DATA_OWNERSHIP.md), [Supplier Import](SUPPLIER_IMPORT.md).

## Current Status

- Pricing rules exist and are used in Catalog Sync Preview.
- Prices are EUR-only.
- Supplier raw prices must stay unchanged.
- Calculated price is shown in preview.
- Pricing preview alone does not update catalog products.

## Rule Scopes

The pricing engine supports scoped rules. Current documented hierarchy:

1. Product
2. Category + Brand + Supplier
3. Category + Brand
4. Category + Supplier
5. Category
6. Brand
7. Supplier
8. Price Range
9. Global Default

The first matching active rule wins.

## Margin Types

Supported by the pricing architecture:

- percentage margin
- fixed amount margin
- minimum margin
- minimum final selling price
- optional rounding

Example:

| Supplier cost | Rule | Final selling price |
| --- | --- | --- |
| `100 EUR` | `20%` | `120 EUR` |
| `100 EUR` | fixed `15 EUR` | `115 EUR` |
| `100 EUR` | `20%` plus minimum profit `25 EUR` | `125 EUR` |

## MSRP / Recommended Price

Architecture prepares for supplier recommended prices. Strategies may include:

- margin price only
- recommended price only
- recommended price with minimum margin enforcement
- higher of margin price and recommended price
- lower of margin price and recommended price

Use only what is implemented in code; mark supplier-specific MSRP behavior as planned unless verified.

## VAT / Tax Handling

Supplier-level VAT modes are represented in the pricing architecture:

- VAT included
- VAT excluded
- intra-EU reverse charge

Do not hard-code BGN conversion. Do not hard-code Bulgarian VAT assumptions into docs or sync behavior. Store raw supplier prices unchanged.

## What Is Allowed

- Show calculated EUR price in Catalog Sync Preview.
- Use active pricing rules to preview supplier product prices.
- Apply pricing during explicitly allowed sync phases.

## What Is Forbidden

- Converting EUR to BGN.
- Overwriting raw supplier prices.
- Automatically repricing manually curated products without explicit admin action.
- Treating pricing preview as a write operation.

## Future Work / Open Questions

- Confirm default Global rule values in staging before pilot sync.
- Add more supplier/category rules only through Filament/admin workflows.
- Expand reporting around which inherited rule won for large sync batches.
