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
     * @param  Collection<int, array<string, mixed>>|null  $requiredAttributes
     * @param  Collection<int, array<string, mixed>>|null  $recommendedAttributes
     * @param  Collection<int, array<string, mixed>>|null  $invalidRequiredAttributes
     * @param  Collection<int, array<string, mixed>>|null  $invalidRecommendedAttributes
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
        public readonly ?CategorySpecificationTemplateResult $templateCoverage = null,
        public readonly ?Collection $requiredAttributes = null,
        public readonly ?Collection $recommendedAttributes = null,
        public readonly ?Collection $invalidRequiredAttributes = null,
        public readonly ?Collection $invalidRecommendedAttributes = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::STATUS_MISSING_REQUIRED => 'Липсват задължителни характеристики',
            self::STATUS_NEEDS_DATA => 'Има непопълнени препоръчителни характеристики',
            self::STATUS_NO_CATEGORY_TEMPLATE => 'Няма зададен шаблон за категорията',
            self::STATUS_GOOD => 'Характеристиките са попълнени',
        ];
    }

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
            self::STATUS_NO_CATEGORY_TEMPLATE => 'warning',
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

    public function compactScoreLabel(): string
    {
        return "{$this->filledCount}/{$this->expectedCount} · {$this->percentageComplete}%";
    }

    public function requiredExpectedCount(): int
    {
        return $this->requiredRows()->count();
    }

    public function requiredFilledCount(): int
    {
        return $this->requiredRows()->where('value_state', 'filled')->count();
    }

    public function requiredMissingCount(): int
    {
        return $this->requiredExpectedCount() - $this->requiredFilledCount();
    }

    public function requiredPercentageComplete(): int
    {
        return $this->percentage($this->requiredFilledCount(), $this->requiredExpectedCount());
    }

    public function requiredScoreLabel(): string
    {
        return $this->score($this->requiredFilledCount(), $this->requiredExpectedCount());
    }

    public function recommendedExpectedCount(): int
    {
        return $this->recommendedRows()->count();
    }

    public function recommendedFilledCount(): int
    {
        return $this->recommendedRows()->where('value_state', 'filled')->count();
    }

    public function recommendedMissingCount(): int
    {
        return $this->recommendedExpectedCount() - $this->recommendedFilledCount();
    }

    public function recommendedPercentageComplete(): int
    {
        return $this->percentage($this->recommendedFilledCount(), $this->recommendedExpectedCount());
    }

    public function recommendedScoreLabel(): string
    {
        return $this->score($this->recommendedFilledCount(), $this->recommendedExpectedCount());
    }

    public function templateSourceLabel(): string
    {
        return $this->templateCoverage?->templateSourceLabel() ?? 'Липсва';
    }

    public function reasonLabel(): ?string
    {
        return match ($this->reason) {
            'missing_category' => 'Липсва категория',
            'missing_template' => 'Няма директен или наследен шаблон',
            default => null,
        };
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

    /**
     * @return array<int, string>
     */
    public function missingRequiredAttributeLabels(): array
    {
        return $this->requiredRows()
            ->where('value_state', 'missing')
            ->pluck('label')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function missingRecommendedAttributeLabels(): array
    {
        return $this->recommendedRows()
            ->where('value_state', 'missing')
            ->pluck('label')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function invalidRequiredAttributeLabels(): array
    {
        return ($this->invalidRequiredAttributes ?? collect())
            ->pluck('label')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function invalidRecommendedAttributeLabels(): array
    {
        return ($this->invalidRecommendedAttributes ?? collect())
            ->pluck('label')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function requiredRows(): Collection
    {
        return $this->requiredAttributes
            ?? $this->expectedAttributes->where('is_required', true)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recommendedRows(): Collection
    {
        return $this->recommendedAttributes
            ?? $this->expectedAttributes->where('is_required', false)->values();
    }

    private function score(int $filled, int $expected): string
    {
        return "{$filled}/{$expected} · {$this->percentage($filled, $expected)}%";
    }

    private function percentage(int $filled, int $expected): int
    {
        return $expected === 0 ? 0 : (int) round(($filled / $expected) * 100);
    }
}
