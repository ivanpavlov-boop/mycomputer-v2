<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'stock_status' => ['nullable', 'string', 'max:255'],
            'availability' => ['nullable', 'string', 'max:255'],
            'availability_status' => ['nullable', 'string', 'max:255'],
            'availability_statuses' => ['nullable', 'array'],
            'availability_statuses.*' => ['string', 'max:255'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['string', 'max:255'],
            'featured' => ['nullable', 'boolean'],
            'new_product' => ['nullable', 'boolean'],
            'bestseller' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:relevance,price_asc,price_desc,newest,bestseller,featured,name_asc,name_desc'],
        ];
    }
}
