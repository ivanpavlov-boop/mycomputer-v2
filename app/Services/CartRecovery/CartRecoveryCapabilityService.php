<?php

namespace App\Services\CartRecovery;

use App\Exceptions\CartRecoveryInvalidException;
use App\Models\AbandonedCartRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class CartRecoveryCapabilityService
{
    public const CAPABILITY_BYTES = 32;

    public const CAPABILITY_LENGTH = 43;

    public const HASH_LENGTH = 64;

    private const CAPABILITY_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    public function issue(AbandonedCartRecord $record): IssuedCartRecoveryCapability
    {
        return DB::transaction(function () use ($record): IssuedCartRecoveryCapability {
            $locked = AbandonedCartRecord::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $value = $this->generate();
            $hash = $this->hash($value);
            $expiresAt = $this->expiryFor($locked);

            $locked->forceFill([
                'recovery_capability_hash' => $hash,
                'recovery_capability_expires_at' => $expiresAt,
            ])->save();

            return new IssuedCartRecoveryCapability(
                $value,
                $hash,
                $this->fragmentUrl($value),
                $expiresAt,
            );
        }, 3);
    }

    public function validatedHash(#[SensitiveParameter] mixed $value): string
    {
        if (! $this->isValid($value)) {
            throw new CartRecoveryInvalidException;
        }

        return $this->hash($value);
    }

    public function resolveHashForUpdate(#[SensitiveParameter] string $hash): AbandonedCartRecord
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new CartRecoveryInvalidException;
        }

        $record = AbandonedCartRecord::query()
            ->where('recovery_capability_hash', $hash)
            ->lockForUpdate()
            ->first();

        if (
            $record === null
            || ! is_string($record->recovery_capability_hash)
            || ! hash_equals($record->recovery_capability_hash, $hash)
        ) {
            throw new CartRecoveryInvalidException;
        }

        return $record;
    }

    public function revoke(AbandonedCartRecord $record): void
    {
        $record->forceFill([
            'recovery_capability_hash' => null,
            'recovery_capability_expires_at' => null,
        ])->save();
    }

    public function revokeIssued(int $recordId, IssuedCartRecoveryCapability $issued): void
    {
        AbandonedCartRecord::query()
            ->whereKey($recordId)
            ->where('recovery_capability_hash', $issued->hash())
            ->update([
                'recovery_capability_hash' => null,
                'recovery_capability_expires_at' => null,
            ]);
    }

    public function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::CAPABILITY_PATTERN, $value) === 1;
    }

    public function hash(#[SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }

    private function generate(): string
    {
        return rtrim(
            strtr(base64_encode(random_bytes(self::CAPABILITY_BYTES)), '+/', '-_'),
            '=',
        );
    }

    private function fragmentUrl(#[SensitiveParameter] string $value): string
    {
        return rtrim(
            (string) config('email-marketing.abandoned_cart.frontend_recovery_url'),
            '#',
        ).'#'.$value;
    }

    private function expiryFor(AbandonedCartRecord $record): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $capabilityExpiry = $now->addDays(
            max(1, (int) config('email-marketing.abandoned_cart.recovery_capability_days', 14)),
        );
        $lifecycleStart = $record->last_cart_activity_at
            ?? $record->created_at
            ?? $now;
        $lifecycleExpiry = CarbonImmutable::instance($lifecycleStart)
            ->addDays(max(1, (int) config('email-marketing.abandoned_cart.expire_after_days', 14)));

        return $capabilityExpiry->lessThan($lifecycleExpiry)
            ? $capabilityExpiry
            : $lifecycleExpiry;
    }
}
