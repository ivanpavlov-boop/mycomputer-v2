<?php

namespace App\Models;

use Database\Factories\CategoryProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryProductAttribute extends Model
{
    /** @use HasFactory<CategoryProductAttributeFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'product_attribute_id',
        'is_required',
        'is_filterable',
        'is_visible_on_product',
        'is_comparable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_visible_on_product' => 'boolean',
            'is_comparable' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }
}
