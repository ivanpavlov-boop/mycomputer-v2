# Product Attributes

## Purpose

Product Attributes define internal, structured catalog characteristics such as RAM, SSD capacity, processor, screen size, warranty, operating system, GPU, refresh rate, cable length and compatibility.

This foundation is for catalog-owned product specifications. It does not enable supplier XML attribute sync, frontend attribute filters, Sync All, automatic sync, image import, or supplier-driven product mutations.

## Current Phase 9C.2 Admin Usability And Starter Structure

Phase 9C.2 improves the Filament admin experience for the internal attribute library and adds a safe starter structure command.

Admin areas:

- `Характеристики`: internal attribute definitions such as RAM, storage capacity, screen size, warranty and compatibility.
- `Опции на характеристики`: controlled options for `select` and `multiselect` attributes.
- `Категорийни характеристики`: category-to-attribute assignment rules.

The admin UI is intentionally manual and controlled. Attribute definitions, options and category assignments do not automatically create product attribute values.

Useful admin conventions:

- `Код` is a stable internal key such as `ram` or `storage_capacity`. It should use lowercase letters, numbers and `_`, and should not be changed after use.
- `Филтър` means the attribute may be used later in catalog filters.
- `Видима` means the attribute may be shown later on product pages.
- `Сравнима` means the attribute may be used later in product comparison.
- `Задължителна` is a category/quality hint and does not block products in this phase.

### Starter Command

Preview the starter attribute library without writing anything:

```bash
php artisan product-attributes:seed-starter
php artisan product-attributes:seed-starter --dry-run
```

Apply the starter attribute library manually:

```bash
php artisan product-attributes:seed-starter --apply
```

The command is idempotent and safe to run repeatedly. It creates missing internal attribute definitions and missing controlled options only. It does not delete records and does not overwrite existing admin-edited labels.

Starter attributes include:

- `ram`
- `storage_capacity`
- `storage_type`
- `processor`
- `gpu`
- `screen_size`
- `resolution`
- `refresh_rate`
- `panel_type`
- `color`
- `operating_system`
- `warranty_months`
- `interface`
- `connectors`
- `cable_length`
- `compatibility`
- `power_watts`
- `weight`

Starter options are included where useful, such as RAM sizes, storage type/capacity, screen size, refresh rate, panel type, color and operating system.

Category assignments are deferred by default. Future category-specific starter sets can be added only through an explicit controlled phase that does not create categories, rename categories, overwrite categories, mutate product category assignments, or populate product attribute values automatically.

Safety guarantees:

- existing products are not mutated
- `supplier_products` are not mutated
- `product_attribute_values` are not auto-filled
- supplier XML attributes are not parsed or synced
- frontend attribute filters are not exposed
- Sync All is not added
- automatic sync is not enabled
- supplier image import is not added

## Phase 9C.1 Foundation

Phase 9C.1 keeps the existing compatibility tables and extends them with a clearer internal product attribute foundation.

Core tables:

- `product_attributes`: internal attribute definitions.
- `attribute_values`: controlled options for `select` and `multiselect` attributes.
- `category_product_attributes`: category-to-attribute assignment rules.
- `product_attribute_values`: product-specific values for manual or future controlled assignments.

Existing normalization tables remain separate:

- `canonical_attributes`
- `canonical_attribute_values`
- `supplier_product_attributes`
- `attribute_mapping_logs`
- `category_attribute_templates`

Those tables support supplier/raw normalization workflows, but supplier attribute writes are not expanded in this phase.

## Attribute Definitions

`product_attributes` stores the internal definition:

- `code`: stable internal key, for example `ram`, `ssd_capacity`, `screen_size`.
- `name_bg`: Bulgarian label and primary admin language.
- `name_en`: optional English label.
- `description_bg` and `description_en`: optional admin descriptions.
- `type`: one of `text`, `number`, `boolean`, `select`, `multiselect`, `decimal`, `json`.
- `unit`: optional display unit such as `GB`, `TB`, `inch`, `Hz`, `W`.
- `is_filterable`: marks future filter eligibility, but does not expose storefront filters yet.
- `is_visible_on_product`: marks future public product-page display eligibility.
- `is_comparable`: marks future comparison eligibility.
- `is_required_by_default`: default requirement hint.
- `is_active`: active admin definition flag.

`attribute_values` is the existing controlled-option table for select/multiselect values. It is intentionally reused instead of adding a duplicate `product_attribute_options` table.

## Category Assignments

`category_product_attributes` defines which internal attributes belong to a category.

Each row stores:

- category
- product attribute
- required flag
- future filterable flag
- visible-on-product flag
- comparable flag
- sort order

The assignment is an internal admin rule. It does not mutate existing products and does not create product attribute values automatically.

## Product Attribute Values

`product_attribute_values` stores actual catalog product values.

The table keeps existing compatibility columns:

- `attribute_value_id`
- `custom_value`
- canonical attribute links where present

Phase 9C.1 adds typed storage columns for future controlled workflows:

- `value_text`
- `value_number`
- `value_boolean`
- `value_json`
- `unit`
- `source`
- `is_verified`
- `sort_order`

No existing products are backfilled in this phase. Product values must be added manually or through a future explicitly approved controlled workflow.

## Admin

Filament exposes Bulgarian admin management for:

- Product Attributes
- Attribute Values
- Category Product Attributes

These screens are protected by existing product-management authorization. Super Admin retains access through the existing bypass. Viewer/Auditor roles are not given mutation permissions.

## Supplier Safety

The correct future supplier flow remains:

```text
Supplier XML
-> supplier_products staging
-> raw supplier attributes / supplier metadata
-> mapping to internal product attributes
-> preview
-> manual/controlled approval
-> catalog product attributes
```

Phase 9C.1 does not implement that full supplier flow.

Forbidden in this phase:

- parsing new supplier XML attributes
- importing supplier attributes into catalog products
- automatic supplier attribute updates
- supplier category/attribute overwrite
- supplier image import
- frontend attribute filters
- Sync All
- automatic sync
- enabling UPDATE sync

Price, stock and availability updates remain separate from content and attribute management. Supplier import continues to write only to staging unless a future phase explicitly designs and tests controlled attribute sync.

## Future Phases

Planned follow-up work:

1. Supplier raw attribute capture review.
2. Supplier-to-internal attribute mapping UI.
3. Preview-only attribute mapping diagnostics.
4. Manual controlled attribute sync with audit logs.
5. Storefront product specification display.
6. Frontend attribute filters and facets.

Each future write phase must include server-side validation, preview, auditability and tests proving products and `supplier_products` are not mutated unexpectedly.
