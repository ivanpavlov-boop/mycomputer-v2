<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class NormalizedSupplierRecord implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-normalized-record-v1';

    public function __construct(
        public string $supplierKey,
        public string $supplierSku,
        public ?string $ean = null,
        public ?string $mpn = null,
        public ?string $name = null,
        public ?string $brandName = null,
        public ?string $supplierCategoryName = null,
        public ?string $supplierPriceRaw = null,
        public ?string $normalizedPrice = null,
        public ?string $recommendedPrice = null,
        public ?string $currency = null,
        public ?int $quantity = null,
        public ?string $externalAvailabilityCode = null,
        public ?string $externalAvailabilityLabel = null,
        public ?string $normalizedAvailability = null,
        public array $sourceRecordIdentity = [],
        public array $sourceFingerprints = [],
        public string $profileVersion = 'unknown',
        public string $driverVersion = 'unknown',
        public array $rawSourceMetadata = [],
        public array $warnings = [],
        public array $validationIssues = [],
    ) {
        if (trim($supplierKey) === '' || trim($supplierSku) === '') {
            throw new InvalidArgumentException('Supplier key and supplier SKU are required.');
        }

        if ($quantity !== null && $quantity < 0) {
            throw new InvalidArgumentException('Supplier quantity cannot be negative.');
        }

        foreach (['supplierPriceRaw', 'normalizedPrice', 'recommendedPrice'] as $decimalField) {
            if ($this->{$decimalField} !== null) {
                DecimalNormalizer::canonical($this->{$decimalField});
            }
        }

        OnboardingValueGuard::assertSafe($sourceRecordIdentity, 'source record identity');
        OnboardingValueGuard::assertSafe($rawSourceMetadata, 'raw source metadata');
        OnboardingValueGuard::assertSafe($warnings, 'record warnings');
        OnboardingValueGuard::assertSafe($validationIssues, 'record validation issues');
    }

    /** @return array<string, mixed> */
    public function toCanonicalArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'supplier_key' => trim($this->supplierKey),
            'supplier_sku' => trim($this->supplierSku),
            'ean' => $this->ean,
            'mpn' => $this->mpn,
            'name' => $this->name,
            'brand_name' => $this->brandName,
            'supplier_category_name' => $this->supplierCategoryName,
            'supplier_price_raw' => $this->supplierPriceRaw === null ? null : DecimalNormalizer::canonical($this->supplierPriceRaw),
            'normalized_price' => $this->normalizedPrice === null ? null : DecimalNormalizer::canonical($this->normalizedPrice),
            'recommended_price' => $this->recommendedPrice === null ? null : DecimalNormalizer::canonical($this->recommendedPrice),
            'currency' => $this->currency === null ? null : strtoupper(trim($this->currency)),
            'quantity' => $this->quantity,
            'external_availability_code' => $this->externalAvailabilityCode,
            'external_availability_label' => $this->externalAvailabilityLabel,
            'normalized_availability' => $this->normalizedAvailability,
            'source_record_identity' => $this->sourceRecordIdentity,
            'source_fingerprints' => array_map(
                fn (mixed $fingerprint): mixed => $fingerprint instanceof SourceFingerprint ? $fingerprint->toArray() : $fingerprint,
                $this->sourceFingerprints
            ),
            'profile_version' => $this->profileVersion,
            'driver_version' => $this->driverVersion,
            'raw_source_metadata' => $this->rawSourceMetadata,
            'warnings' => $this->warnings,
            'validation_issues' => $this->validationIssues,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toCanonicalArray();
    }
}
