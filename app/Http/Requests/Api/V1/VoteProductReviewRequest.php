<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProductReviewVote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoteProductReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vote_type' => ['required', Rule::in(ProductReviewVote::TYPES)],
        ];
    }
}
