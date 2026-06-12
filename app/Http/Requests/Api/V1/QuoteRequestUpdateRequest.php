<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequestUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'offered_price' => ['prohibited'],
            'items' => ['prohibited'],
        ];
    }
}
