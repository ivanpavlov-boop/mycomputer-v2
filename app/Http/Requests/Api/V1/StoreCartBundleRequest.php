<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCartBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bundle_id' => ['required', 'integer', 'exists:product_bundles,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'selected_items' => ['nullable', 'array'],
            'selected_items.*.component_group' => ['required_with:selected_items', 'string', 'max:255'],
            'selected_items.*.product_id' => ['required_with:selected_items', 'integer', 'exists:products,id'],
        ];
    }
}
