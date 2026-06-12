<?php

namespace App\Services\Csv;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CsvValidationService
{
    public function validate(string $type, array $row, string $mode): array
    {
        $rules = match ($type) {
            'products' => [
                'sku' => ['required_without:ean', 'nullable', 'string', 'max:255'],
                'ean' => ['required_without:sku', 'nullable', 'string', 'max:255'],
                'mpn' => ['nullable', 'string', 'max:255'],
                'name' => [$mode === 'update-only' ? 'nullable' : 'required', 'string', 'max:255'],
                'price' => ['nullable', 'numeric', 'min:0'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'promo_price' => ['nullable', 'numeric', 'min:0'],
                'quantity' => ['nullable', 'integer', 'min:0'],
                'stock_status' => ['nullable', 'string', 'max:255'],
                'availability_status' => ['nullable', 'string', 'max:255'],
                'external_availability_status' => ['nullable', 'string', 'max:255'],
                'external_availability_label' => ['nullable', 'string', 'max:255'],
                'availability_message' => ['nullable', 'string', 'max:255'],
                'expected_date' => ['nullable', 'date'],
                'supplier_lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'warranty_months' => ['nullable', 'integer', 'min:0'],
                'active' => ['nullable'],
                'featured' => ['nullable'],
                'new_product' => ['nullable'],
                'bestseller' => ['nullable'],
                'short_description' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'brand' => ['nullable', 'string', 'max:255'],
                'category' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255'],
                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string'],
                'meta_keywords' => ['nullable', 'string'],
            ],
            'prices' => [
                'sku' => ['required_without:ean', 'nullable', 'string', 'max:255'],
                'ean' => ['required_without:sku', 'nullable', 'string', 'max:255'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'price' => ['required', 'numeric', 'min:0'],
                'promo_price' => ['nullable', 'numeric', 'min:0'],
                'promo_start' => ['nullable', 'date'],
                'promo_end' => ['nullable', 'date'],
            ],
            'stock' => [
                'sku' => ['required_without:ean', 'nullable', 'string', 'max:255'],
                'ean' => ['required_without:sku', 'nullable', 'string', 'max:255'],
                'quantity' => ['required', 'integer', 'min:0'],
                'stock_status' => ['nullable', 'string', 'max:255'],
                'availability_status' => ['nullable', 'string', 'max:255'],
                'external_availability_status' => ['nullable', 'string', 'max:255'],
                'external_availability_label' => ['nullable', 'string', 'max:255'],
            ],
            'categories' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255'],
                'parent' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string'],
                'is_active' => ['nullable'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'brands' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255'],
                'website' => ['nullable', 'url', 'max:255'],
                'description' => ['nullable', 'string'],
                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string'],
                'is_active' => ['nullable'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'attributes' => [
                'sku' => ['required', 'string', 'max:255'],
                'attribute_group' => ['required', 'string', 'max:255'],
                'attribute_name' => ['required', 'string', 'max:255'],
                'attribute_value' => ['required', 'string', 'max:255'],
                'unit' => ['nullable', 'string', 'max:50'],
                'is_filterable' => ['nullable'],
            ],
            default => throw ValidationException::withMessages(['type' => 'Unsupported CSV import type.']),
        };

        return Validator::make($row, $rules)->validate();
    }
}
