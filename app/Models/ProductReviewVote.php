<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReviewVote extends Model
{
    public const TYPES = ['helpful', 'not_helpful'];

    protected $fillable = [
        'product_review_id',
        'user_id',
        'session_id',
        'vote_type',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'product_review_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
