<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierFeedProfileDraft implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-feed-profile-draft-v1';

    /**
     * @param  array<string, float>  $confidenceScores
     * @param  array<int, string>  $proposedImagePaths
     * @param  array<int, string>  $unresolvedFields
     * @param  array<int, string>  $profileBlockers
     * @param  array<int, string>  $profileWarnings
     */
    public function __construct(
        public string $supplierKey,
        public string $sourceFormat,
        public string $sourceSha256,
        public ?string $recordPath,
        public ?string $proposedSkuPath,
        public ?string $proposedEanPath,
        public ?string $proposedMpnPath,
        public ?string $proposedNamePath,
        public ?string $proposedBrandPath,
        public ?string $proposedCategoryPath,
        public ?string $proposedPricePath,
        public ?string $proposedCurrencyPath,
        public ?string $proposedQuantityPath,
        public ?string $proposedAvailabilityPath,
        array $proposedImagePaths,
        array $confidenceScores,
        array $unresolvedFields,
        array $profileBlockers,
        array $profileWarnings,
        public bool $requiresHumanReview = true,
    ) {
        $this->proposedImagePaths = array_values(array_filter($proposedImagePaths, 'is_string'));
        $this->confidenceScores = CanonicalOnboardingData::normalize($confidenceScores);
        $this->unresolvedFields = array_values(array_unique(array_filter($unresolvedFields, 'is_string')));
        $this->profileBlockers = array_values(array_unique(array_filter($profileBlockers, 'is_string')));
        $this->profileWarnings = array_values(array_unique(array_filter($profileWarnings, 'is_string')));

        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier feed profile draft');
    }

    /** @var array<int, string> */
    public array $proposedImagePaths;

    /** @var array<string, float> */
    public array $confidenceScores;

    /** @var array<int, string> */
    public array $unresolvedFields;

    /** @var array<int, string> */
    public array $profileBlockers;

    /** @var array<int, string> */
    public array $profileWarnings;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'supplier_key' => $this->supplierKey,
            'source_format' => $this->sourceFormat,
            'source_sha256' => $this->sourceSha256,
            'record_path' => $this->recordPath,
            'proposed_sku_path' => $this->proposedSkuPath,
            'proposed_ean_path' => $this->proposedEanPath,
            'proposed_mpn_path' => $this->proposedMpnPath,
            'proposed_name_path' => $this->proposedNamePath,
            'proposed_brand_path' => $this->proposedBrandPath,
            'proposed_category_path' => $this->proposedCategoryPath,
            'proposed_price_path' => $this->proposedPricePath,
            'proposed_currency_path' => $this->proposedCurrencyPath,
            'proposed_quantity_path' => $this->proposedQuantityPath,
            'proposed_availability_path' => $this->proposedAvailabilityPath,
            'proposed_image_paths' => $this->proposedImagePaths,
            'confidence_scores' => $this->confidenceScores,
            'unresolved_fields' => $this->unresolvedFields,
            'profile_blockers' => $this->profileBlockers,
            'profile_warnings' => $this->profileWarnings,
            'requires_human_review' => $this->requiresHumanReview,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
