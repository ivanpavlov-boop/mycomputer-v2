<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReportProductReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
