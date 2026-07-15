<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierPreviewFeedProfileDesignReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-preview-feed-profile-design-v1';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public bool $success,
        public string $verdict,
        private array $payload,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier preview feed profile design report');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'preview_feed_profile_design',
            'read_only' => true,
            'preview_only' => true,
            'success' => $this->success,
            'verdict' => $this->verdict,
            ...$this->payload,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
