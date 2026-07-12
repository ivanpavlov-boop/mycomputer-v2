<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SupplierFeedSource implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-feed-source-v1';

    public function __construct(
        public string $sourceType,
        public string $logicalSourceName,
        public string $localPathOrStream,
        public ?string $expectedMediaType = null,
        public ?SourceFingerprint $expectedFingerprint = null,
        public ?string $sourceVersion = null,
        public ?string $partName = null,
        public array $safeMetadata = [],
    ) {
        if (trim($sourceType) === '' || trim($logicalSourceName) === '' || trim($localPathOrStream) === '') {
            throw new InvalidArgumentException('Source type, logical name, and local source are required.');
        }

        if (preg_match('/^(?:https?|ftp):\/\//i', $localPathOrStream) === 1) {
            throw new InvalidArgumentException('Remote feed sources are outside the onboarding contract boundary.');
        }

        OnboardingValueGuard::assertSafe($safeMetadata, 'source metadata');
    }

    public static function localFile(
        string $logicalSourceName,
        string $path,
        ?string $expectedMediaType = null,
        ?SourceFingerprint $expectedFingerprint = null,
        ?string $sourceVersion = null,
        ?string $partName = null,
        array $safeMetadata = [],
    ): self {
        return new self('local_file', $logicalSourceName, $path, $expectedMediaType, $expectedFingerprint, $sourceVersion, $partName, $safeMetadata);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'logical_source_name' => $this->logicalSourceName,
            'expected_media_type' => $this->expectedMediaType,
            'expected_fingerprint' => $this->expectedFingerprint?->toArray(),
            'source_version' => $this->sourceVersion,
            'part_name' => $this->partName,
            'safe_metadata' => CanonicalOnboardingData::normalize($this->safeMetadata),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
