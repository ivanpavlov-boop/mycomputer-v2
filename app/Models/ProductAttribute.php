<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use HasFactory;

    use HasLocalizedFields;
    use SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_SELECT = 'select';

    public const TYPE_MULTISELECT = 'multiselect';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_JSON = 'json';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_DECIMAL,
        self::TYPE_JSON,
    ];

    protected $fillable = [
        'attribute_group_id',
        'code',
        'name',
        'name_bg',
        'name_en',
        'name_translations',
        'description_bg',
        'description_en',
        'slug',
        'type',
        'unit',
        'sort_order',
        'is_filterable',
        'is_visible_on_product',
        'is_comparable',
        'is_required',
        'is_required_by_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_visible_on_product' => 'boolean',
            'is_comparable' => 'boolean',
            'is_required' => 'boolean',
            'is_required_by_default' => 'boolean',
            'is_active' => 'boolean',
            'name_translations' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductAttribute $attribute): void {
            $attribute->code = filled($attribute->code)
                ? Str::slug((string) $attribute->code, '_')
                : Str::slug((string) ($attribute->slug ?: $attribute->name_bg ?: $attribute->name), '_');

            if (blank($attribute->slug)) {
                $attribute->slug = Str::slug((string) ($attribute->code ?: $attribute->name_bg ?: $attribute->name));
            }

            if (blank($attribute->name_bg) && filled($attribute->name)) {
                $attribute->name_bg = $attribute->name;
            }

            if (filled($attribute->name_bg)) {
                $attribute->name = $attribute->name_bg;
            }

            if (filled($attribute->name_en)) {
                $translations = $attribute->name_translations ?? [];
                $translations['en'] = $attribute->name_en;
                $attribute->name_translations = $translations;
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class, 'attribute_group_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(CategoryProductAttribute::class);
    }
}
