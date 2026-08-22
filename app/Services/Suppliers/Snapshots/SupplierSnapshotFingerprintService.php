<?php

namespace App\Services\Suppliers\Snapshots;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use App\Data\Suppliers\Snapshots\CanonicalSupplierDispatchAlert;
use App\Data\Suppliers\Snapshots\CanonicalSupplierImportDispatchPayload;
use App\Data\Suppliers\Snapshots\CanonicalSupplierRecoveryExpectedStateV2;
use App\Data\Suppliers\Snapshots\CanonicalSupplierRecoveryResult;
use App\Data\Suppliers\Snapshots\CanonicalSupplierRecoveryResumeState;
use App\Data\Suppliers\Snapshots\CanonicalSupplierSnapshotEnrollment;
use App\Data\Suppliers\Snapshots\CanonicalSupplierSnapshotGenerationHeader;
use App\Data\Suppliers\Snapshots\CanonicalSupplierSnapshotObservation;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferIdentityHasher;
use InvalidArgumentException;

final class SupplierSnapshotFingerprintService
{
    public const COHORT_AUTHORIZATION_VERSION = 'supplier_offer_cohort_v1';

    public const IDENTITIES = [
        'logical_execution_key',
        'active_attempt_token_hash',
        'source_fingerprint',
        'cohort_seed_fingerprint',
        'supplier_sku_hash',
        'dispatch_payload_hash',
        'lease_token_hash',
        'publication_attempt_token_hash',
        'expected_state_fingerprint',
        'authorization_nonce_hash',
        'resume_state_fingerprint',
        'result_fingerprint',
        'monitor_owner_token_hash',
        'alert_identity',
        'delivery_owner_token_hash',
        'snapshot_id',
        'cohort_fingerprint',
        'observation_set_fingerprint',
        'generation_fingerprint',
        'enrollment_fingerprint',
        'observation_fingerprint',
        'reliable_manufacturer_mpn_hash',
    ];

    public function logicalExecutionKey(string $randomBytes): string
    {
        if (strlen($randomBytes) !== 32) {
            throw new InvalidArgumentException('invalid_logical_execution_key_bytes');
        }

        return bin2hex($randomBytes);
    }

    public function activeAttemptTokenHash(string $tokenBytes): string
    {
        return $this->tokenHash($tokenBytes, 'active_attempt_token');
    }

    public function sourceFingerprint(string $sourceBytes): string
    {
        return CanonicalSupplierContract::rawDigest($sourceBytes);
    }

    /** @param list<string> $memberHashes */
    public function cohortSeedCanonicalBytes(string $authorizationVersion, array $memberHashes): string
    {
        if ($authorizationVersion !== self::COHORT_AUTHORIZATION_VERSION) {
            throw new InvalidArgumentException('invalid_cohort_authorization_version');
        }

        return CanonicalSupplierContract::encodeSorted([
            'cohort_authorization_version' => $authorizationVersion,
            'member_hashes' => CanonicalSupplierContract::sortedUniqueHashes(
                $memberHashes,
                'member_hashes',
            ),
        ]);
    }

    /** @param list<string> $memberHashes */
    public function cohortSeedFingerprint(string $authorizationVersion, array $memberHashes): string
    {
        return $this->sample(
            'snapshot_cohort_authorization_v1',
            $this->cohortSeedCanonicalBytes($authorizationVersion, $memberHashes),
        );
    }

    public function supplierSkuHash(string $supplierKey, string $supplierSku): string
    {
        CanonicalSupplierContract::nonEmptyString($supplierKey, 'supplier_key');
        CanonicalSupplierContract::nonEmptyString($supplierSku, 'supplier_sku');

        return $this->hasher()->supplierSku($supplierKey, $supplierSku);
    }

    public function dispatchPayloadHash(CanonicalSupplierImportDispatchPayload $payload): string
    {
        return $payload->fingerprint();
    }

    public function leaseTokenHash(string $tokenBytes): string
    {
        return $this->tokenHash($tokenBytes, 'lease_token');
    }

    public function publicationAttemptTokenHash(string $tokenBytes): string
    {
        return $this->tokenHash($tokenBytes, 'publication_attempt_token');
    }

    public function expectedStateFingerprint(CanonicalSupplierRecoveryExpectedStateV2 $state): string
    {
        return $state->fingerprint();
    }

    public function authorizationNonceHash(string $nonceBytes): string
    {
        if (strlen($nonceBytes) !== 32) {
            throw new InvalidArgumentException('invalid_authorization_nonce_bytes');
        }

        return CanonicalSupplierContract::digest(
            'supplier-import-dispatch-recovery-nonce-v1',
            $nonceBytes,
        );
    }

    public function resumeStateFingerprint(CanonicalSupplierRecoveryResumeState $state): string
    {
        return $state->fingerprint();
    }

    public function resultFingerprint(CanonicalSupplierRecoveryResult $result): string
    {
        return $result->fingerprint();
    }

    public function monitorOwnerTokenHash(string $tokenBytes): string
    {
        return $this->tokenHash($tokenBytes, 'monitor_owner_token');
    }

    public function alertIdentity(CanonicalSupplierDispatchAlert $alert): string
    {
        return $alert->fingerprint();
    }

    public function deliveryOwnerTokenHash(string $tokenBytes): string
    {
        return $this->tokenHash($tokenBytes, 'delivery_owner_token');
    }

    public function snapshotIdCanonicalBytes(string $supplierKey, int $importHistoryId): string
    {
        $supplierKey = $this->canonicalSupplierKey($supplierKey);
        CanonicalSupplierContract::positiveInteger($importHistoryId, 'import_history_id');

        return CanonicalSupplierContract::encodeSorted([
            'import_history_id' => $importHistoryId,
            'supplier_key' => $supplierKey,
        ]);
    }

    public function snapshotId(string $supplierKey, int $importHistoryId): string
    {
        return $this->sample(
            'snapshot_generation_v1',
            $this->snapshotIdCanonicalBytes($supplierKey, $importHistoryId),
        );
    }

    /** @param list<string> $enrollmentHashes */
    public function cohortCanonicalBytes(array $enrollmentHashes): string
    {
        return CanonicalSupplierContract::encodeSorted(
            CanonicalSupplierContract::sortedUniqueHashes($enrollmentHashes, 'enrollment_hashes'),
        );
    }

    /** @param list<string> $enrollmentHashes */
    public function cohortFingerprint(array $enrollmentHashes): string
    {
        return $this->sample('snapshot_cohort_v1', $this->cohortCanonicalBytes($enrollmentHashes));
    }

    /** @param list<string> $observationFingerprints */
    public function observationSetCanonicalBytes(array $observationFingerprints): string
    {
        return CanonicalSupplierContract::encodeSorted(
            CanonicalSupplierContract::sortedUniqueHashes(
                $observationFingerprints,
                'observation_fingerprints',
            ),
        );
    }

    /** @param list<string> $observationFingerprints */
    public function observationSetFingerprint(array $observationFingerprints): string
    {
        return $this->sample(
            'snapshot_observation_set_v1',
            $this->observationSetCanonicalBytes($observationFingerprints),
        );
    }

    public function generationFingerprint(CanonicalSupplierSnapshotGenerationHeader $header): string
    {
        return $this->sample('snapshot_generation_header_v1', $header->canonicalBytes());
    }

    public function enrollmentFingerprint(CanonicalSupplierSnapshotEnrollment $enrollment): string
    {
        return $this->sample('snapshot_enrollment_v1', $enrollment->canonicalBytes());
    }

    public function observationFingerprint(CanonicalSupplierSnapshotObservation $observation): string
    {
        return $this->sample('snapshot_observation_v1', $observation->canonicalBytes());
    }

    public function reliableManufacturerMpnHash(mixed $value): null
    {
        if ($value !== null) {
            throw new InvalidArgumentException('unapproved_reliable_manufacturer_mpn_hash');
        }

        return null;
    }

    private function tokenHash(string $tokenBytes, string $field): string
    {
        if ($tokenBytes === '') {
            throw new InvalidArgumentException('invalid_'.$field.'_bytes');
        }

        return CanonicalSupplierContract::rawDigest($tokenBytes);
    }

    private function canonicalSupplierKey(string $supplierKey): string
    {
        CanonicalSupplierContract::asciiString($supplierKey, 'supplier_key', 96);
        $canonical = strtolower(trim($supplierKey));

        if ($canonical === '') {
            throw new InvalidArgumentException('invalid_supplier_key');
        }

        return $canonical;
    }

    private function sample(string $bucket, string $canonicalBytes): string
    {
        return $this->hasher()->sample($bucket, $canonicalBytes);
    }

    private function hasher(): OperationalSupplierOfferIdentityHasher
    {
        return new OperationalSupplierOfferIdentityHasher;
    }
}
