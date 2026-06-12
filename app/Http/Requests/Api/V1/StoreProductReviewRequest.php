<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductReviewRequest extends FormRequest
{
    public function rules(): array
    {
        $guest = $this->user('sanctum') === null;

        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'comment' => ['required', 'string', 'min:5', 'max:5000'],
            'pros' => ['nullable', 'string', 'max:2000'],
            'cons' => ['nullable', 'string', 'max:2000'],
            'customer_name' => [$guest ? 'required' : 'nullable', 'string', 'max:120'],
            'customer_email' => [$guest ? 'required' : 'nullable', 'email', 'max:190'],
        ];
    }
}
