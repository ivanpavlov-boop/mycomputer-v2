<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductDataQualityScanner
{
    public const ISSUE_MISSING_IMAGE = 'missing_image';

    public const ISSUE_MISSING_CATEGORY = 'missing_category';

    public const ISSUE_MISSING_BRAND = 'missing_brand';

    public const ISSUE_MISSING_SEO = 'missing_seo';

    public const ISSUE_MISSING_EN_TRANSLATION = 'missing_en_translation';

    public const ISSUE_WEAK_DESCRIPTION = 'weak_description';

    public const ISSUE_MISSING_ATTRIBUTES = 'missing_attributes';

    public const ISSUE_MISSING_EAN = 'missing_ean';

    public const MIN_DESCRIPTION_LENGTH = 80;

    public const MIN_SHORT_DESCRIPTION_LENGTH = 30;

    /**
     * @return array<string, string>
     */
    public static function issueOptions(): array
    {
        return [
            self::ISSUE_MISSING_IMAGE => 'Липсва снимка',
            self::ISSUE_MISSING_CATEGORY => 'Липсва категория',
            self::ISSUE_MISSING_BRAND => 'Липсва бранд',
            self::ISSUE_MISSING_SEO => 'Липсва SEO',
            self::ISSUE_MISSING_EN_TRANSLATION => 'Липсва EN превод',
            self::ISSUE_WEAK_DESCRIPTION => 'Слабо описание',
            self::ISSUE_MISSING_ATTRIBUTES => 'Липсват атрибути',
            self::ISSUE_MISSING_EAN => 'Липсва EAN',
        ];
    }

    /**
     * @param  array<int, string>|null  $issueCodes
     * @return array<int, array{code: string, label: string, severity: string}>
     */
    public function detectedIssues(Product $product, ?array $issueCodes = null): array
    {
        $issues = [];

        $options = self::issueOptions();

        foreach ($issueCodes ?? array_keys($options) as $code) {
            $label = $options[$code] ?? $code;

            if ($this->productHasIssue($product, $code)) {
                $issues[] = [
                    'code' => $code,
                    'label' => $label,
                    'severity' => $this->issueSeverity($code),
                ];
            }
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    public function issueLabels(Product $product): array
    {
        return collect($this->detectedIssues($product))
            ->pluck('label')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function activeFlagLabels(Product $product): array
    {
        return $product->activeQualityFlagAssignments
            ->map(fn ($assignment): ?string => $assignment->flag?->label_bg ?? $assignment->flag?->label_en)
            ->filter()
            ->values()
            ->all();
    }

    public function productHasIssue(Product $product, string $issue): bool
    {
        return match ($issue) {
            self::ISSUE_MISSING_IMAGE => ! $product->relationLoaded('images')
                ? ! $product->images()->exists()
                : $product->images->isEmpty(),
            self::ISSUE_MISSING_CATEGORY => blank($product->category_id),
            self::ISSUE_MISSING_BRAND => blank($product->brand_id),
            self::ISSUE_MISSING_SEO => blank($product->meta_title) || blank($product->meta_description),
            self::ISSUE_MISSING_EN_TRANSLATION => $this->missingEnglishTranslation($product),
            self::ISSUE_WEAK_DESCRIPTION => $this->hasWeakDescription($product),
            self::ISSUE_MISSING_ATTRIBUTES => $this->missingAttributes($product),
            self::ISSUE_MISSING_EAN => blank($product->ean),
            default => false,
        };
    }

    public function applyQueueScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach (array_keys(self::issueOptions()) as $issue) {
                $query->orWhere(fn (Builder $query): Builder => $this->applyIssueQuery($query, $issue));
            }

            $query->orWhereHas('activeQualityFlagAssignments');
        });
    }

    public function applyIssueQuery(Builder $query, ?string $issue): Builder
    {
        return match ($issue) {
            self::ISSUE_MISSING_IMAGE => $query->doesntHave('images'),
            self::ISSUE_MISSING_CATEGORY => $query->whereNull('category_id'),
            self::ISSUE_MISSING_BRAND => $query->whereNull('brand_id'),
            self::ISSUE_MISSING_SEO => $query->where(function (Builder $query): void {
                $query
                    ->whereNull('meta_title')
                    ->orWhere('meta_title', '')
                    ->orWhereNull('meta_description')
                    ->orWhere('meta_description', '');
            }),
            self::ISSUE_MISSING_EN_TRANSLATION => $query->where(function (Builder $query): void {
                $query
                    ->whereNull('name_translations')
                    ->orWhereNull('name_translations->en')
                    ->orWhere('name_translations->en', '')
                    ->orWhereNull('description_translations')
                    ->orWhereNull('description_translations->en')
                    ->orWhere('description_translations->en', '')
                    ->orWhereNull('meta_title_translations')
                    ->orWhereNull('meta_title_translations->en')
                    ->orWhere('meta_title_translations->en', '');
            }),
            self::ISSUE_WEAK_DESCRIPTION => $query->where(function (Builder $query): void {
                $query
                    ->whereNull('description')
                    ->orWhere('description', '')
                    ->orWhereRaw('LENGTH(description) < ?', [self::MIN_DESCRIPTION_LENGTH])
                    ->orWhereNull('short_description')
                    ->orWhere('short_description', '')
                    ->orWhereRaw('LENGTH(short_description) < ?', [self::MIN_SHORT_DESCRIPTION_LENGTH]);
            }),
            self::ISSUE_MISSING_ATTRIBUTES => $query->where(function (Builder $query): void {
                $query
                    ->doesntHave('attributes')
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('specifications')
                            ->orWhere('specifications', '[]')
                            ->orWhere('specifications', '{}');
                    });
            }),
            self::ISSUE_MISSING_EAN => $query->where(fn (Builder $query): Builder => $query->whereNull('ean')->orWhere('ean', '')),
            default => $query,
        };
    }

    public function issueSeverity(string $issue): string
    {
        return match ($issue) {
            self::ISSUE_MISSING_IMAGE,
            self::ISSUE_MISSING_CATEGORY,
            self::ISSUE_MISSING_BRAND,
            self::ISSUE_MISSING_SEO => 'high',
            self::ISSUE_WEAK_DESCRIPTION,
            self::ISSUE_MISSING_ATTRIBUTES,
            self::ISSUE_MISSING_EN_TRANSLATION => 'medium',
            default => 'low',
        };
    }

    private function missingEnglishTranslation(Product $product): bool
    {
        return blank($product->localizedField('name', 'en', fallbackToPrimary: false))
            || blank($product->localizedField('description', 'en', fallbackToPrimary: false))
            || blank($product->localizedField('meta_title', 'en', fallbackToPrimary: false));
    }

    private function hasWeakDescription(Product $product): bool
    {
        $description = Str::squish(strip_tags((string) $product->description));
        $shortDescription = Str::squish(strip_tags((string) $product->short_description));

        return mb_strlen($description) < self::MIN_DESCRIPTION_LENGTH
            || mb_strlen($shortDescription) < self::MIN_SHORT_DESCRIPTION_LENGTH;
    }

    private function missingAttributes(Product $product): bool
    {
        $hasAssignedAttributes = $product->relationLoaded('attributes')
            ? $product->attributes->isNotEmpty()
            : $product->attributes()->exists();

        return ! $hasAssignedAttributes && blank($product->specifications);
    }
}
