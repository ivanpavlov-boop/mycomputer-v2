<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ValidationIssue implements JsonSerializable
{
    public function __construct(
        public string $code,
        public ValidationSeverity $severity,
        public ?string $field = null,
        public ?string $messageKey = null,
        public array $safeContext = [],
        public ?string $sourceRecordHash = null,
        public bool $blocking = false,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Validation issue code is required.');
        }

        if ($sourceRecordHash !== null && preg_match('/^[a-f0-9]{64}$/', strtolower($sourceRecordHash)) !== 1) {
            throw new InvalidArgumentException('Source record hash must be a SHA-256 hexadecimal digest.');
        }

        OnboardingValueGuard::assertSafe($safeContext, 'validation issue context');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'code' => $this->code,
            'severity' => $this->severity->value,
            'field' => $this->field,
            'message_key' => $this->messageKey,
            'safe_context' => $this->safeContext,
            'source_record_hash' => $this->sourceRecordHash === null ? null : strtolower($this->sourceRecordHash),
            'blocking' => $this->blocking,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
