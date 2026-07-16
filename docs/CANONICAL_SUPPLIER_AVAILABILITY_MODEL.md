# Canonical Supplier Availability Model

## Purpose

This document defines supplier-neutral, immutable status vocabulary for
read-only supplier onboarding previews. It does not add database columns,
storefront rendering, supplier import behavior, or Catalog Sync integration.

## Supplier-Neutral Statuses

`CanonicalSupplierAvailabilityStatus`:

- `in_stock`
- `limited`
- `on_request`
- `out_of_stock`
- `unknown`

`CanonicalSupplierLifecycleStatus`:

- `active`
- `eol`
- `discontinued`
- `unknown`

`CanonicalPublicAvailabilityStatus`:

- `in_stock`
- `limited`
- `on_request`
- `last_units`
- `unavailable`
- `discontinued`
- `unknown`

The types are reusable by APCOM, ASBIS, and future suppliers. They do not make
one supplier's source semantics global.

## Availability And Lifecycle

Availability and lifecycle are separate facts. A supplier mapper produces both
facts and a preview-only computed public status:

- `in_stock` plus `active` -> `in_stock`;
- `limited` plus `active` -> `limited`;
- `on_request` plus `active` -> `on_request`;
- positive stock plus `eol` -> `last_units`; and
- zero stock plus `eol` -> `discontinued`.

Unknown or invalid input produces `unknown`. No computed status is written to
`products` or `supplier_products` in this phase.

## Exact Quantity Privacy

`public-supplier-quantity-policy-v1` sets
`public_exact_quantity_allowed=false`. Supplier snapshot quantities are only
possible internal supplier-offer metadata. They are not customer-facing stock
promises and are not real-time guarantees.

## Supplier-Specific Mappers

Each supplier owns its field semantics and mapper. APCOM currently has
`apcom-availability-policy-v1`; it does not apply to ASBIS or any future
supplier. The mapper contract is immutable and read-only.

## Multi-Supplier Aggregation

Future catalog availability must aggregate independently mapped supplier
offers. APCOM EOL cannot make a catalog product EOL if another supplier has an
active offer. One supplier's out-of-stock result cannot override another
supplier's in-stock result. Runtime aggregation is not implemented here.

## Phase Boundary

This phase makes no storefront change, no import change, and no Catalog Sync
integration. UPDATE remains disabled, Sync All remains disabled, automatic sync
remains disabled, and supplier images remain prohibited.
