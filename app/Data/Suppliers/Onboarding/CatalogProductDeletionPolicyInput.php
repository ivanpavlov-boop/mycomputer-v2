<?php

namespace App\Data\Suppliers\Onboarding;

final readonly class CatalogProductDeletionPolicyInput
{
    public function __construct(
        public string $productReferenceHash,
        public bool $hasEverBeenPublished,
        public bool $hasOrderHistory,
        public bool $hasSupplierHistory,
        public bool $hasActiveSupplierOffer,
        public bool $hasRequiredRelationalDependency,
        public bool $isDemonstrablyTestDuplicateOrErroneous,
        public bool $hasExplicitDuplicateConsolidationPlan,
        public bool $seoRedirectReviewed,
        public bool $previewAndBackupExist,
    ) {}
}
