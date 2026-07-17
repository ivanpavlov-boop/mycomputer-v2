<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogProductVisibilityLifecycleInput;
use App\Data\Suppliers\Onboarding\CatalogProductVisibilityPreviewResult;

final class CatalogProductVisibilityLifecyclePolicy
{
    public const POLICY_KEY = 'catalog-product-visibility-lifecycle-policy-v1';

    public const NOINDEX_AFTER_DAYS = 60;

    public const COLD_ARCHIVE_AFTER_MONTHS = 24;

    public function preview(CatalogProductVisibilityLifecycleInput $input): CatalogProductVisibilityPreviewResult
    {
        if ($input->hasActiveCommercialOffer) {
            return new CatalogProductVisibilityPreviewResult(
                productReferenceHash: $input->productReferenceHash,
                zeroActiveOffersSince: null,
                elapsedWithoutActiveOfferDays: 0,
                visibilityState: 'active',
                purchaseAllowed: $input->canonicalPublicStatus !== 'unavailable',
                categoryListingAllowed: true,
                internalSearchAllowed: true,
                directPageHttpStatus: 200,
                indexAllowed: true,
                sitemapAllowed: true,
                robotsDirective: 'index, follow',
                coldArchiveCandidate: false,
                deleteAllowed: false,
            );
        }

        $zeroActiveOffersSince = $input->zeroActiveOffersSince ?? $input->evaluatedAt;
        $elapsedDays = max(0, intdiv((int) $zeroActiveOffersSince->diffInSeconds($input->evaluatedAt, false), 86400));
        $coldArchiveCandidate = $input->evaluatedAt->greaterThanOrEqualTo($zeroActiveOffersSince->addMonthsNoOverflow(self::COLD_ARCHIVE_AFTER_MONTHS));
        $archivedNoindex = $elapsedDays >= self::NOINDEX_AFTER_DAYS;

        return new CatalogProductVisibilityPreviewResult(
            productReferenceHash: $input->productReferenceHash,
            zeroActiveOffersSince: $zeroActiveOffersSince,
            elapsedWithoutActiveOfferDays: $elapsedDays,
            visibilityState: $coldArchiveCandidate ? 'cold_archive_candidate' : ($archivedNoindex ? 'archived_noindex' : 'unavailable_indexable'),
            purchaseAllowed: false,
            categoryListingAllowed: false,
            internalSearchAllowed: false,
            directPageHttpStatus: 200,
            indexAllowed: ! $archivedNoindex,
            sitemapAllowed: ! $archivedNoindex,
            robotsDirective: $archivedNoindex ? 'noindex, follow' : 'index, follow',
            coldArchiveCandidate: $coldArchiveCandidate,
            deleteAllowed: false,
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'cold_archive_candidate_after_complete_months' => self::COLD_ARCHIVE_AFTER_MONTHS,
            'direct_page_http_status' => 200,
            'noindex_after_complete_days' => self::NOINDEX_AFTER_DAYS,
            'noindex_robots_directive' => 'noindex, follow',
            'policy_key' => self::POLICY_KEY,
            'product_delete_allowed' => false,
            'runtime_storefront_change_allowed' => false,
            'sitemap_change_allowed' => false,
            'write_allowed' => false,
        ];
    }
}
