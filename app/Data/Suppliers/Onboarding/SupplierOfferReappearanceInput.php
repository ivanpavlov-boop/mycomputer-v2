<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SupplierOfferReappearanceInput
{
    public const MAX_PRICE = '9999999999.99';

    public function __construct(
        public string $supplierKey,
        public string $supplierSkuHash,
        public string $previousPresenceStatus,
        public CarbonImmutable $evaluatedAt,
        public bool $supplierSkuMatchesExactly,
        public ?string $price,
        public bool $supplierMapperValid,
        public bool $hasIdentifierConflict,
        public bool $hasBlockingValidationIssue,
    ) {
        if ($this->price === null) {
            return;
        }

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $this->price) !== 1) {
            throw new InvalidArgumentException('invalid_offer_price');
        }

        $canonical = DecimalNormalizer::canonical($this->price);
        if ($canonical !== $this->price
            || DecimalNormalizer::compare($this->price, '0') < 0
            || DecimalNormalizer::compare($this->price, self::MAX_PRICE) > 0) {
            throw new InvalidArgumentException('invalid_offer_price');
        }
    }
}
