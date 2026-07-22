<?php

namespace App\Models;

use App\Enums\CategoryAttributeFilterControl;
use Database\Factories\CategoryProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CategoryProductAttribute extends Model
{
    /** @use HasFactory<CategoryProductAttributeFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'product_attribute_id',
        'is_required',
        'is_filterable',
        'filter_control_type',
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

    protected static function booted(): void
    {
        static::saving(function (CategoryProductAttribute $assignment): void {
            if (blank($assignment->filter_control_type)) {
                $assignment->filter_control_type = CategoryAttributeFilterControl::Auto->value;
            }

            $control = CategoryAttributeFilterControl::tryFrom((string) $assignment->filter_control_type);

            if ($control === null) {
                throw ValidationException::withMessages([
                    'filter_control_type' => 'Избран е невалиден тип публичен филтър.',
                ]);
            }

            $attribute = ProductAttribute::query()->withTrashed()->find($assignment->product_attribute_id);

            if ($attribute !== null && ! $control->isCompatibleWith($attribute->type)) {
                throw ValidationException::withMessages([
                    'filter_control_type' => $control->validationMessage(),
                ]);
            }
        });
    }

    public function configuredFilterControl(): CategoryAttributeFilterControl
    {
        return CategoryAttributeFilterControl::fromPersisted($this->filter_control_type);
    }

    public function resolvedFilterControl(): ?CategoryAttributeFilterControl
    {
        return $this->configuredFilterControl()->resolveForAttributeType($this->attribute?->type);
    }

    public function filterControlDisplayLabel(): string
    {
        $configured = $this->configuredFilterControl();
        $resolved = $this->resolvedFilterControl();

        if ($configured !== CategoryAttributeFilterControl::Auto || $resolved === null) {
            return $configured->label();
        }

        return $configured->label().' → '.$resolved->label();
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
