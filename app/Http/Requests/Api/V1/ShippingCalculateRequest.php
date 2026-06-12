<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ShippingCalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:255'],
            'delivery_type' => ['required', 'in:office,address,manual'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'office_id' => ['required_if:delivery_type,office', 'nullable', 'integer', 'exists:shipping_offices,id'],
            'city' => ['required', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'cart_id' => ['nullable', 'integer', 'exists:carts,id'],
            'cart_items' => ['nullable', 'array'],
        ];
    }
}
