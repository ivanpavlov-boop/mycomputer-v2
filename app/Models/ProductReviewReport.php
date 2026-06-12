<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReviewReport extends Model
{
    public const STATUSES = ['pending', 'reviewed', 'dismissed'];

    protected $fillable = [
        'product_review_id',
        'user_id',
        'session_id',
        'reason',
        'message',
        'status',
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
