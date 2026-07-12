<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SupplierFeedProfile implements JsonSerializable
{
    public const CONTRACT_VERSION = 'supplier-feed-profile-v1';

    public function __construct(
        public string $supplierKey,
        public string $profileKey,
        public string $profileVersion,
        public string $driverKey,
        public string $sourceFormat,
        public string $recordSelector,
        public array $fieldMappings = [],
        public array $identifierRules = [],
        public array $priceRules = [],
        public array $currencyRules = [],
        public array $quantityRules = [],
        public array $availabilityRules = [],
        public array $categoryRules = [],
        public array $brandRules = [],
        public array $eanRules = [],
        public array $mpnRules = [],
        public array $requiredFields = [],
        public array $optionalFields = [],
        public array $sourceParts = [],
        public array $metadata = [],
    ) {
        foreach ([
            'supplier key' => $supplierKey,
            'profile key' => $profileKey,
            'profile version' => $profileVersion,
            'driver key' => $driverKey,
            'source format' => $sourceFormat,
            'record selector' => $recordSelector,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$label} is required.");
            }
        }

        OnboardingValueGuard::assertSafe($this->safeArrays(), 'feed profile');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'supplier_key' => $this->supplierKey,
            'profile_key' => $this->profileKey,
            'profile_version' => $this->profileVersion,
            'driver_key' => $this->driverKey,
            'source_format' => $this->sourceFormat,
            'record_selector' => $this->recordSelector,
            'field_mappings' => $this->fieldMappings,
            'identifier_rules' => $this->identifierRules,
            'price_rules' => $this->priceRules,
            'currency_rules' => $this->currencyRules,
            'quantity_rules' => $this->quantityRules,
            'availability_rules' => $this->availabilityRules,
            'category_rules' => $this->categoryRules,
            'brand_rules' => $this->brandRules,
            'ean_rules' => $this->eanRules,
            'mpn_rules' => $this->mpnRules,
            'required_fields' => $this->requiredFields,
            'optional_fields' => $this->optionalFields,
            'source_parts' => array_map(
                fn (mixed $part): mixed => $part instanceof SupplierFeedSource ? $part->toArray() : $part,
                $this->sourceParts
            ),
            'metadata' => $this->metadata,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    private function safeArrays(): array
    {
        return [
            'field_mappings' => $this->fieldMappings,
            'identifier_rules' => $this->identifierRules,
            'price_rules' => $this->priceRules,
            'currency_rules' => $this->currencyRules,
            'quantity_rules' => $this->quantityRules,
            'availability_rules' => $this->availabilityRules,
            'category_rules' => $this->categoryRules,
            'brand_rules' => $this->brandRules,
            'ean_rules' => $this->eanRules,
            'mpn_rules' => $this->mpnRules,
            'required_fields' => $this->requiredFields,
            'optional_fields' => $this->optionalFields,
            'source_parts' => array_map(
                fn (mixed $part): mixed => $part instanceof SupplierFeedSource ? $part->toArray() : $part,
                $this->sourceParts
            ),
            'metadata' => $this->metadata,
        ];
    }
}
