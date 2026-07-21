<?php

namespace App\Services\Products;

final class ProductSeoDescriptionQualityResult
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_MISSING_DESCRIPTIONS = 'missing_descriptions';

    public const STATE_MISSING_FULL_DESCRIPTION = 'missing_full_description';

    public const STATE_MISSING_SHORT_DESCRIPTION = 'missing_short_description';

    public const STATE_MISSING_SEO = 'missing_seo';

    public const STATE_INCOMPLETE_SEO = 'incomplete_seo';

    public const STATE_WEAK_DESCRIPTION = 'weak_description';

    public const STATE_MISSING_EN_TRANSLATION = 'missing_en_translation';

    public readonly string $stateLabel;

    public readonly string $stateColor;

    public readonly int $seoCompletionPercentage;

    public readonly int $descriptionCompletionPercentage;

    public readonly int $englishCompletionPercentage;

    public readonly string $seoScoreLabel;

    public readonly string $descriptionScoreLabel;

    public readonly string $englishScoreLabel;

    public readonly int $criticalIssueCount;

    public readonly int $warningIssueCount;

    public readonly int $recommendationIssueCount;

    /**
     * @param  array<int, string>  $missingEnglishFieldLabels
     * @param  array<int, array{code: string, label: string, level: string, color: string}>  $issues
     * @param  array<int, string>  $nextSteps
     */
    public function __construct(
        public readonly string $state,
        public readonly bool $seoTitlePresent,
        public readonly bool $seoDescriptionPresent,
        public readonly int $seoCompletedCount,
        public readonly int $seoExpectedCount,
        public readonly bool $shortDescriptionPresent,
        public readonly bool $fullDescriptionPresent,
        public readonly int $descriptionCompletedCount,
        public readonly int $descriptionExpectedCount,
        public readonly bool $weakDescription,
        public readonly int $englishCompletedCount,
        public readonly int $englishExpectedCount,
        public readonly array $missingEnglishFieldLabels,
        public readonly array $issues,
        public readonly array $nextSteps,
        public readonly int $seoTitleLength,
        public readonly int $seoDescriptionLength,
        public readonly int $shortDescriptionLength,
        public readonly int $fullDescriptionLength,
    ) {
        $this->stateLabel = self::labelFor($state);
        $this->stateColor = self::colorFor($state);
        $this->seoCompletionPercentage = self::percentage($seoCompletedCount, $seoExpectedCount);
        $this->descriptionCompletionPercentage = self::percentage($descriptionCompletedCount, $descriptionExpectedCount);
        $this->englishCompletionPercentage = self::percentage($englishCompletedCount, $englishExpectedCount);
        $this->seoScoreLabel = self::scoreLabel($seoCompletedCount, $seoExpectedCount, $this->seoCompletionPercentage);
        $this->descriptionScoreLabel = self::scoreLabel($descriptionCompletedCount, $descriptionExpectedCount, $this->descriptionCompletionPercentage);
        $this->englishScoreLabel = self::scoreLabel($englishCompletedCount, $englishExpectedCount, $this->englishCompletionPercentage);
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
            self::STATE_MISSING_DESCRIPTIONS => self::labelFor(self::STATE_MISSING_DESCRIPTIONS),
            self::STATE_MISSING_FULL_DESCRIPTION => self::labelFor(self::STATE_MISSING_FULL_DESCRIPTION),
            self::STATE_MISSING_SHORT_DESCRIPTION => self::labelFor(self::STATE_MISSING_SHORT_DESCRIPTION),
            self::STATE_MISSING_SEO => self::labelFor(self::STATE_MISSING_SEO),
            self::STATE_INCOMPLETE_SEO => self::labelFor(self::STATE_INCOMPLETE_SEO),
            self::STATE_WEAK_DESCRIPTION => self::labelFor(self::STATE_WEAK_DESCRIPTION),
            self::STATE_MISSING_EN_TRANSLATION => self::labelFor(self::STATE_MISSING_EN_TRANSLATION),
            self::STATE_COMPLETE => self::labelFor(self::STATE_COMPLETE),
        ];
    }

    public static function labelFor(string $state): string
    {
        return match ($state) {
            self::STATE_COMPLETE => 'SEO, описанията и локализацията са попълнени',
            self::STATE_MISSING_DESCRIPTIONS => 'Липсват кратко и пълно описание',
            self::STATE_MISSING_FULL_DESCRIPTION => 'Липсва пълно описание',
            self::STATE_MISSING_SHORT_DESCRIPTION => 'Липсва кратко описание',
            self::STATE_MISSING_SEO => 'Липсват SEO заглавие и описание',
            self::STATE_INCOMPLETE_SEO => 'SEO данните са непълни',
            self::STATE_WEAK_DESCRIPTION => 'Описанията се нуждаят от допълване',
            self::STATE_MISSING_EN_TRANSLATION => 'Английската локализация е непълна',
            default => 'Неизвестно',
        };
    }

    public static function colorFor(string $state): string
    {
        return match ($state) {
            self::STATE_COMPLETE => 'success',
            self::STATE_MISSING_SEO => 'danger',
            self::STATE_MISSING_DESCRIPTIONS,
            self::STATE_MISSING_FULL_DESCRIPTION,
            self::STATE_MISSING_SHORT_DESCRIPTION,
            self::STATE_INCOMPLETE_SEO,
            self::STATE_WEAK_DESCRIPTION => 'warning',
            self::STATE_MISSING_EN_TRANSLATION => 'info',
            default => 'gray',
        };
    }

    /**
     * @return array<int, string>
     */
    public function issueLabels(): array
    {
        return collect($this->issues)->pluck('label')->unique()->values()->all();
    }

    private static function percentage(int $completed, int $expected): int
    {
        return $expected === 0 ? 0 : (int) round(($completed / $expected) * 100);
    }

    private static function scoreLabel(int $completed, int $expected, int $percentage): string
    {
        return sprintf('%d/%d · %d%%', $completed, $expected, $percentage);
    }

    private function countIssues(string $level): int
    {
        return count(array_filter(
            $this->issues,
            fn (array $issue): bool => $issue['level'] === $level,
        ));
    }
}
