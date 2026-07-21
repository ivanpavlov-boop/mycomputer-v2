<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\User;

final class ProductDataQualitySummaryService
{
    /**
     * @var array<int, string>
     */
    private const CORE_ISSUE_CODES = [
        ProductDataQualityScanner::ISSUE_MISSING_IMAGE,
        ProductDataQualityScanner::ISSUE_MISSING_CATEGORY,
        ProductDataQualityScanner::ISSUE_MISSING_BRAND,
        ProductDataQualityScanner::ISSUE_MISSING_SEO,
        ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION,
        ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION,
        ProductDataQualityScanner::ISSUE_MISSING_EAN,
    ];

    /**
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        User::ROLE_SUPER_ADMIN => 'Супер администратор',
        User::ROLE_CATALOG_MANAGER => 'Каталог',
        User::ROLE_PRODUCT_EDITOR => 'Редактор на продукти',
        User::ROLE_PRODUCT_DATA_ENTRY => 'Въвеждане на продукти',
        User::ROLE_PRICING_MANAGER => 'Цени',
        User::ROLE_INVENTORY_MANAGER => 'Наличност',
        User::ROLE_SEO_MARKETING => 'SEO / Маркетинг',
        User::ROLE_ORDER_MANAGER => 'Поръчки',
        User::ROLE_VIEWER_AUDITOR => 'Преглед / одит',
    ];

    public function __construct(
        private readonly ProductDataQualityScanner $scanner,
        private readonly ProductSpecificationQualityService $specificationQuality,
        private readonly ProductCategoryBrandQualityService $categoryBrandQuality,
    ) {}

    public function summarize(Product $product): ProductDataQualitySummaryResult
    {
        $product->loadMissing([
            'images',
            'thumbnailImage',
            'category.parent',
            'brand',
            'activeQualityFlagAssignments.flag',
            'attributeValues.value',
        ]);

        $coreIssues = collect($this->scanner->detectedIssues($product, self::CORE_ISSUE_CODES))
            ->map(fn (array $issue): array => [
                ...$issue,
                'level' => $this->coreIssueLevel($issue['code']),
                'color' => $this->levelColor($this->coreIssueLevel($issue['code'])),
            ])
            ->sortBy(fn (array $issue): array => [
                $this->levelRank($issue['level']),
                array_search($issue['code'], self::CORE_ISSUE_CODES, true),
            ])
            ->values()
            ->all();

        $specificationResult = $this->specificationQuality->evaluate($product);
        $specificationLevel = $this->specificationLevel($specificationResult);
        $categoryBrandResult = $this->categoryBrandQuality->evaluate($product);
        $manualFlags = $product->activeQualityFlagAssignments
            ->filter(fn ($assignment): bool => (bool) $assignment->flag?->is_active)
            ->map(function ($assignment): array {
                $flag = $assignment->flag;
                $level = $this->manualFlagLevel($flag?->severity);
                $role = $flag?->responsible_role;

                return [
                    'label' => $flag?->label_bg ?: $flag?->label_en ?: $flag?->code ?: 'Флаг без етикет',
                    'severity' => $flag?->severity ?? 'unknown',
                    'severity_label' => $this->severityLabel($flag?->severity),
                    'responsible_role' => $role,
                    'responsible_role_label' => $role ? (self::ROLE_LABELS[$role] ?? $role) : 'Няма',
                    'level' => $level,
                    'color' => $this->levelColor($level),
                ];
            })
            ->sortBy(fn (array $flag): array => [$this->levelRank($flag['level']), $flag['label']])
            ->values()
            ->all();

        $levels = collect($coreIssues)->pluck('level')
            ->when($specificationLevel !== null, fn ($levels) => $levels->push($specificationLevel))
            ->merge(array_fill(0, count($categoryBrandResult->warnings), 'warning'))
            ->merge(collect($manualFlags)->pluck('level'));

        $criticalCount = $levels->filter(fn (string $level): bool => $level === 'critical')->count();
        $warningCount = $levels->filter(fn (string $level): bool => $level === 'warning')->count();
        $recommendationCount = $levels->filter(fn (string $level): bool => $level === 'recommendation')->count();
        $totalCount = $criticalCount + $warningCount + $recommendationCount;

        return new ProductDataQualitySummaryResult(
            overallStatus: match (true) {
                $criticalCount > 0 => ProductDataQualitySummaryResult::STATUS_CRITICAL,
                $totalCount > 0 => ProductDataQualitySummaryResult::STATUS_NEEDS_ATTENTION,
                default => ProductDataQualitySummaryResult::STATUS_GOOD,
            },
            coreIssues: $coreIssues,
            criticalIssueCount: $criticalCount,
            warningIssueCount: $warningCount,
            recommendationIssueCount: $recommendationCount,
            specificationResult: $specificationResult,
            categoryBrandQuality: $categoryBrandResult,
            manualFlags: $manualFlags,
            activeManualQualityFlagLabels: collect($manualFlags)->pluck('label')->all(),
            totalActionableIssueCount: $totalCount,
            nextSteps: $this->nextSteps($coreIssues, $specificationResult, $categoryBrandResult, $manualFlags),
        );
    }

    private function coreIssueLevel(string $code): string
    {
        return match ($code) {
            ProductDataQualityScanner::ISSUE_MISSING_IMAGE,
            ProductDataQualityScanner::ISSUE_MISSING_CATEGORY,
            ProductDataQualityScanner::ISSUE_MISSING_BRAND,
            ProductDataQualityScanner::ISSUE_MISSING_SEO => 'critical',
            ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION => 'warning',
            ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION,
            ProductDataQualityScanner::ISSUE_MISSING_EAN => 'recommendation',
            default => 'recommendation',
        };
    }

    private function specificationLevel(ProductSpecificationQualityResult $result): ?string
    {
        return match ($result->status) {
            ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED => 'critical',
            ProductSpecificationQualityResult::STATUS_NEEDS_DATA,
            ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE => 'warning',
            ProductSpecificationQualityResult::STATUS_GOOD => null,
            default => 'warning',
        };
    }

    private function manualFlagLevel(?string $severity): string
    {
        return match ($severity) {
            ProductQualityFlag::SEVERITY_HIGH => 'critical',
            ProductQualityFlag::SEVERITY_MEDIUM => 'warning',
            default => 'recommendation',
        };
    }

    private function severityLabel(?string $severity): string
    {
        return match ($severity) {
            ProductQualityFlag::SEVERITY_HIGH => 'Висока',
            ProductQualityFlag::SEVERITY_MEDIUM => 'Средна',
            ProductQualityFlag::SEVERITY_LOW => 'Ниска',
            default => 'Неизвестна',
        };
    }

    private function levelColor(string $level): string
    {
        return match ($level) {
            'critical' => 'danger',
            'warning' => 'warning',
            'recommendation' => 'info',
            default => 'gray',
        };
    }

    private function levelRank(string $level): int
    {
        return match ($level) {
            'critical' => 0,
            'warning' => 1,
            'recommendation' => 2,
            default => 3,
        };
    }

    /**
     * @param  array<int, array{code: string, label: string, severity: string, level: string, color: string}>  $coreIssues
     * @param  array<int, array{label: string, severity: string, severity_label: string, responsible_role: ?string, responsible_role_label: string, level: string, color: string}>  $manualFlags
     * @return array<int, string>
     */
    private function nextSteps(
        array $coreIssues,
        ProductSpecificationQualityResult $specificationResult,
        ProductCategoryBrandQualityResult $categoryBrandResult,
        array $manualFlags,
    ): array {
        $steps = collect($coreIssues)->map(fn (array $issue): string => match ($issue['code']) {
            ProductDataQualityScanner::ISSUE_MISSING_CATEGORY => 'Задайте категория',
            ProductDataQualityScanner::ISSUE_MISSING_BRAND => 'Задайте марка',
            ProductDataQualityScanner::ISSUE_MISSING_IMAGE => 'Добавете продуктова снимка',
            ProductDataQualityScanner::ISSUE_MISSING_SEO => 'Попълнете SEO заглавие и описание',
            ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION => 'Допълнете краткото и пълното описание',
            ProductDataQualityScanner::ISSUE_MISSING_EAN => 'Добавете EAN, когато е приложимо',
            ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION => 'Добавете английска локализация',
            default => 'Прегледайте продуктовите данни',
        });

        if ($specificationResult->status !== ProductSpecificationQualityResult::STATUS_GOOD) {
            $steps->push(match ($specificationResult->status) {
                ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE => 'Прегледайте шаблона за характеристики на категорията',
                default => 'Попълнете важните характеристики',
            });
        }

        if ($categoryBrandResult->categoryWarning() !== null) {
            $steps->push('Прегледайте състоянието на зададената категория');
        }

        if ($categoryBrandResult->brandWarning() !== null) {
            $steps->push('Прегледайте състоянието на зададената марка');
        }

        if ($manualFlags !== []) {
            $steps->push('Прегледайте активните флагове за качество');
        }

        return $steps->unique()->values()->all();
    }
}
