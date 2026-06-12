<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRecommendationLog extends Model
{
    public const TYPES = ['product_recommendation', 'alternative_products', 'product_comparison', 'category_advice', 'buying_guide'];

    protected $fillable = ['user_id', 'session_id', 'query', 'recommendation_type', 'results'];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
