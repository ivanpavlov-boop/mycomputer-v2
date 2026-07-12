<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class PriceNormalizationResult implements JsonSerializable
{
    public function __construct(
        public bool $valid,
        public mixed $rawSupplierPrice,
        public ?string $normalizedPrice,
        public string $sourceCurrency,
        public string $normalizedCurrency,
        public string $roundingPolicy,
        public string $taxInterpretation,
        public array $warnings = [],
        public array $errors = [],
        public bool $overflowDetected = false,
        public bool $negativeDetected = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'valid' => $this->valid,
            'raw_supplier_price' => $this->rawSupplierPrice,
            'normalized_price' => $this->normalizedPrice,
            'source_currency' => $this->sourceCurrency,
            'normalized_currency' => $this->normalizedCurrency,
            'rounding_policy' => $this->roundingPolicy,
            'tax_interpretation' => $this->taxInterpretation,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'overflow_detected' => $this->overflowDetected,
            'negative_detected' => $this->negativeDetected,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
