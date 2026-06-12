<?php

namespace App\Support\Catalog;

final class ProductCsvSchema
{
    public const IMPORT_TYPES = [
        'products' => 'Products',
        'prices' => 'Prices',
        'stock' => 'Stock',
        'categories' => 'Categories',
        'brands' => 'Brands',
        'attributes' => 'Attributes',
    ];

    public const EXPORT_TYPES = [
        'products' => 'Products',
        'prices' => 'Prices',
        'stock' => 'Stock',
        'categories' => 'Categories',
        'brands' => 'Brands',
        'attributes' => 'Attributes',
        'products_without_images' => 'Products without images',
        'products_without_descriptions' => 'Products without descriptions',
        'active_products' => 'Active products',
        'inactive_products' => 'Inactive products',
    ];

    public const MODES = [
        'update-only' => 'Update only',
        'create-only' => 'Create only',
        'create-or-update' => 'Create or update',
        'dry-run' => 'Dry run',
    ];

    public const COLUMNS = [
        'sku',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'slug',
        'category_slug',
        'brand_slug',
        'supplier_slug',
        'short_description',
        'description',
        'weight',
        'purchase_price',
        'price',
        'promo_price',
        'promo_start',
        'promo_end',
        'quantity',
        'reserved_quantity',
        'stock_status',
        'availability_status',
        'external_availability_status',
        'external_availability_label',
        'availability_message',
        'expected_date',
        'supplier_lead_time_days',
        'warranty_months',
        'active',
        'featured',
        'new_product',
        'bestseller',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'published_at',
    ];

    public const TYPE_COLUMNS = [
        'products' => [
            'sku', 'ean', 'mpn', 'name', 'slug', 'brand', 'category', 'short_description',
            'description', 'purchase_price', 'price', 'promo_price', 'quantity', 'stock_status',
            'availability_status', 'external_availability_status', 'external_availability_label',
            'availability_message', 'expected_date', 'supplier_lead_time_days',
            'warranty_months', 'active', 'featured', 'new_product', 'bestseller',
            'meta_title', 'meta_description', 'meta_keywords',
        ],
        'prices' => ['sku', 'ean', 'purchase_price', 'price', 'promo_price', 'promo_start', 'promo_end'],
        'stock' => ['sku', 'ean', 'quantity', 'stock_status', 'availability_status', 'external_availability_status', 'external_availability_label'],
        'categories' => ['name', 'slug', 'parent', 'description', 'meta_title', 'meta_description', 'is_active', 'sort_order'],
        'brands' => ['name', 'slug', 'website', 'description', 'meta_title', 'meta_description', 'is_active', 'sort_order'],
        'attributes' => ['sku', 'attribute_group', 'attribute_name', 'attribute_value', 'unit', 'is_filterable'],
    ];

    public const LABELS = [
        'sku' => 'SKU',
        'ean' => 'EAN',
        'mpn' => 'MPN',
        'name' => 'Name',
        'slug' => 'Slug',
        'brand' => 'Brand',
        'category' => 'Category',
        'parent' => 'Parent category',
        'attribute_group' => 'Attribute group',
        'attribute_name' => 'Attribute name',
        'attribute_value' => 'Attribute value',
    ];

    public const ALIASES = [
        'sku' => ['sku', 'код', 'artikul', 'product_code', 'product sku'],
        'ean' => ['ean', 'barcode', 'баркод'],
        'mpn' => ['mpn', 'manufacturer_code', 'part_number'],
        'name' => ['name', 'product_name', 'име', 'title'],
        'slug' => ['slug', 'url'],
        'brand' => ['brand', 'brand_name', 'марка', 'manufacturer'],
        'category' => ['category', 'category_name', 'категория'],
        'purchase_price' => ['purchase_price', 'buy_price', 'cost'],
        'price' => ['price', 'sale_price', 'цена'],
        'promo_price' => ['promo_price', 'discount_price', 'special_price'],
        'quantity' => ['quantity', 'qty', 'stock', 'наличност'],
        'stock_status' => ['stock_status', 'availability'],
        'availability_status' => ['availability_status', 'internal_availability_status'],
        'external_availability_status' => ['external_availability_status', 'external_status', 'supplier_status', 'availability'],
        'external_availability_label' => ['external_availability_label', 'availability_label', 'supplier_status_label'],
        'active' => ['active', 'is_active', 'enabled'],
        'attribute_group' => ['attribute_group', 'group'],
        'attribute_name' => ['attribute_name', 'attribute', 'specification'],
        'attribute_value' => ['attribute_value', 'value'],
    ];

    public static function columnsFor(string $type): array
    {
        return self::TYPE_COLUMNS[$type] ?? self::TYPE_COLUMNS['products'];
    }

    public static function importTypeOptions(): array
    {
        return self::IMPORT_TYPES;
    }

    public static function exportTypeOptions(): array
    {
        return self::EXPORT_TYPES;
    }

    public static function modeOptions(): array
    {
        return self::MODES;
    }
}
