<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogProductDeletionPolicyInput;
use App\Services\Suppliers\Onboarding\CatalogProductDeletionPolicy;
use PHPUnit\Framework\TestCase;

final class CatalogProductDeletionPolicyTest extends TestCase
{
    public function test_published_ordered_or_supplier_history_products_are_never_deleted(): void
    {
        foreach ([
            ['hasEverBeenPublished' => true],
            ['hasOrderHistory' => true],
            ['hasSupplierHistory' => true],
        ] as $attributes) {
            $result = $this->preview(...$attributes);

            $this->assertFalse($result['delete_allowed']);
            $this->assertFalse($result['automatic_hard_delete_allowed']);
            $this->assertFalse($result['automatic_soft_delete_allowed']);
            $this->assertTrue($result['requires_super_admin_review']);
        }
    }

    public function test_test_product_without_dependencies_is_only_a_manual_review_candidate(): void
    {
        $result = $this->preview(
            isDemonstrablyTestDuplicateOrErroneous: true,
            seoRedirectReviewed: true,
            previewAndBackupExist: true,
        );

        $this->assertSame('future_manual_review_candidate', $result['manual_review_classification']);
        $this->assertFalse($result['delete_allowed']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(0, $result['records_changed']);
    }

    /** @return array<string, mixed> */
    private function preview(
        bool $hasEverBeenPublished = false,
        bool $hasOrderHistory = false,
        bool $hasSupplierHistory = false,
        bool $isDemonstrablyTestDuplicateOrErroneous = false,
        bool $seoRedirectReviewed = false,
        bool $previewAndBackupExist = false,
    ): array {
        return (new CatalogProductDeletionPolicy)->preview(new CatalogProductDeletionPolicyInput(
            productReferenceHash: 'synthetic-product', hasEverBeenPublished: $hasEverBeenPublished,
            hasOrderHistory: $hasOrderHistory, hasSupplierHistory: $hasSupplierHistory, hasActiveSupplierOffer: false,
            hasRequiredRelationalDependency: false, isDemonstrablyTestDuplicateOrErroneous: $isDemonstrablyTestDuplicateOrErroneous,
            hasExplicitDuplicateConsolidationPlan: false, seoRedirectReviewed: $seoRedirectReviewed,
            previewAndBackupExist: $previewAndBackupExist,
        ))->toArray();
    }
}
