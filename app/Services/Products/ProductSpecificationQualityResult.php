<?php

namespace App\Services\Products;

use Illuminate\Support\Collection;

class ProductSpecificationQualityResult
{
    public const STATUS_GOOD = 'good';

    public const STATUS_NEEDS_DATA = 'needs_data';

    public const STATUS_MISSING_REQUIRED = 'missing_required';

    public const STATUS_NO_CATEGORY_TEMPLATE = 'no_category_template';

    /**
     * @param  Collection<int, array<string, mixed>>  $expectedAttributes
     * @param  Collection<int, array<string, mixed>>  $filledAttributes
     * @param  Collection<int, array<string, mixed>>  $missingAttributes
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedCount,
        public readonly int $filledCount,
        public readonly int $missingCount,
        public readonly int $percentageComplete,
        public readonly Collection $expectedAttributes,
        public readonly Collection $filledAttributes,
        public readonly Collection $missingAttributes,
    ) {}

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_GOOD => 'Добро',
            self::STATUS_NEEDS_DATA => 'Нуждае се от попълване',
            self::STATUS_MISSING_REQUIRED => 'Липсват важни характеристики',
            self::STATUS_NO_CATEGORY_TEMPLATE => 'Няма зададен шаблон за категория',
            default => 'Неизвестно',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_GOOD => 'success',
            self::STATUS_NEEDS_DATA => 'warning',
            self::STATUS_MISSING_REQUIRED => 'danger',
            self::STATUS_NO_CATEGORY_TEMPLATE => 'gray',
            default => 'gray',
        };
    }

    public function scoreLabel(): string
    {
        if ($this->expectedCount === 0) {
            return '0/0';
        }

        return "{$this->filledCount}/{$this->expectedCount} ({$this->percentageComplete}%)";
    }

    /**
     * @return array<int, string>
     */
    public function missingAttributeLabels(): array
    {
        return $this->missingAttributes
            ->pluck('label')
            ->filter()
            ->values()
            ->all();
    }

    public function missingAttributeSummary(int $limit = 5): string
    {
        $labels = collect($this->missingAttributeLabels());

        if ($labels->isEmpty()) {
            return '';
        }

        $visible = $labels->take($limit)->implode(', ');
        $remaining = $labels->count() - $limit;

        if ($remaining > 0) {
            return "{$visible} и още {$remaining}";
        }

        return $visible;
    }
}
