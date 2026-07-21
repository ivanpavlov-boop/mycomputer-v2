<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

final class ProductImageQualityService
{
    /** @var WeakMap<Product, ProductImageQualityResult> */
    private WeakMap $results;

    public function __construct(
        private readonly ProductDataQualityScanner $scanner,
    ) {
        $this->results = new WeakMap;
    }

    public function evaluate(Product $product): ProductImageQualityResult
    {
        return $this->results[$product] ??= $this->evaluateFresh($product);
    }

    public function applyStateQuery(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            ProductImageQualityResult::STATE_NO_IMAGES => $this->scanner
                ->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_IMAGE),
            ProductImageQualityResult::STATE_MULTIPLE_PRIMARY => $query
                ->whereHas('images', fn (Builder $query): Builder => $query->where('is_primary', true), '>', 1),
            ProductImageQualityResult::STATE_MISSING_PRIMARY => $query
                ->has('images')
                ->doesntHave('images', 'and', fn (Builder $query): Builder => $query->where('is_primary', true)),
            ProductImageQualityResult::STATE_MISSING_ALT_ALL => $query
                ->has('images')
                ->whereHas('images', fn (Builder $query): Builder => $query->where('is_primary', true), '=', 1)
                ->doesntHave('images', 'and', fn (Builder $query): Builder => $this->whereAltPresent($query)),
            ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL => $query
                ->has('images')
                ->whereHas('images', fn (Builder $query): Builder => $query->where('is_primary', true), '=', 1)
                ->whereHas('images', fn (Builder $query): Builder => $this->whereAltMissing($query))
                ->whereHas('images', fn (Builder $query): Builder => $this->whereAltPresent($query)),
            ProductImageQualityResult::STATE_COMPLETE => $query
                ->has('images')
                ->whereHas('images', fn (Builder $query): Builder => $query->where('is_primary', true), '=', 1)
                ->doesntHave('images', 'and', fn (Builder $query): Builder => $this->whereAltMissing($query)),
            default => $query,
        };
    }

    /**
     * @return array{no_images: int, multiple_primary: int, missing_primary: int, missing_alt_all: int, missing_alt_partial: int, complete: int}
     */
    public function countsFor(Builder $query): array
    {
        return collect(array_keys(ProductImageQualityResult::options()))
            ->mapWithKeys(fn (string $state): array => [
                $state => $this->applyStateQuery(clone $query, $state)->count(),
            ])
            ->all();
    }

    public function countWithMissingAltFor(Builder $query): int
    {
        return $query
            ->has('images')
            ->whereHas('images', fn (Builder $query): Builder => $this->whereAltMissing($query))
            ->count();
    }

    private function evaluateFresh(Product $product): ProductImageQualityResult
    {
        $product->loadMissing('images');

        $images = $product->images;
        $imageCount = $images->count();
        $primaryCount = $images->where('is_primary', true)->count();
        $imagesWithAlt = $images->filter(fn ($image): bool => trim((string) $image->alt_text) !== '')->count();
        $imagesMissingAlt = $imageCount - $imagesWithAlt;
        $state = match (true) {
            $imageCount === 0 => ProductImageQualityResult::STATE_NO_IMAGES,
            $primaryCount > 1 => ProductImageQualityResult::STATE_MULTIPLE_PRIMARY,
            $primaryCount === 0 => ProductImageQualityResult::STATE_MISSING_PRIMARY,
            $imagesMissingAlt === $imageCount => ProductImageQualityResult::STATE_MISSING_ALT_ALL,
            $imagesMissingAlt > 0 => ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL,
            default => ProductImageQualityResult::STATE_COMPLETE,
        };

        $issues = [];

        if ($imageCount === 0) {
            $issues[] = $this->issue(ProductImageQualityResult::STATE_NO_IMAGES, 'critical');
        } else {
            if ($primaryCount > 1) {
                $issues[] = $this->issue(ProductImageQualityResult::STATE_MULTIPLE_PRIMARY, 'critical');
            } elseif ($primaryCount === 0) {
                $issues[] = $this->issue(ProductImageQualityResult::STATE_MISSING_PRIMARY, 'warning');
            }

            if ($imagesMissingAlt === $imageCount) {
                $issues[] = $this->issue(ProductImageQualityResult::STATE_MISSING_ALT_ALL, 'warning');
            } elseif ($imagesMissingAlt > 0) {
                $issues[] = $this->issue(ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL, 'warning');
            }
        }

        return new ProductImageQualityResult(
            state: $state,
            totalImageCount: $imageCount,
            eligibleImageCount: $imageCount,
            primaryImageCount: $primaryCount,
            imagesWithAltText: $imagesWithAlt,
            imagesMissingAltText: $imagesMissingAlt,
            issues: $issues,
            nextSteps: collect($issues)
                ->map(fn (array $issue): string => match ($issue['code']) {
                    ProductImageQualityResult::STATE_NO_IMAGES => 'Добавете продуктова снимка',
                    ProductImageQualityResult::STATE_MULTIPLE_PRIMARY => 'Оставете само една основна снимка',
                    ProductImageQualityResult::STATE_MISSING_PRIMARY => 'Задайте основна снимка',
                    ProductImageQualityResult::STATE_MISSING_ALT_ALL => 'Добавете ALT текст към всички снимки',
                    ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL => 'Допълнете липсващите ALT текстове',
                    default => 'Прегледайте продуктовите снимки',
                })
                ->unique()
                ->values()
                ->all(),
            altTextSamples: $images
                ->map(fn ($image): string => trim((string) $image->alt_text))
                ->filter()
                ->take(3)
                ->values()
                ->all(),
        );
    }

    /**
     * @return array{code: string, label: string, level: string, color: string}
     */
    private function issue(string $code, string $level): array
    {
        return [
            'code' => $code,
            'label' => ProductImageQualityResult::labelFor($code),
            'level' => $level,
            'color' => $level === 'critical' ? 'danger' : 'warning',
        ];
    }

    private function whereAltMissing(Builder $query): Builder
    {
        return $query->whereRaw("TRIM(COALESCE(alt_text, '')) = ''");
    }

    private function whereAltPresent(Builder $query): Builder
    {
        return $query->whereRaw("TRIM(COALESCE(alt_text, '')) <> ''");
    }
}
