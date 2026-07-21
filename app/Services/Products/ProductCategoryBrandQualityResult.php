<?php

namespace App\Services\Products;

final class ProductCategoryBrandQualityResult
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_MISSING_CATEGORY = 'missing_category';

    public const STATE_MISSING_BRAND = 'missing_brand';

    public const STATE_MISSING_BOTH = 'missing_both';

    public readonly string $stateLabel;

    public readonly string $stateColor;

    public readonly string $categoryDisplayLabel;

    public readonly string $brandDisplayLabel;

    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $categoryLabel,
        public readonly ?string $categoryPath,
        public readonly ?string $brandLabel,
        public readonly bool $categoryInactive,
        public readonly bool $categoryArchived,
        public readonly bool $brandInactive,
        public readonly bool $brandArchived,
        public readonly array $warnings,
    ) {
        $this->stateLabel = self::labelFor($state);
        $this->stateColor = self::colorFor($state);
        $this->categoryDisplayLabel = $categoryPath ?: $categoryLabel ?: 'Липсва';
        $this->brandDisplayLabel = $brandLabel ?: 'Липсва';
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::STATE_MISSING_CATEGORY => self::labelFor(self::STATE_MISSING_CATEGORY),
            self::STATE_MISSING_BRAND => self::labelFor(self::STATE_MISSING_BRAND),
            self::STATE_MISSING_BOTH => self::labelFor(self::STATE_MISSING_BOTH),
            self::STATE_COMPLETE => self::labelFor(self::STATE_COMPLETE),
        ];
    }

    public static function labelFor(string $state): string
    {
        return match ($state) {
            self::STATE_COMPLETE => 'Категорията и марката са попълнени',
            self::STATE_MISSING_CATEGORY => 'Липсва категория',
            self::STATE_MISSING_BRAND => 'Липсва марка',
            self::STATE_MISSING_BOTH => 'Липсват категория и марка',
            default => 'Неизвестно',
        };
    }

    public static function colorFor(string $state): string
    {
        return match ($state) {
            self::STATE_COMPLETE => 'success',
            self::STATE_MISSING_CATEGORY,
            self::STATE_MISSING_BRAND,
            self::STATE_MISSING_BOTH => 'danger',
            default => 'gray',
        };
    }

    public function isCategoryMissing(): bool
    {
        return in_array($this->state, [self::STATE_MISSING_CATEGORY, self::STATE_MISSING_BOTH], true);
    }

    public function isBrandMissing(): bool
    {
        return in_array($this->state, [self::STATE_MISSING_BRAND, self::STATE_MISSING_BOTH], true);
    }

    public function categoryWarning(): ?string
    {
        return match (true) {
            $this->categoryArchived => 'Архивирана категория',
            $this->categoryInactive => 'Неактивна категория',
            default => null,
        };
    }

    public function brandWarning(): ?string
    {
        return match (true) {
            $this->brandArchived => 'Архивирана марка',
            $this->brandInactive => 'Неактивна марка',
            default => null,
        };
    }
}
