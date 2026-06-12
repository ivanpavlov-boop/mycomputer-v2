# Complete Implementation Report

## 1. Project Structure

### Modules

- Product Catalog
  - Categories
  - Brands
  - Products
  - Product Images
  - Attribute Groups
  - Product Attributes
  - Attribute Values
  - Product Attribute Assignments
  - Related Products
  - Accessory Products

- Supplier Management
  - Suppliers
  - Supplier Feeds
  - Supplier Products staging table
  - Supplier Feed Items legacy/staging table

- XML Import Engine
  - XML Mapping Templates
  - Import Jobs
  - Import History
  - Failed Imports
  - XML import queue job
  - Scheduled supplier sync command

- API
  - Public catalog API under `/api/v1`

- Admin
  - Filament admin panel under `/admin`

### Services

- `App\Services\Imports\XmlImportEngine`
  - Loads XML from URL or local path
  - Extracts rows using root path/XPath
  - Maps XML fields into supplier product fields
  - Validates mapped rows
  - Imports valid rows into `supplier_products`
  - Writes import logs and failed row logs

### Jobs

- `App\Jobs\ProcessXmlSupplierFeed`
  - Queued job
  - Receives `importJobId`
  - Executes `XmlImportEngine::import()`

### Console Commands

- `suppliers:sync-due-feeds`
  - Queues due active XML supplier feeds
  - Scheduled every 15 minutes in `routes/console.php`

### Repositories

- No repository classes currently exist.

### API Resources

- `App\Http\Resources\CategoryResource`
- `App\Http\Resources\BrandResource`
- `App\Http\Resources\ProductResource`

### Support Classes

- `App\Support\Catalog\ProductCsvSchema`

---

# 2. Database

## Base Laravel Tables

### `users`

Columns:
- `id`
- `name`
- `email`
- `email_verified_at`
- `password`
- `remember_token`
- `created_at`
- `updated_at`

Indexes:
- unique `email`

Foreign keys:
- none

### `password_reset_tokens`

Columns:
- `email`
- `token`
- `created_at`

Indexes:
- primary `email`

Foreign keys:
- none

### `sessions`

Columns:
- `id`
- `user_id`
- `ip_address`
- `user_agent`
- `payload`
- `last_activity`

Indexes:
- primary `id`
- index `user_id`
- index `last_activity`

Foreign keys:
- none

### `cache`

Columns:
- `key`
- `value`
- `expiration`

Indexes:
- primary `key`
- index `expiration`

Foreign keys:
- none

### `cache_locks`

Columns:
- `key`
- `owner`
- `expiration`

Indexes:
- primary `key`
- index `expiration`

Foreign keys:
- none

### `jobs`

Columns:
- `id`
- `queue`
- `payload`
- `attempts`
- `reserved_at`
- `available_at`
- `created_at`

Indexes:
- index `queue`

Foreign keys:
- none

### `job_batches`

Columns:
- `id`
- `name`
- `total_jobs`
- `pending_jobs`
- `failed_jobs`
- `failed_job_ids`
- `options`
- `cancelled_at`
- `created_at`
- `finished_at`

Indexes:
- primary `id`

Foreign keys:
- none

### `failed_jobs`

Columns:
- `id`
- `uuid`
- `connection`
- `queue`
- `payload`
- `exception`
- `failed_at`

Indexes:
- unique `uuid`

Foreign keys:
- none

---

## Catalog Tables

### `categories`

Columns:
- `id`
- `parent_id`
- `name`
- `slug`
- `description`
- `image_path`
- `icon`
- `is_active`
- `sort_order`
- `meta_title`
- `meta_description`
- `meta_keywords`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- unique `slug`
- index `is_active`
- index `parent_id, sort_order`
- index `is_active, sort_order`

Foreign keys:
- `parent_id` -> `categories.id`, null on delete

### `brands`

Columns:
- `id`
- `name`
- `slug`
- `website`
- `logo_path`
- `description`
- `meta_title`
- `meta_description`
- `meta_keywords`
- `is_active`
- `sort_order`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- unique `slug`
- index `is_active`

Foreign keys:
- none

### `products`

Columns:
- `id`
- `category_id`
- `brand_id`
- `supplier_id`
- `sku`
- `supplier_sku`
- `ean`
- `mpn`
- `name`
- `slug`
- `short_description`
- `description`
- `weight`
- `purchase_price`
- `price`
- `promo_price`
- `promo_start`
- `promo_end`
- `quantity`
- `reserved_quantity`
- `stock_status`
- `warranty_months`
- `active`
- `featured`
- `new_product`
- `bestseller`
- `meta_title`
- `meta_description`
- `meta_keywords`
- `specifications`
- `source_payload`
- `published_at`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- unique `sku`
- unique `slug`
- index `supplier_sku`
- index `ean`
- index `mpn`
- index `stock_status`
- index `active`
- index `featured`
- index `new_product`
- index `bestseller`
- index `category_id, brand_id, active`
- index `active, featured`
- index `active, stock_status`

Foreign keys:
- `category_id` -> `categories.id`, null on delete
- `brand_id` -> `brands.id`, null on delete
- `supplier_id` -> `suppliers.id`, null on delete

### `product_images`

Columns:
- `id`
- `product_id`
- `path`
- `alt_text`
- `sort_order`
- `is_primary`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- index `product_id, is_primary`

Foreign keys:
- `product_id` -> `products.id`, cascade on delete

### `attribute_groups`

Columns:
- `id`
- `name`
- `slug`
- `description`
- `sort_order`
- `is_active`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- unique `slug`
- index `is_active`

Foreign keys:
- none

### `product_attributes`

Columns:
- `id`
- `attribute_group_id`
- `name`
- `slug`
- `type`
- `unit`
- `sort_order`
- `is_filterable`
- `is_required`
- `is_active`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- unique `slug`
- index `is_active`
- index `attribute_group_id, sort_order`
- index `is_filterable, is_active`

Foreign keys:
- `attribute_group_id` -> `attribute_groups.id`, cascade on delete

### `attribute_values`

Columns:
- `id`
- `product_attribute_id`
- `value`
- `slug`
- `sort_order`
- `is_active`
- `created_at`
- `updated_at`
- `deleted_at`

Indexes:
- unique `product_attribute_id, slug`
- index `is_active`
- index `product_attribute_id, sort_order`

Foreign keys:
- `product_attribute_id` -> `product_attributes.id`, cascade on delete

### `product_attribute_values`

Columns:
- `id`
- `product_id`
- `product_attribute_id`
- `attribute_value_id`
- `custom_value`
- `is_filterable`
- `created_at`
- `updated_at`

Indexes:
- unique `product_id, product_attribute_id, attribute_value_id`
- index `product_attribute_id, attribute_value_id`

Foreign keys:
- `product_id` -> `products.id`, cascade on delete
- `product_attribute_id` -> `product_attributes.id`, cascade on delete
- `attribute_value_id` -> `attribute_values.id`, null on delete

### `product_related_products`

Columns:
- `product_id`
- `related_product_id`
- `sort_order`
- `created_at`
- `updated_at`

Indexes:
- primary `product_id, related_product_id`

Foreign keys:
- `product_id` -> `products.id`, cascade on delete
- `related_product_id` -> `products.id`, cascade on delete

### `product_accessory_products`

Columns:
- `product_id`
- `accessory_product_id`
- `sort_order`
- `created_at`
- `updated_at`

Indexes:
- primary `product_id, accessory_product_id`

Foreign keys:
- `product_id` -> `products.id`, cascade on delete
- `accessory_product_id` -> `products.id`, cascade on delete

---

## Supplier Tables

### `suppliers`

Columns:
- `id`
- `company_name`
- `slug`
- `contact_person`
- `email`
- `phone`
- `website`
- `notes`
- `status`
- `created_at`
- `updated_at`

Indexes:
- unique `slug`
- index `status`
- index `company_name, status`

Foreign keys:
- none

### `supplier_feeds`

Columns:
- `id`
- `supplier_id`
- `feed_name`
- `feed_type`
- `feed_url`
- `username`
- `password`
- `update_interval`
- `mapping`
- `last_sync_at`
- `last_error`
- `status`
- `created_at`
- `updated_at`

Indexes:
- index `feed_type`
- index `update_interval`
- index `status`
- index `supplier_id, feed_type`
- index `status, update_interval`

Foreign keys:
- `supplier_id` -> `suppliers.id`, cascade on delete

### `supplier_feed_items`

Columns:
- `id`
- `supplier_feed_id`
- `product_id`
- `supplier_sku`
- `raw_payload`
- `status`
- `error_message`
- `imported_at`
- `created_at`
- `updated_at`

Indexes:
- index `supplier_sku`
- index `status`

Foreign keys:
- `supplier_feed_id` -> `supplier_feeds.id`, cascade on delete
- `product_id` -> `products.id`, null on delete

### `supplier_products`

Columns:
- `id`
- `supplier_id`
- `supplier_feed_id`
- `supplier_sku`
- `name`
- `brand_name`
- `category_name`
- `price`
- `quantity`
- `currency`
- `raw_data`
- `payload_hash`
- `received_at`
- `status`
- `mapping_notes`
- `created_at`
- `updated_at`

Indexes:
- index `supplier_sku`
- index `brand_name`
- index `category_name`
- index `payload_hash`
- index `received_at`
- index `status`
- index `supplier_id, received_at`
- index `supplier_feed_id, status`

Foreign keys:
- `supplier_id` -> `suppliers.id`, cascade on delete
- `supplier_feed_id` -> `supplier_feeds.id`, null on delete

---

## XML Import Tables

### `xml_mapping_templates`

Columns:
- `id`
- `supplier_id`
- `name`
- `description`
- `root_path`
- `field_map`
- `validation_rules`
- `defaults`
- `is_active`
- `created_at`
- `updated_at`

Indexes:
- index `is_active`
- index `supplier_id, is_active`

Foreign keys:
- `supplier_id` -> `suppliers.id`, null on delete

### `import_jobs`

Columns:
- `id`
- `supplier_id`
- `supplier_feed_id`
- `xml_mapping_template_id`
- `type`
- `mode`
- `status`
- `preview_limit`
- `total_rows`
- `processed_rows`
- `failed_rows`
- `preview_data`
- `started_at`
- `finished_at`
- `error_message`
- `created_at`
- `updated_at`

Indexes:
- index `type`
- index `mode`
- index `status`
- index `supplier_feed_id, status`

Foreign keys:
- `supplier_id` -> `suppliers.id`, cascade on delete
- `supplier_feed_id` -> `supplier_feeds.id`, cascade on delete
- `xml_mapping_template_id` -> `xml_mapping_templates.id`, null on delete

### `import_histories`

Columns:
- `id`
- `import_job_id`
- `supplier_id`
- `supplier_feed_id`
- `event`
- `level`
- `message`
- `context`
- `created_at`
- `updated_at`

Indexes:
- index `level`
- index `supplier_id, created_at`

Foreign keys:
- `import_job_id` -> `import_jobs.id`, null on delete
- `supplier_id` -> `suppliers.id`, cascade on delete
- `supplier_feed_id` -> `supplier_feeds.id`, null on delete

### `failed_imports`

Columns:
- `id`
- `import_job_id`
- `supplier_id`
- `supplier_feed_id`
- `supplier_sku`
- `row_number`
- `error_type`
- `error_message`
- `raw_data`
- `created_at`
- `updated_at`

Indexes:
- index `supplier_sku`
- index `error_type`
- index `supplier_id, created_at`

Foreign keys:
- `import_job_id` -> `import_jobs.id`, null on delete
- `supplier_id` -> `suppliers.id`, cascade on delete
- `supplier_feed_id` -> `supplier_feeds.id`, null on delete

---

## Relationships Summary

- Category belongs to parent Category.
- Category has many child Categories.
- Category has many Products.
- Brand has many Products.
- Product belongs to Category.
- Product belongs to Brand.
- Product belongs to Supplier.
- Product has many ProductImages.
- Product has many ProductAttributeValues.
- Product belongs to many related Products.
- Product belongs to many accessory Products.
- ProductImage belongs to Product.
- AttributeGroup has many ProductAttributes.
- ProductAttribute belongs to AttributeGroup.
- ProductAttribute has many AttributeValues.
- ProductAttribute has many ProductAttributeValue assignments.
- AttributeValue belongs to ProductAttribute.
- AttributeValue has many ProductAttributeValue assignments.
- ProductAttributeValue belongs to Product.
- ProductAttributeValue belongs to ProductAttribute.
- ProductAttributeValue belongs to AttributeValue.
- Supplier has many Products.
- Supplier has many SupplierFeeds.
- Supplier has many SupplierProducts.
- Supplier has many XmlMappingTemplates.
- Supplier has many ImportJobs.
- SupplierFeed belongs to Supplier.
- SupplierFeed has many SupplierFeedItems.
- SupplierFeed has many SupplierProducts.
- SupplierFeed has many ImportJobs.
- SupplierFeedItem belongs to SupplierFeed.
- SupplierFeedItem belongs to Product.
- SupplierProduct belongs to Supplier.
- SupplierProduct belongs to SupplierFeed.
- XmlMappingTemplate belongs to Supplier.
- XmlMappingTemplate has many ImportJobs.
- ImportJob belongs to Supplier.
- ImportJob belongs to SupplierFeed.
- ImportJob belongs to XmlMappingTemplate.
- ImportJob has many ImportHistories.
- ImportJob has many FailedImports.
- ImportHistory belongs to ImportJob.
- ImportHistory belongs to Supplier.
- ImportHistory belongs to SupplierFeed.
- FailedImport belongs to ImportJob.
- FailedImport belongs to Supplier.
- FailedImport belongs to SupplierFeed.

---

# 3. Models

## `AttributeGroup`

Fields:
- `name`
- `slug`
- `description`
- `sort_order`
- `is_active`

Traits:
- `HasFactory`
- `SoftDeletes`

Relationships:
- `attributes()` -> has many `ProductAttribute`

Scopes:
- none

## `AttributeValue`

Fields:
- `product_attribute_id`
- `value`
- `slug`
- `sort_order`
- `is_active`

Traits:
- `SoftDeletes`

Relationships:
- `attribute()` -> belongs to `ProductAttribute`
- `assignments()` -> has many `ProductAttributeValue`

Scopes:
- none

## `Brand`

Fields:
- `name`
- `slug`
- `website`
- `logo_path`
- `description`
- `meta_title`
- `meta_description`
- `meta_keywords`
- `is_active`
- `sort_order`

Traits:
- `HasFactory`
- `SoftDeletes`

Relationships:
- `products()` -> has many `Product`

Scopes:
- none

## `Category`

Fields:
- `parent_id`
- `name`
- `slug`
- `description`
- `image_path`
- `icon`
- `is_active`
- `sort_order`
- `meta_title`
- `meta_description`
- `meta_keywords`

Traits:
- `HasFactory`
- `SoftDeletes`

Relationships:
- `parent()` -> belongs to `Category`
- `children()` -> has many `Category`
- `childrenRecursive()` -> recursive has many `Category`
- `products()` -> has many `Product`

Scopes:
- none

## `Product`

Fields:
- `category_id`
- `brand_id`
- `supplier_id`
- `sku`
- `supplier_sku`
- `ean`
- `mpn`
- `name`
- `slug`
- `short_description`
- `description`
- `weight`
- `purchase_price`
- `price`
- `promo_price`
- `promo_start`
- `promo_end`
- `quantity`
- `reserved_quantity`
- `stock_status`
- `warranty_months`
- `active`
- `featured`
- `new_product`
- `bestseller`
- `meta_title`
- `meta_description`
- `meta_keywords`
- `specifications`
- `source_payload`
- `published_at`

Traits:
- `HasFactory`
- `SoftDeletes`

Relationships:
- `category()` -> belongs to `Category`
- `brand()` -> belongs to `Brand`
- `supplier()` -> belongs to `Supplier`
- `images()` -> has many `ProductImage`
- `attributes()` -> has many `ProductAttributeValue`
- `attributeValues()` -> has many `ProductAttributeValue`
- `relatedProducts()` -> belongs to many `Product`
- `accessoryProducts()` -> belongs to many `Product`

Scopes:
- `published()` -> active and published
- `inStock()` -> `stock_status = in_stock`

## `ProductImage`

Fields:
- `product_id`
- `path`
- `alt_text`
- `sort_order`
- `is_primary`

Traits:
- `SoftDeletes`

Relationships:
- `product()` -> belongs to `Product`

Scopes:
- none

## `ProductAttribute`

Fields:
- `attribute_group_id`
- `name`
- `slug`
- `type`
- `unit`
- `sort_order`
- `is_filterable`
- `is_required`
- `is_active`

Traits:
- `SoftDeletes`

Relationships:
- `group()` -> belongs to `AttributeGroup`
- `values()` -> has many `AttributeValue`
- `assignments()` -> has many `ProductAttributeValue`

Scopes:
- none

## `ProductAttributeValue`

Fields:
- `product_id`
- `product_attribute_id`
- `attribute_value_id`
- `custom_value`
- `is_filterable`

Traits:
- none

Relationships:
- `product()` -> belongs to `Product`
- `attribute()` -> belongs to `ProductAttribute`
- `value()` -> belongs to `AttributeValue`

Scopes:
- none

## `Supplier`

Fields:
- `company_name`
- `slug`
- `contact_person`
- `email`
- `phone`
- `website`
- `notes`
- `status`

Traits:
- `HasFactory`

Relationships:
- `products()` -> has many `Product`
- `feeds()` -> has many `SupplierFeed`
- `supplierProducts()` -> has many `SupplierProduct`
- `xmlMappingTemplates()` -> has many `XmlMappingTemplate`
- `importJobs()` -> has many `ImportJob`

Accessors:
- `name` returns `company_name`

Scopes:
- none

## `SupplierFeed`

Fields:
- `supplier_id`
- `feed_name`
- `feed_type`
- `feed_url`
- `username`
- `password`
- `update_interval`
- `mapping`
- `last_sync_at`
- `last_error`
- `status`

Traits:
- `HasFactory`

Relationships:
- `supplier()` -> belongs to `Supplier`
- `items()` -> has many `SupplierFeedItem`
- `supplierProducts()` -> has many `SupplierProduct`
- `importJobs()` -> has many `ImportJob`

Accessors:
- `name` returns `feed_name`
- `type` returns `feed_type`

Scopes:
- none

## `SupplierFeedItem`

Fields:
- `supplier_feed_id`
- `product_id`
- `supplier_sku`
- `raw_payload`
- `status`
- `error_message`
- `imported_at`

Traits:
- none

Relationships:
- `feed()` -> belongs to `SupplierFeed`
- `product()` -> belongs to `Product`

Scopes:
- none

## `SupplierProduct`

Fields:
- `supplier_id`
- `supplier_feed_id`
- `supplier_sku`
- `name`
- `brand_name`
- `category_name`
- `price`
- `quantity`
- `currency`
- `raw_data`
- `payload_hash`
- `received_at`
- `status`
- `mapping_notes`

Traits:
- none

Relationships:
- `supplier()` -> belongs to `Supplier`
- `feed()` -> belongs to `SupplierFeed`

Scopes:
- none

## `XmlMappingTemplate`

Fields:
- `supplier_id`
- `name`
- `description`
- `root_path`
- `field_map`
- `validation_rules`
- `defaults`
- `is_active`

Traits:
- `HasFactory`

Relationships:
- `supplier()` -> belongs to `Supplier`
- `importJobs()` -> has many `ImportJob`

Scopes:
- none

## `ImportJob`

Fields:
- `supplier_id`
- `supplier_feed_id`
- `xml_mapping_template_id`
- `type`
- `mode`
- `status`
- `preview_limit`
- `total_rows`
- `processed_rows`
- `failed_rows`
- `preview_data`
- `started_at`
- `finished_at`
- `error_message`

Traits:
- none

Relationships:
- `supplier()` -> belongs to `Supplier`
- `feed()` -> belongs to `SupplierFeed`
- `mappingTemplate()` -> belongs to `XmlMappingTemplate`
- `histories()` -> has many `ImportHistory`
- `failures()` -> has many `FailedImport`

Scopes:
- none

## `ImportHistory`

Fields:
- `import_job_id`
- `supplier_id`
- `supplier_feed_id`
- `event`
- `level`
- `message`
- `context`

Traits:
- none

Relationships:
- `importJob()` -> belongs to `ImportJob`
- `supplier()` -> belongs to `Supplier`
- `feed()` -> belongs to `SupplierFeed`

Scopes:
- none

## `FailedImport`

Fields:
- `import_job_id`
- `supplier_id`
- `supplier_feed_id`
- `supplier_sku`
- `row_number`
- `error_type`
- `error_message`
- `raw_data`

Traits:
- none

Relationships:
- `importJob()` -> belongs to `ImportJob`
- `supplier()` -> belongs to `Supplier`
- `feed()` -> belongs to `SupplierFeed`

Scopes:
- none

## `User`

Fields:
- `name`
- `email`
- `password`

Traits:
- `HasFactory`
- `Notifiable`

Relationships:
- none

Scopes:
- none

---

# 4. Filament Admin

## Admin Panel

- Panel path: `/admin`
- Login enabled
- Default dashboard: `Filament\Pages\Dashboard`
- Resource discovery enabled from `app/Filament/Resources`
- Widget discovery enabled from `app/Filament/Widgets`

## Resources

Catalog:
- `Categories`
- `Brands`
- `Products`
- `ProductImages`

Catalog Attributes:
- `AttributeGroups`
- `ProductAttributes`
- `AttributeValues`

Suppliers:
- `Suppliers`
- `SupplierFeeds`
- `SupplierProducts`

Supplier Imports:
- `XmlMappingTemplates`
- `ImportJobs`
- `ImportHistories`
- `FailedImports`

## Pages

Every resource has:
- `List...`
- `Create...`
- `Edit...`

Examples:
- `ListProducts`
- `CreateProduct`
- `EditProduct`
- `ListSupplierFeeds`
- `CreateSupplierFeed`
- `EditSupplierFeed`
- `ListImportJobs`
- `CreateImportJob`
- `EditImportJob`

## Widgets

- `SupplierStats`
  - Active suppliers
  - Active feeds
  - Raw supplier products
  - Unmapped raw products

- `RecentSupplierFeeds`
  - Recent supplier feeds table
  - Shows feed name, supplier, feed type, interval, last sync, status

## Dashboard

- Filament default `Dashboard`
- Includes:
  - `AccountWidget`
  - `SupplierStats`
  - `RecentSupplierFeeds`
  - `FilamentInfoWidget`

---

# 5. Product Catalog

## Implemented

- Category CRUD
- Unlimited category nesting through self-referencing `parent_id`
- Recursive category API loading
- Category slug, image, icon, SEO fields, status, sort order
- Brand CRUD
- Brand slug, logo, description, SEO fields, status
- Product CRUD
- Product SKU, EAN, MPN, slug, descriptions
- Product pricing:
  - purchase price
  - price
  - promo price
  - promo start/end
- Product inventory:
  - quantity
  - reserved quantity
  - stock status
- Product flags:
  - active
  - featured
  - new product
  - bestseller
- Product SEO fields
- Product images:
  - multiple images
  - main image flag
  - sort order
  - alt text
- Product attributes:
  - attribute groups
  - attribute definitions
  - attribute values
  - product assignments
  - filterable flags
- Related products pivot
- Accessory products pivot
- Soft deletes for categories, brands, products, product images, attributes, groups, values
- Public catalog API:
  - `GET /api/v1/categories`
  - `GET /api/v1/brands`
  - `GET /api/v1/products`
  - `GET /api/v1/products/{slug}`

## Relationships

- Product belongs to category, brand, supplier
- Product has images
- Product has assigned attribute values
- Product has related products
- Product has accessory products
- Category has parent/children
- Attribute group has attributes
- Attribute has values
- Attribute values are assigned to products through `product_attribute_values`

## Filters

Implemented in Filament:
- Product stock status
- Product category
- Product brand
- Product active
- Product featured
- Product new product
- Product bestseller
- Category active
- Brand active
- Attribute group active
- Attribute type/group/filterable/active
- Attribute value active
- Soft-delete trash filters on soft-deletable resources

## Missing

- No customer-facing frontend
- No advanced search engine
- No faceted public API filtering
- No product variants
- No bundles
- No price history
- No inventory movement ledger
- No stock reservation workflow beyond `reserved_quantity` field
- No product approval workflow
- No product import-to-catalog mapper
- No image optimization/conversion pipeline
- No category breadcrumb/cache table
- No product reviews
- No carts/orders/customers in current implementation

---

# 6. Supplier Module

## Implemented

### Suppliers

- Supplier CRUD
- Fields:
  - company name
  - slug
  - contact person
  - email
  - phone
  - website
  - notes
  - status

### Feeds

- Supplier Feed CRUD
- Fields:
  - supplier
  - feed name
  - feed type: XML, CSV, API
  - feed URL
  - username
  - encrypted password
  - update interval
  - mapping JSON
  - last sync timestamp
  - last error
  - status

### Supplier Products

- `supplier_products` staging/history table
- Stores raw supplier product payloads
- Stores extracted staging fields:
  - supplier SKU
  - name
  - brand
  - category
  - price
  - quantity
  - currency
  - payload hash
  - received timestamp
  - mapping status
  - mapping notes

### Mappings

- Supplier feed has generic `mapping` JSON
- XML import has first-class `xml_mapping_templates`

### Logs

- Import logs implemented through `import_histories`
- Failed row logs implemented through `failed_imports`

## Missing

- No supplier contract/payment terms
- No supplier address table
- No supplier API token/OAuth support
- No automatic CSV importer yet
- No API feed importer yet
- No supplier product matching UI to catalog products
- No mapping approval workflow
- No duplicate supplier product resolution
- No supplier-specific price rules
- No supplier priority logic
- No real product update pipeline after staging

---

# 7. XML Import Engine

## Implemented

### Services

- `XmlImportEngine`
  - `preview()`
  - `import()`
  - `loadXml()`
  - `extractRows()`
  - `mapRow()`
  - `validateMappedRow()`
  - `failRow()`
  - `log()`

### Jobs

- `ProcessXmlSupplierFeed`
  - queued
  - calls `XmlImportEngine`

### Mappings

- `xml_mapping_templates`
  - supplier-specific or global
  - configurable `root_path`
  - `field_map`
  - `validation_rules`
  - `defaults`

### Validation

Implemented validation rules:
- `required`
- `numeric`

Validation failures write to:
- `failed_imports`

### Logs

Implemented logs:
- import started
- import finished
- import failed
- row validation failures

Log tables:
- `import_histories`
- `failed_imports`

### Queues

- Laravel `jobs` table exists
- `ProcessXmlSupplierFeed` implements `ShouldQueue`
- Import jobs can be queued from Filament
- Due XML feeds can be queued from command

### Scheduled Sync

- Command: `suppliers:sync-due-feeds`
- Registered schedule: every 15 minutes
- Supports intervals:
  - manual
  - hourly
  - 6h
  - 12h
  - daily

### Admin UI

- XML Mapping Templates resource
- Import Jobs resource
  - preview action
  - queue sync action
- Import History resource
- Failed Imports resource
- Supplier Feeds resource
  - queue XML sync action

## Critical Statement

XML import does **not** import directly into `products`.

XML import imports into `supplier_products` first.

The catalog `products` table is not updated by `XmlImportEngine`.

## Missing

- No streaming XML parser for very large files
- No XSD/schema validation
- No advanced validation rules beyond `required` and `numeric`
- No retry/backoff customization
- No queue failure callback writing to `failed_imports`
- No dry-run diff against existing supplier products
- No duplicate prevention beyond storing `payload_hash`
- No import cancellation
- No chunked/batched imports
- No CSV import engine
- No API import engine
- No mapping UI preview modal beyond stored preview action/data
- No product catalog mapping/promotion from `supplier_products` to `products`

---

# 8. Technical Debt

## Critical

- None identified as immediately blocking local tests or migrations.

## High

- XML import loads entire XML documents into memory with SimpleXML; large supplier feeds can exhaust memory.
- `supplier_products` history can grow indefinitely; no retention/archive strategy.
- No idempotency enforcement on `supplier_products`; repeated imports can create duplicate staged rows with the same `payload_hash`.
- XML import can read local file paths from `feed_url`; acceptable for admin-controlled development, but risky without validation in production.
- No authorization policies/roles for Filament resources.

## Medium

- `SupplierFeedItem` appears to be an older staging structure and overlaps conceptually with `supplier_products`.
- Product import mapping from `supplier_products` to real `products` is not implemented.
- Many status/type fields are strings without PHP enums or database constraints.
- Import preview stores JSON strings inside `preview_data` key/value format instead of normalized preview rows.
- Product attribute assignment unique index allows nullable `attribute_value_id`; MySQL may allow duplicate custom-value assignments with nulls.
- No tests for Filament actions themselves.
- No tests for scheduled command beyond manual smoke behavior.
- No queue worker configuration documented beyond Laravel defaults.

## Low

- Some resources allow creating log/history records manually in admin.
- No dedicated repositories; code uses Eloquent directly.
- No dedicated DTOs for mapped XML rows.
- No API filters for brands/categories/products yet.
- No dashboard metrics for failed imports/import throughput.

---

# 9. Recommendations

Build next:

1. Supplier Product Mapping Workflow
   - Match `supplier_products` to existing products
   - Create/update product drafts from approved supplier data
   - Keep audit history

2. Import Idempotency
   - Add unique or logical dedupe rules for `supplier_id + supplier_feed_id + payload_hash + received_at window`
   - Track import batch IDs

3. Large XML Support
   - Replace SimpleXML full-load parsing with XMLReader streaming
   - Process rows in chunks

4. Import Validation Upgrade
   - Laravel Validator-based rules
   - Required field profiles per supplier/template
   - XSD validation if suppliers provide schemas

5. Admin Import UX
   - Preview modal/table
   - Row-level mapped/raw comparison
   - Retry failed rows
   - Mark ignored/mapped

6. Catalog Public API Filters
   - category
   - brand
   - price range
   - attributes
   - stock status
   - sorting

7. Permissions
   - Filament roles/policies
   - Restrict import execution and credential editing

8. Retention/Archiving
   - Archive old supplier product snapshots
   - Cleanup old import histories

9. CSV/API Engines
   - CSV import engine using same staging architecture
   - API feed engine using same mapping/history/failure tables

---

# 10. Overall Completion

Estimated against a full production e-commerce backend vision:

- Catalog: **65%**
  - Strong data model/admin foundation exists.
  - Missing variants, advanced search/filter API, stock ledger, mapping pipeline, frontend.

- Supplier Module: **70%**
  - Supplier/feed/raw staging/admin exists.
  - Missing real supplier matching, retention, supplier business terms, CSV/API processors.

- XML Engine: **55%**
  - Functional mapped XML staging engine exists.
  - Missing streaming, idempotency, advanced validation, retry UX, production hardening.

- Admin Panel: **70%**
  - Broad CRUD coverage exists.
  - Missing permissions, polished import preview UX, dashboards beyond supplier stats/feed table.

- Overall Project: **35%**
  - Backend catalog/supplier/import foundations are in place.
  - Core e-commerce areas like customers, carts, orders, payments, shipping, frontend, search, and production workflows are not implemented.
