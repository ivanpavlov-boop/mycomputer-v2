<?php

namespace App\Support;

use App\Models\User;
use App\Services\Products\ProductWorkflowService;
use Illuminate\Support\Arr;

final class ProductFormFieldAccess
{
    /** @var list<string> */
    private const CONTENT_FIELDS = [
        'category_id',
        'brand_id',
        'supplier_id',
        'sku',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'name_translations',
        'lock_name',
        'slug',
        'slug_translations',
        'short_description',
        'short_description_translations',
        'description',
        'description_translations',
        'lock_descriptions',
        'weight',
        'warranty_months',
        'assigned_to',
        'featured',
        'new_product',
        'bestseller',
        'searchable_keywords',
        'specifications',
        'images',
        'relatedProducts',
        'accessoryProducts',
    ];

    /** @var list<string> */
    private const PRICE_FIELDS = [
        'purchase_price',
        'regular_price',
        'apply_pricing_rules',
        'price',
        'price_source',
        'sale_price',
        'sale_price_starts_at',
        'sale_price_ends_at',
        'sale_price_source',
    ];

    /** @var list<string> */
    private const STOCK_FIELDS = [
        'quantity',
        'reserved_quantity',
        'availability_status_id',
        'stock_status',
        'manual_override',
        'availability_message',
        'expected_date',
        'supplier_lead_time_days',
    ];

    /** @var list<string> */
    private const SEO_FIELDS = [
        'meta_title',
        'meta_title_translations',
        'meta_description',
        'meta_description_translations',
        'meta_keywords',
        'lock_seo',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data, User $user): array
    {
        $allowedFields = [];

        if ($user->canEditProductContent()) {
            $allowedFields = array_merge($allowedFields, self::CONTENT_FIELDS);
        }

        if ($user->canEditProductPrices()) {
            $allowedFields = array_merge($allowedFields, self::PRICE_FIELDS);
        }

        if ($user->canEditProductStock()) {
            $allowedFields = array_merge($allowedFields, self::STOCK_FIELDS);
        }

        if ($user->canEditProductSeo()) {
            $allowedFields = array_merge($allowedFields, self::SEO_FIELDS);
        }

        if ($user->canManageProductQualityFlags()) {
            $allowedFields[] = 'qualityFlagAssignments';
        }

        return Arr::only(
            Arr::except($data, ProductWorkflowService::PROTECTED_FORM_FIELDS),
            array_values(array_unique($allowedFields)),
        );
    }
}
