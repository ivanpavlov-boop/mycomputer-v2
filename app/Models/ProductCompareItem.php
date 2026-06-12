<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCompareItem extends Model
{
    protected $fillable = [
        'product_compare_list_id',
        'product_id',
        'sort_order',
    ];

    public function compareList(): BelongsTo
    {
        return $this->belongsTo(ProductCompareList::class, 'product_compare_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
