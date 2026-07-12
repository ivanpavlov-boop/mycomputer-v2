<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SourceFingerprint implements JsonSerializable
{
    public const REQUIRED_ALGORITHM = 'sha256';

    public readonly string $algorithm;

    public readonly string $digest;

    public readonly string $logicalSourceName;

    public readonly ?string $sourcePart;

    public readonly ?string $sourceVersion;

    public function __construct(
        string $algorithm,
        string $digest,
        string $logicalSourceName,
        ?string $sourcePart = null,
        ?string $sourceVersion = null,
    ) {
        if (strtolower($algorithm) !== self::REQUIRED_ALGORITHM) {
            throw new InvalidArgumentException('Only SHA-256 source fingerprints are supported.');
        }

        $digest = strtolower($digest);

        if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            throw new InvalidArgumentException('Source fingerprint must be a SHA-256 hexadecimal digest.');
        }

        if (trim($logicalSourceName) === '') {
            throw new InvalidArgumentException('Logical source name is required.');
        }

        $this->algorithm = self::REQUIRED_ALGORITHM;
        $this->digest = $digest;
        $this->logicalSourceName = trim($logicalSourceName);
        $this->sourcePart = $sourcePart;
        $this->sourceVersion = $sourceVersion;
    }

    public static function sha256(
        string $digest,
        string $logicalSourceName,
        ?string $sourcePart = null,
        ?string $sourceVersion = null,
    ): self {
        return new self(self::REQUIRED_ALGORITHM, $digest, $logicalSourceName, $sourcePart, $sourceVersion);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm,
            'digest' => $this->digest,
            'logical_source_name' => $this->logicalSourceName,
            'source_part' => $this->sourcePart,
            'source_version' => $this->sourceVersion,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
