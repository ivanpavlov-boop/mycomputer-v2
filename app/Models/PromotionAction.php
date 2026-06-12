<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionAction extends Model
{
    public const TYPES = [
        'percentage_discount',
        'fixed_discount',
        'free_shipping',
        'gift_product',
        'bundle_discount',
        'buy_x_get_y',
    ];

    protected $fillable = [
        'promotion_id',
        'action_type',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
