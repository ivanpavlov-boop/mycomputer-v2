<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\AttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttributeValue extends Model
{
    /** @use HasFactory<AttributeValueFactory> */
    use HasFactory;

    use HasLocalizedFields;
    use SoftDeletes;

    protected $fillable = [
        'product_attribute_id',
        'value',
        'value_translations',
        'slug',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'value_translations' => 'array',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
