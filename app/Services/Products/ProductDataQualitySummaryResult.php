<?php

namespace App\Services\Products;

final class ProductDataQualitySummaryResult
{
    public const STATUS_GOOD = 'good';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_CRITICAL = 'critical';

    public readonly string $overallLabel;

    public readonly string $statusColor;

    /**
     * @param  array<int, array{code: string, label: string, severity: string, level: string, color: string}>  $coreIssues
     * @param  array<int, array{label: string, severity: string, severity_label: string, responsible_role: ?string, responsible_role_label: string, level: string, color: string}>  $manualFlags
     * @param  array<int, string>  $activeManualQualityFlagLabels
     * @param  array<int, string>  $nextSteps
     */
    public function __construct(
        public readonly string $overallStatus,
        public readonly array $coreIssues,
        public readonly int $criticalIssueCount,
        public readonly int $warningIssueCount,
        public readonly int $recommendationIssueCount,
        public readonly ProductSpecificationQualityResult $specificationResult,
        public readonly ProductCategoryBrandQualityResult $categoryBrandQuality,
        public readonly ProductImageQualityResult $imageQuality,
        public readonly ProductSeoDescriptionQualityResult $seoDescriptionQuality,
        public readonly array $manualFlags,
        public readonly array $activeManualQualityFlagLabels,
        public readonly int $totalActionableIssueCount,
        public readonly array $nextSteps,
    ) {
        $this->overallLabel = match ($overallStatus) {
            self::STATUS_GOOD => 'Добро',
            self::STATUS_NEEDS_ATTENTION => 'Нуждае се от допълване',
            self::STATUS_CRITICAL => 'Критични липси',
            default => 'Неизвестно',
        };

        $this->statusColor = match ($overallStatus) {
            self::STATUS_GOOD => 'success',
            self::STATUS_NEEDS_ATTENTION => 'warning',
            self::STATUS_CRITICAL => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<int, array{code: string, label: string, severity: string, level: string, color: string}>
     */
    public function coreIssuesForLevel(string $level): array
    {
        return array_values(array_filter(
            $this->coreIssues,
            fn (array $issue): bool => $issue['level'] === $level,
        ));
    }
}
