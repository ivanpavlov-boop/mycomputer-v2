<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;
use JsonSerializable;

final readonly class CatalogProductVisibilityPreviewResult implements JsonSerializable
{
    public function __construct(
        public string $productReferenceHash,
        public ?CarbonImmutable $zeroActiveOffersSince,
        public int $elapsedWithoutActiveOfferDays,
        public string $visibilityState,
        public bool $purchaseAllowed,
        public bool $categoryListingAllowed,
        public bool $internalSearchAllowed,
        public int $directPageHttpStatus,
        public bool $indexAllowed,
        public bool $sitemapAllowed,
        public string $robotsDirective,
        public bool $coldArchiveCandidate,
        public bool $deleteAllowed,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'catalog product visibility preview result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'category_listing_allowed' => $this->categoryListingAllowed,
            'cold_archive_candidate' => $this->coldArchiveCandidate,
            'delete_allowed' => $this->deleteAllowed,
            'direct_page_http_status' => $this->directPageHttpStatus,
            'elapsed_without_active_offer_days' => $this->elapsedWithoutActiveOfferDays,
            'index_allowed' => $this->indexAllowed,
            'internal_search_allowed' => $this->internalSearchAllowed,
            'product_reference_hash' => $this->productReferenceHash,
            'purchase_allowed' => $this->purchaseAllowed,
            'records_changed' => 0,
            'robots_directive' => $this->robotsDirective,
            'sitemap_allowed' => $this->sitemapAllowed,
            'visibility_state' => $this->visibilityState,
            'write_allowed' => false,
            'zero_active_offers_since' => $this->zeroActiveOffersSince?->toAtomString(),
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
