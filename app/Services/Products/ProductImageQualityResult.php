<?php

namespace App\Services\Products;

final class ProductImageQualityResult
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_NO_IMAGES = 'no_images';

    public const STATE_MULTIPLE_PRIMARY = 'multiple_primary';

    public const STATE_MISSING_PRIMARY = 'missing_primary';

    public const STATE_MISSING_ALT_ALL = 'missing_alt_all';

    public const STATE_MISSING_ALT_PARTIAL = 'missing_alt_partial';

    public readonly string $stateLabel;

    public readonly string $stateColor;

    public readonly string $imageCountLabel;

    public readonly string $primaryStatusLabel;

    public readonly string $primaryStatusColor;

    public readonly int $altCompletionPercentage;

    public readonly string $altCoverageLabel;

    public readonly int $criticalIssueCount;

    public readonly int $warningIssueCount;

    public readonly int $recommendationIssueCount;

    /**
     * @param  array<int, array{code: string, label: string, level: string, color: string}>  $issues
     * @param  array<int, string>  $nextSteps
     * @param  array<int, string>  $altTextSamples
     */
    public function __construct(
        public readonly string $state,
        public readonly int $totalImageCount,
        public readonly int $eligibleImageCount,
        public readonly int $primaryImageCount,
        public readonly int $imagesWithAltText,
        public readonly int $imagesMissingAltText,
        public readonly array $issues,
        public readonly array $nextSteps,
        public readonly array $altTextSamples,
    ) {
        $this->stateLabel = self::labelFor($state);
        $this->stateColor = self::colorFor($state);
        $this->imageCountLabel = $eligibleImageCount === 1 ? '1 снимка' : $eligibleImageCount.' снимки';
        $this->primaryStatusLabel = match (true) {
            $primaryImageCount > 1 => 'Повече от една',
            $primaryImageCount === 1 => 'Зададена',
            default => 'Липсва',
        };
        $this->primaryStatusColor = match (true) {
            $primaryImageCount > 1 => 'danger',
            $primaryImageCount === 1 => 'success',
            $eligibleImageCount > 0 => 'warning',
            default => 'gray',
        };
        $this->altCompletionPercentage = $eligibleImageCount === 0
            ? 0
            : (int) round(($imagesWithAltText / $eligibleImageCount) * 100);
        $this->altCoverageLabel = $eligibleImageCount === 0
            ? '0/0'
            : sprintf('%d/%d · %d%%', $imagesWithAltText, $eligibleImageCount, $this->altCompletionPercentage);
        $this->criticalIssueCount = $this->countIssues('critical');
        $this->warningIssueCount = $this->countIssues('warning');
        $this->recommendationIssueCount = $this->countIssues('recommendation');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::STATE_NO_IMAGES => self::labelFor(self::STATE_NO_IMAGES),
            self::STATE_MULTIPLE_PRIMARY => self::labelFor(self::STATE_MULTIPLE_PRIMARY),
            self::STATE_MISSING_PRIMARY => self::labelFor(self::STATE_MISSING_PRIMARY),
            self::STATE_MISSING_ALT_ALL => self::labelFor(self::STATE_MISSING_ALT_ALL),
            self::STATE_MISSING_ALT_PARTIAL => self::labelFor(self::STATE_MISSING_ALT_PARTIAL),
            self::STATE_COMPLETE => self::labelFor(self::STATE_COMPLETE),
        ];
    }

    public static function labelFor(string $state): string
    {
        return match ($state) {
            self::STATE_COMPLETE => 'Снимките и ALT текстовете са попълнени',
            self::STATE_NO_IMAGES => 'Липсват продуктови снимки',
            self::STATE_MULTIPLE_PRIMARY => 'Има повече от една основна снимка',
            self::STATE_MISSING_PRIMARY => 'Липсва основна снимка',
            self::STATE_MISSING_ALT_ALL => 'Липсва ALT текст за всички снимки',
            self::STATE_MISSING_ALT_PARTIAL => 'Липсва ALT текст за част от снимките',
            default => 'Неизвестно',
        };
    }

    public static function colorFor(string $state): string
    {
        return match ($state) {
            self::STATE_COMPLETE => 'success',
            self::STATE_NO_IMAGES,
            self::STATE_MULTIPLE_PRIMARY => 'danger',
            self::STATE_MISSING_PRIMARY,
            self::STATE_MISSING_ALT_ALL,
            self::STATE_MISSING_ALT_PARTIAL => 'warning',
            default => 'gray',
        };
    }

    private function countIssues(string $level): int
    {
        return count(array_filter(
            $this->issues,
            fn (array $issue): bool => $issue['level'] === $level,
        ));
    }
}
