<?php

namespace App\Services\Taxonomy;

use App\Models\SupplierCategoryMapping;
use Illuminate\Support\Str;

class SupplierCategoryFamilyInferenceService
{
    public function __construct(private readonly CanonicalProductFamilyCatalog $catalog) {}

    /**
     * @param  array<int, string|null>  $parts
     * @return array{family_code: string, confidence: string, match_reason: string, matched_families: array<string, array<int, string>>}
     */
    public function infer(array $parts): array
    {
        $haystack = $this->normalize(implode(' ', array_filter($parts, fn (?string $part): bool => filled($part))));
        $matches = [];

        foreach ($this->catalog->keywords() as $familyCode => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $this->normalize($keyword))) {
                    $matches[$familyCode][] = $keyword;
                }
            }
        }

        if ($matches === []) {
            return [
                'family_code' => 'unknown',
                'confidence' => SupplierCategoryMapping::CONFIDENCE_LOW,
                'match_reason' => 'No conservative keyword match; manual classification required.',
                'matched_families' => [],
            ];
        }

        $matches = collect($matches)
            ->sortByDesc(fn (array $keywords): int => count($keywords))
            ->all();
        $scores = collect($matches)->map(fn (array $keywords): int => count($keywords));
        $topFamily = (string) $scores->keys()->first();
        $topScore = (int) $scores->first();
        $multipleFamilies = $scores->count() > 1;
        $secondScore = $multipleFamilies ? (int) $scores->values()->get(1) : 0;

        $confidence = match (true) {
            $multipleFamilies && $secondScore === $topScore => SupplierCategoryMapping::CONFIDENCE_LOW,
            $topScore >= 2 => SupplierCategoryMapping::CONFIDENCE_HIGH,
            default => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        };

        $reason = sprintf(
            'Matched %s using keyword(s): %s.',
            $topFamily,
            implode(', ', $matches[$topFamily]),
        );

        if ($multipleFamilies) {
            $reason .= ' Other possible family matches: '.$scores
                ->keys()
                ->skip(1)
                ->map(fn (string $family): string => $family.' ('.implode(', ', $matches[$family]).')')
                ->implode('; ').'.';
        }

        return [
            'family_code' => $topFamily,
            'confidence' => $confidence,
            'match_reason' => $reason,
            'matched_families' => $matches,
        ];
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();
    }
}
