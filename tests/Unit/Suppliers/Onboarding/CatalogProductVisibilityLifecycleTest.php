<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogProductVisibilityLifecycleInput;
use App\Services\Suppliers\Onboarding\CatalogProductVisibilityLifecyclePolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class CatalogProductVisibilityLifecycleTest extends TestCase
{
    public function test_day_zero_and_day_59_remain_unavailable_but_indexable(): void
    {
        $at = CarbonImmutable::parse('2026-07-17 12:00:00', 'UTC');
        $dayZero = $this->preview($at, $at, false);
        $day59 = $this->preview($at->subDays(59), $at, false);

        foreach ([$dayZero, $day59] as $result) {
            $this->assertSame('unavailable_indexable', $result['visibility_state']);
            $this->assertFalse($result['purchase_allowed']);
            $this->assertSame(200, $result['direct_page_http_status']);
            $this->assertTrue($result['index_allowed']);
            $this->assertTrue($result['sitemap_allowed']);
        }
    }

    public function test_day_sixty_is_noindex_follow_and_excluded_from_sitemap(): void
    {
        $at = CarbonImmutable::parse('2026-07-17 12:00:00', 'UTC');
        $result = $this->preview($at->subDays(60), $at, false);

        $this->assertSame('archived_noindex', $result['visibility_state']);
        $this->assertSame('noindex, follow', $result['robots_directive']);
        $this->assertFalse($result['sitemap_allowed']);
        $this->assertSame(200, $result['direct_page_http_status']);
        $this->assertFalse($result['delete_allowed']);
    }

    public function test_month_24_is_cold_archive_candidate_and_reappearance_resets_the_preview(): void
    {
        $at = CarbonImmutable::parse('2026-07-17 12:00:00', 'UTC');
        $cold = $this->preview($at->subMonthsNoOverflow(24), $at, false);
        $reappeared = $this->preview($at->subMonthsNoOverflow(24), $at, true);

        $this->assertSame('cold_archive_candidate', $cold['visibility_state']);
        $this->assertTrue($cold['cold_archive_candidate']);
        $this->assertFalse($cold['delete_allowed']);
        $this->assertSame('active', $reappeared['visibility_state']);
        $this->assertNull($reappeared['zero_active_offers_since']);
        $this->assertTrue($reappeared['sitemap_allowed']);
    }

    /** @return array<string, mixed> */
    private function preview(CarbonImmutable $zeroSince, CarbonImmutable $at, bool $hasActiveOffer): array
    {
        return (new CatalogProductVisibilityLifecyclePolicy)->preview(new CatalogProductVisibilityLifecycleInput(
            productReferenceHash: 'synthetic-product', zeroActiveOffersSince: $zeroSince, evaluatedAt: $at,
            hasActiveCommercialOffer: $hasActiveOffer, canonicalPublicStatus: 'in_stock',
        ))->toArray();
    }
}
