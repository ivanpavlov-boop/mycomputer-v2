<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SupplierSnapshotQualificationInput
{
    public function __construct(
        public string $supplierKey,
        public string $snapshotId,
        public string $snapshotStatus,
        public CarbonImmutable $observedAt,
        public bool $isSuccessful,
        public bool $isFullSnapshot,
        public bool $isSchemaValid,
        public bool $isTruncated,
        public int $productCount,
        public int $minimumProductCount,
        public string $productDropPercent,
        public string $maximumProductDropPercent,
        public bool $hasFatalBlocker,
        public bool $supplierIdentityConfirmed,
        public ?string $snapshotFingerprint,
        public bool $isDuplicateFingerprint,
    ) {
        if ($this->productCount < 0 || $this->minimumProductCount < 0) {
            throw new InvalidArgumentException('Snapshot product counts cannot be negative.');
        }
        if (DecimalNormalizer::compare($this->productDropPercent, '0') < 0
            || DecimalNormalizer::compare($this->maximumProductDropPercent, '0') < 0) {
            throw new InvalidArgumentException('Snapshot product drop percentages cannot be negative.');
        }
    }
}
