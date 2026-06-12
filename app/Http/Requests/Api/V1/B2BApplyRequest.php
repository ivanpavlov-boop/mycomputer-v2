<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class B2BApplyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['required', 'string', 'max:64'],
            'mol' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'billing_address' => ['required', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
