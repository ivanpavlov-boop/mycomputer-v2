<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use WeakMap;

final class ProductSeoDescriptionQualityService
{
    private const SEO_EXPECTED_COUNT = 2;

    private const DESCRIPTION_EXPECTED_COUNT = 2;

    /**
     * The existing scanner defines these three English fields as expected.
     *
     * @var array<string, array{label: string, rich: bool}>
     */
    private const EXPECTED_ENGLISH_FIELDS = [
        'name' => ['label' => 'Липсва английско име', 'rich' => false],
        'description' => ['label' => 'Липсва английско пълно описание', 'rich' => true],
        'meta_title' => ['label' => 'Липсва английско SEO заглавие', 'rich' => false],
    ];

    /** @var WeakMap<Product, ProductSeoDescriptionQualityResult> */
    private WeakMap $results;

    public function __construct(
        private readonly ProductDataQualityScanner $scanner,
    ) {
        $this->results = new WeakMap;
    }

    public function evaluate(Product $product): ProductSeoDescriptionQualityResult
    {
        return $this->results[$product] ??= $this->evaluateFresh($product);
    }

    public function applyStateQuery(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS => $query
                ->where(fn (Builder $query) => $this->whereRichMissing($query, 'short_description'))
                ->where(fn (Builder $query) => $this->whereRichMissing($query, 'description')),
            ProductSeoDescriptionQualityResult::STATE_MISSING_FULL_DESCRIPTION => $query
                ->where(fn (Builder $query) => $this->whereRichPresent($query, 'short_description'))
                ->where(fn (Builder $query) => $this->whereRichMissing($query, 'description')),
            ProductSeoDescriptionQualityResult::STATE_MISSING_SHORT_DESCRIPTION => $query
                ->where(fn (Builder $query) => $this->whereRichMissing($query, 'short_description'))
                ->where(fn (Builder $query) => $this->whereRichPresent($query, 'description')),
            ProductSeoDescriptionQualityResult::STATE_MISSING_SEO => $this->whereDescriptionsPresent($query)
                ->where(fn (Builder $query) => $this->wherePlainMissing($query, 'meta_title'))
                ->where(fn (Builder $query) => $this->wherePlainMissing($query, 'meta_description')),
            ProductSeoDescriptionQualityResult::STATE_INCOMPLETE_SEO => $this->whereDescriptionsPresent($query)
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $query): void {
                            $this->wherePlainMissing($query, 'meta_title');
                            $this->wherePlainPresent($query, 'meta_description');
                        })
                        ->orWhere(function (Builder $query): void {
                            $this->wherePlainPresent($query, 'meta_title');
                            $this->wherePlainMissing($query, 'meta_description');
                        });
                }),
            ProductSeoDescriptionQualityResult::STATE_WEAK_DESCRIPTION => $this->whereSeoPresent(
                $this->whereDescriptionsPresent($query)
            )->where(fn (Builder $query): Builder => $this->scanner->applyIssueQuery(
                $query,
                ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION,
            )),
            ProductSeoDescriptionQualityResult::STATE_MISSING_EN_TRANSLATION => $this->whereNotWeak(
                $this->whereSeoPresent($this->whereDescriptionsPresent($query))
            )->where(fn (Builder $query) => $this->whereEnglishMissing($query)),
            ProductSeoDescriptionQualityResult::STATE_COMPLETE => $this->whereEnglishComplete(
                $this->whereNotWeak($this->whereSeoPresent($this->whereDescriptionsPresent($query)))
            ),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    public function countsFor(Builder $query): array
    {
        return collect(array_keys(ProductSeoDescriptionQualityResult::options()))
            ->mapWithKeys(fn (string $state): array => [
                $state => $this->applyStateQuery(clone $query, $state)->count(),
            ])
            ->all();
    }

    public function countWithMissingSeoFor(Builder $query): int
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->where(fn (Builder $query) => $this->wherePlainMissing($query, 'meta_title'))
                    ->orWhere(fn (Builder $query) => $this->wherePlainMissing($query, 'meta_description'));
            })
            ->count();
    }

    public function countWithMissingDescriptionsFor(Builder $query): int
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->where(fn (Builder $query) => $this->whereRichMissing($query, 'short_description'))
                    ->orWhere(fn (Builder $query) => $this->whereRichMissing($query, 'description'));
            })
            ->count();
    }

    public function countWithWeakDescriptionFor(Builder $query): int
    {
        return $this->scanner
            ->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION)
            ->count();
    }

    public function countWithMissingEnglishFor(Builder $query): int
    {
        return $query
            ->where(fn (Builder $query) => $this->whereEnglishMissing($query))
            ->count();
    }

    private function evaluateFresh(Product $product): ProductSeoDescriptionQualityResult
    {
        $seoTitle = $this->plainText((string) $product->meta_title);
        $seoDescription = $this->plainText((string) $product->meta_description);
        $shortDescription = $this->richText((string) $product->short_description);
        $fullDescription = $this->richText((string) $product->description);
        $seoTitlePresent = $seoTitle !== '';
        $seoDescriptionPresent = $seoDescription !== '';
        $shortDescriptionPresent = $shortDescription !== '';
        $fullDescriptionPresent = $fullDescription !== '';
        $weakDescription = $this->scanner->productHasIssue($product, ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION);
        $missingEnglishFields = collect(self::EXPECTED_ENGLISH_FIELDS)
            ->filter(function (array $definition, string $field) use ($product): bool {
                $value = (string) $product->localizedField($field, 'en', fallbackToPrimary: false);

                return $definition['rich'] ? $this->richText($value) === '' : $this->plainText($value) === '';
            });
        $seoCompleted = (int) $seoTitlePresent + (int) $seoDescriptionPresent;
        $descriptionCompleted = (int) $shortDescriptionPresent + (int) $fullDescriptionPresent;
        $englishCompleted = count(self::EXPECTED_ENGLISH_FIELDS) - $missingEnglishFields->count();
        $state = match (true) {
            ! $shortDescriptionPresent && ! $fullDescriptionPresent => ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS,
            ! $fullDescriptionPresent => ProductSeoDescriptionQualityResult::STATE_MISSING_FULL_DESCRIPTION,
            ! $shortDescriptionPresent => ProductSeoDescriptionQualityResult::STATE_MISSING_SHORT_DESCRIPTION,
            ! $seoTitlePresent && ! $seoDescriptionPresent => ProductSeoDescriptionQualityResult::STATE_MISSING_SEO,
            ! $seoTitlePresent || ! $seoDescriptionPresent => ProductSeoDescriptionQualityResult::STATE_INCOMPLETE_SEO,
            $weakDescription => ProductSeoDescriptionQualityResult::STATE_WEAK_DESCRIPTION,
            $missingEnglishFields->isNotEmpty() => ProductSeoDescriptionQualityResult::STATE_MISSING_EN_TRANSLATION,
            default => ProductSeoDescriptionQualityResult::STATE_COMPLETE,
        };
        $issues = collect();

        if (! $shortDescriptionPresent) {
            $issues->push($this->issue('missing_short_description', 'Липсва кратко описание', 'warning'));
        }

        if (! $fullDescriptionPresent) {
            $issues->push($this->issue('missing_full_description', 'Липсва пълно описание', 'warning'));
        }

        if (! $seoTitlePresent) {
            $issues->push($this->issue('missing_seo_title', 'Липсва SEO заглавие', 'critical'));
        }

        if (! $seoDescriptionPresent) {
            $issues->push($this->issue('missing_seo_description', 'Липсва SEO описание', 'critical'));
        }

        if ($shortDescriptionPresent && $fullDescriptionPresent && $weakDescription) {
            $issues->push($this->issue('weak_description', 'Съдържанието е твърде кратко', 'warning'));
        }

        foreach ($missingEnglishFields as $field => $definition) {
            $issues->push($this->issue('missing_en_'.$field, $definition['label'], 'recommendation'));
        }

        return new ProductSeoDescriptionQualityResult(
            state: $state,
            seoTitlePresent: $seoTitlePresent,
            seoDescriptionPresent: $seoDescriptionPresent,
            seoCompletedCount: $seoCompleted,
            seoExpectedCount: self::SEO_EXPECTED_COUNT,
            shortDescriptionPresent: $shortDescriptionPresent,
            fullDescriptionPresent: $fullDescriptionPresent,
            descriptionCompletedCount: $descriptionCompleted,
            descriptionExpectedCount: self::DESCRIPTION_EXPECTED_COUNT,
            weakDescription: $weakDescription,
            englishCompletedCount: $englishCompleted,
            englishExpectedCount: count(self::EXPECTED_ENGLISH_FIELDS),
            missingEnglishFieldLabels: $missingEnglishFields->pluck('label')->values()->all(),
            issues: $issues->all(),
            nextSteps: $this->nextSteps(
                $shortDescriptionPresent,
                $fullDescriptionPresent,
                $seoTitlePresent,
                $seoDescriptionPresent,
                $weakDescription,
                $missingEnglishFields->pluck('label')->values()->all(),
            ),
            seoTitleLength: mb_strlen($seoTitle),
            seoDescriptionLength: mb_strlen($seoDescription),
            shortDescriptionLength: mb_strlen($shortDescription),
            fullDescriptionLength: mb_strlen($fullDescription),
        );
    }

    /**
     * @param  array<int, string>  $missingEnglishFieldLabels
     * @return array<int, string>
     */
    private function nextSteps(
        bool $shortDescriptionPresent,
        bool $fullDescriptionPresent,
        bool $seoTitlePresent,
        bool $seoDescriptionPresent,
        bool $weakDescription,
        array $missingEnglishFieldLabels,
    ): array {
        $steps = collect();

        if (! $shortDescriptionPresent && ! $fullDescriptionPresent) {
            $steps->push('Допълнете краткото и пълното описание');
        } elseif (! $shortDescriptionPresent) {
            $steps->push('Попълнете краткото описание');
        } elseif (! $fullDescriptionPresent) {
            $steps->push('Попълнете пълното описание');
        } elseif ($weakDescription) {
            $steps->push('Допълнете краткото и пълното описание');
        }

        if (! $seoTitlePresent && ! $seoDescriptionPresent) {
            $steps->push('Попълнете SEO заглавие и описание');
        } elseif (! $seoTitlePresent) {
            $steps->push('Попълнете SEO заглавие');
        } elseif (! $seoDescriptionPresent) {
            $steps->push('Попълнете SEO описание');
        }

        if ($missingEnglishFieldLabels !== []) {
            $steps->push('Добавете английска локализация');
            $steps->push(...array_map(
                fn (string $label): string => str_replace('Липсва', 'Добавете', $label),
                $missingEnglishFieldLabels,
            ));
        }

        return $steps->unique()->values()->all();
    }

    /**
     * @return array{code: string, label: string, level: string, color: string}
     */
    private function issue(string $code, string $label, string $level): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'level' => $level,
            'color' => match ($level) {
                'critical' => 'danger',
                'warning' => 'warning',
                'recommendation' => 'info',
                default => 'gray',
            },
        ];
    }

    private function plainText(string $value): string
    {
        return Str::squish($value);
    }

    private function richText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $withoutTags = strip_tags($decoded);

        return Str::squish(str_replace(["\u{00A0}", "\u{200B}"], ' ', $withoutTags));
    }

    private function whereDescriptionsPresent(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => $this->whereRichPresent($query, 'short_description'))
            ->where(fn (Builder $query) => $this->whereRichPresent($query, 'description'));
    }

    private function whereSeoPresent(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => $this->wherePlainPresent($query, 'meta_title'))
            ->where(fn (Builder $query) => $this->wherePlainPresent($query, 'meta_description'));
    }

    private function whereNotWeak(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $query): Builder => $this->scanner->applyIssueQuery(
            $query,
            ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION,
        ));
    }

    private function whereEnglishMissing(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach (self::EXPECTED_ENGLISH_FIELDS as $field => $definition) {
                $query->orWhere(function (Builder $query) use ($field, $definition): void {
                    $selector = $field.'_translations->en';

                    if ($definition['rich']) {
                        $this->whereRichMissing($query, $selector);
                    } else {
                        $this->wherePlainMissing($query, $selector);
                    }
                });
            }
        });
    }

    private function whereEnglishComplete(Builder $query): Builder
    {
        foreach (self::EXPECTED_ENGLISH_FIELDS as $field => $definition) {
            $query->where(function (Builder $query) use ($field, $definition): void {
                $selector = $field.'_translations->en';

                if ($definition['rich']) {
                    $this->whereRichPresent($query, $selector);
                } else {
                    $this->wherePlainPresent($query, $selector);
                }
            });
        }

        return $query;
    }

    private function wherePlainMissing(Builder $query, string $selector): Builder
    {
        return $query->whereRaw($this->plainSqlExpression($query, $selector)." = ''");
    }

    private function wherePlainPresent(Builder $query, string $selector): Builder
    {
        return $query->whereRaw($this->plainSqlExpression($query, $selector)." <> ''");
    }

    private function whereRichMissing(Builder $query, string $selector): Builder
    {
        return $query->whereRaw($this->richSqlExpression($query, $selector)." = ''");
    }

    private function whereRichPresent(Builder $query, string $selector): Builder
    {
        return $query->whereRaw($this->richSqlExpression($query, $selector)." <> ''");
    }

    private function plainSqlExpression(Builder $query, string $selector): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($selector);

        return "TRIM(COALESCE({$wrapped}, ''))";
    }

    private function richSqlExpression(Builder $query, string $selector): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($selector);
        $expression = "LOWER(COALESCE({$wrapped}, ''))";

        foreach (['&nbsp;', '&#160;', '<p>', '</p>', '<div>', '</div>', '<br>', '<br/>', '<br />'] as $token) {
            $expression = "REPLACE({$expression}, '{$token}', '')";
        }

        foreach ([9, 10, 13] as $character) {
            $expression = "REPLACE({$expression}, CHAR({$character}), '')";
        }

        foreach ([
            ['hex' => 'C2A0', 'codepoint' => 160],
            ['hex' => 'E2808B', 'codepoint' => 8203],
        ] as $character) {
            $unicodeCharacter = $this->unicodeCharacterSql(
                $query,
                $character['hex'],
                $character['codepoint'],
            );
            $expression = "REPLACE({$expression}, {$unicodeCharacter}, '')";
        }

        return "TRIM({$expression})";
    }

    private function unicodeCharacterSql(Builder $query, string $utf8Hex, int $codepoint): string
    {
        return match ($query->getQuery()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CONVERT(0x{$utf8Hex} USING utf8mb4)",
            default => "CHAR({$codepoint})",
        };
    }
}
