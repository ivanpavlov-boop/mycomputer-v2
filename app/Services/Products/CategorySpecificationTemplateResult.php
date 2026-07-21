<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\CategoryProductAttribute;
use Illuminate\Support\Collection;

final class CategorySpecificationTemplateResult
{
    public const STATUS_DIRECT_TEMPLATE = 'direct_template';

    public const STATUS_INHERITED_TEMPLATE = 'inherited_template';

    public const STATUS_NO_TEMPLATE = 'no_template';

    /**
     * @param  array<int, string>  $categoryPath
     * @param  Collection<int, CategoryProductAttribute>  $directAssignments
     * @param  Collection<int, CategoryProductAttribute>  $inheritedAssignments
     * @param  Collection<int, CategoryProductAttribute>  $effectiveAssignments
     * @param  Collection<int, CategoryProductAttribute>  $requiredAssignments
     * @param  Collection<int, CategoryProductAttribute>  $recommendedAssignments
     * @param  Collection<int, CategoryProductAttribute>  $optionalAssignments
     */
    public function __construct(
        public readonly string $status,
        public readonly ?Category $category,
        public readonly ?Category $sourceCategory,
        public readonly array $categoryPath,
        public readonly Collection $directAssignments,
        public readonly Collection $inheritedAssignments,
        public readonly Collection $effectiveAssignments,
        public readonly Collection $requiredAssignments,
        public readonly Collection $recommendedAssignments,
        public readonly Collection $optionalAssignments,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::STATUS_DIRECT_TEMPLATE => 'Директен шаблон',
            self::STATUS_INHERITED_TEMPLATE => 'Наследен шаблон',
            self::STATUS_NO_TEMPLATE => 'Няма зададен шаблон',
        ];
    }

    public static function labelFor(?string $status): string
    {
        return self::options()[$status] ?? 'Неизвестно';
    }

    public static function colorFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_DIRECT_TEMPLATE => 'success',
            self::STATUS_INHERITED_TEMPLATE => 'info',
            self::STATUS_NO_TEMPLATE => 'warning',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return self::labelFor($this->status);
    }

    public function statusColor(): string
    {
        return self::colorFor($this->status);
    }

    public function templateSourceLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DIRECT_TEMPLATE => 'Директен',
            self::STATUS_INHERITED_TEMPLATE => $this->sourceCategory
                ? 'Наследен от '.$this->sourceCategory->name
                : 'Наследен',
            self::STATUS_NO_TEMPLATE => 'Липсва',
            default => 'Неизвестно',
        };
    }

    public function hierarchyLabel(): string
    {
        return implode(' / ', $this->categoryPath);
    }

    public function directAttributeCount(): int
    {
        return $this->directAssignments->count();
    }

    public function inheritedAttributeCount(): int
    {
        return $this->inheritedAssignments->count();
    }

    public function effectiveAttributeCount(): int
    {
        return $this->effectiveAssignments->count();
    }

    public function requiredAttributeCount(): int
    {
        return $this->requiredAssignments->count();
    }

    public function recommendedAttributeCount(): int
    {
        return $this->recommendedAssignments->count();
    }

    public function optionalAttributeCount(): int
    {
        return $this->optionalAssignments->count();
    }

    /**
     * Required and recommended assignments are authoritative. Templates without
     * either flag retain the legacy behavior of evaluating every assignment.
     *
     * @return Collection<int, CategoryProductAttribute>
     */
    public function qualityAssignments(): Collection
    {
        $important = $this->requiredAssignments
            ->concat($this->recommendedAssignments)
            ->unique('product_attribute_id')
            ->values();

        return $important->isNotEmpty() ? $important : $this->effectiveAssignments;
    }
}
