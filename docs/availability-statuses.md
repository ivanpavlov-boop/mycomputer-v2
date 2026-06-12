# Availability Statuses

The Product Availability system separates product lifecycle status from sellable availability.

Product status is an internal merchandising lifecycle value such as `draft`, `active`, `hidden`, `archived` or `discontinued`.

Availability status is admin-managed through Filament and controls storefront labels, colors, icons, purchase behavior and stock display.

## Database

- `availability_statuses`: dynamic internal statuses.
- `availability_status_mappings`: external supplier, ERP, XML, CSV, API or manual statuses mapped to internal availability statuses.
- `products.availability_status_id`: current product availability.
- `products.availability_message`: optional storefront message.
- `products.expected_date`: optional expected availability date.
- `products.supplier_lead_time_days`: optional lead time.
- `products.manual_override`: prevents automatic sync from changing the assigned availability status.
- `products.external_availability_status`: last external status received.
- `products.external_availability_label`: last external status label received.
- `supplier_products.external_availability_status`: raw supplier-side status staged before catalog sync.
- `supplier_products.availability_status_id`: mapped status for diagnostics and future sync decisions.

## Admin Workflow

Filament resources:

- `AvailabilityStatusResource`
- `AvailabilityStatusMappingResource`

Administrators with `manage availability statuses` can:

- create, edit, disable and reorder statuses
- change labels, colors, icons and badge style
- control `allow_purchase`
- control `show_stock_quantity`
- create source mappings for suppliers, ERP, XML, CSV and API feeds

Default seeded statuses:

- `in_stock`
- `limited_stock`
- `incoming`
- `preorder`
- `on_request`
- `out_of_stock`
- `discontinued`

These are seed defaults, not hardcoded business rules. Admins can add custom statuses such as "Delivery Today", "Supplier Warehouse" or "Last Unit".

## Mapping Fallback

`AvailabilityStatusMapper::mapWithFallback()` resolves status in this order:

1. exact `source_type + source_code + external_status`
2. generic `source_type + external_status`
3. manual generic external status
4. quantity-based status through `AvailabilityStatusService`
5. default active availability status

## Purchase And Stock Rules

`AvailabilityStatus.allow_purchase` controls whether the product can be added to cart.

`AvailabilityStatus.show_stock_quantity` controls whether checkout must reserve physical stock. This allows statuses such as preorder and on request to be purchasable without requiring current quantity.

Legacy `stock_status` is still mirrored for backward compatibility with existing modules and feeds.

## API Payload

Product card and detail resources include:

```json
{
  "availability": {
    "code": "preorder",
    "name": "Preorder",
    "color": "blue",
    "icon": "clock",
    "badge_style": "soft",
    "allow_purchase": true,
    "show_stock_quantity": false,
    "message": "Expected in 7 days",
    "expected_date": "2026-07-01",
    "supplier_lead_time_days": 7
  }
}
```

Public product list endpoints support:

- `availability`
- `availability_status`
- `availability_statuses[]`

Search index fields:

- `availability_status`
- `availability_status_code`
- `availability_status_name`
- `availability_sort_order`
- `allow_purchase`

## Import Usage

XML and CSV imports store external statuses in staging data first. Product sync maps supplier products into catalog products using the mapper.

CSV supports:

- `availability_status`
- `external_availability_status`
- `external_availability_label`
- `availability_message`
- `expected_date`
- `supplier_lead_time_days`

Supplier feeds should map external status fields into `external_availability_status` and optionally `external_availability_label`.

## Merchandising Usage

CMS product blocks can filter by:

- `availability_status`
- `availability_statuses`

Product bundles and checkout use `AvailabilityStatusService` to respect dynamic purchase behavior.

## Stock Alerts And Analytics

Back-in-stock automation triggers when the mirrored stock status becomes `in_stock` or `limited_stock`.

Tracked events:

- `product_out_of_stock_view`
- `product_preorder_view`
- `product_incoming_view`
- `stock_alert_signup`
- `availability_status_click`

## Staging Validation

Before launch:

- create one custom status in Filament
- create supplier, ERP, XML and CSV mappings
- import a supplier feed with external availability fields
- sync a supplier product into the catalog
- verify product list/detail API payloads
- verify search filters and Meilisearch reindex
- verify add-to-cart blocks statuses with `allow_purchase = false`
- verify preorder/on-request statuses with `allow_purchase = true`
- verify Nuxt product cards and product detail badges in browser
