<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use App\Data\Suppliers\Onboarding\DecimalNormalizer;
use App\Data\Suppliers\Onboarding\OnboardingValueGuard;
use App\Data\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundle;
use App\Data\Suppliers\Onboarding\OperationalSupplierSourceIdentity;
use App\Data\Suppliers\Onboarding\OperationalSupplierSourceIdentityMap;
use App\Enums\Suppliers\Onboarding\CanonicalPublicAvailabilityStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

class OperationalSupplierOfferEvidenceBundleReader
{
    public const APCOM_FRESHNESS_POLICY_KEY = 'apcom-snapshot-freshness-policy-v1';

    public const APCOM_FRESHNESS_HOURS = 24;

    private const MAX_FILE_BYTES = 8_388_608;

    private const READ_CHUNK_BYTES = 65_536;

    private const MAX_JSON_DEPTH = 128;

    private const MAX_UNSIGNED_INTEGER = 4_294_967_295;

    private const MAX_FRESHNESS_HOURS = 8_760;

    private const MAX_PERCENT_SCALE = 6;

    private const MAX_PRICE_SCALE = 2;

    private const MAX_PERCENT = '100';

    private const MAX_PRICE = '9999999999.99';

    /** @var array<string, string> */
    private const REQUIRED_POLICY_VERSIONS = [
        'aggregation' => CatalogOfferAggregationPolicy::POLICY_KEY,
        'decision_register' => SupplierHumanDecisionRegistry::APCOM_REGISTER_V4,
        'deletion' => CatalogProductDeletionPolicy::POLICY_KEY,
        'missing_offer' => SupplierOfferLifecyclePolicy::POLICY_KEY,
        'preview_profile' => SupplierPreviewFeedProfileDesignRegistry::APCOM_PROFILE_V4,
        'reappearance' => SupplierOfferReappearancePolicy::POLICY_KEY,
        'retention' => SupplierTechnicalRetentionPolicy::POLICY_KEY,
        'snapshot_qualification' => SupplierSnapshotQualificationPolicy::POLICY_KEY,
        'visibility' => CatalogProductVisibilityLifecyclePolicy::POLICY_KEY,
    ];

    public function read(string $source, string $expectedSha256): OperationalSupplierOfferEvidenceBundle
    {
        $expectedSha256 = strtolower(trim($expectedSha256));
        if (! $this->isSha256($expectedSha256)) {
            throw new InvalidArgumentException('invalid_expected_sha256');
        }

        [$contents, $evidenceFingerprint] = $this->readVerifiedLocalFile(
            $this->validatedLocalPath($source),
            $expectedSha256,
        );

        $this->assertNoDuplicateJsonObjectKeys($contents);

        try {
            $decoded = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('malformed_evidence_json', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('invalid_evidence_root');
        }

        OnboardingValueGuard::assertSafe($decoded, 'operational evidence');
        $this->rejectRawIdentities($decoded);
        $this->assertExactKeys($decoded, [
            'freshness_policies', 'policy_versions', 'product_lifecycle_evidence', 'schema_version',
            'snapshots', 'source_identity', 'supplier', 'supplier_scope',
        ], 'evidence');

        if (($decoded['schema_version'] ?? null) !== OperationalSupplierOfferEvidenceBundle::SCHEMA_VERSION) {
            throw new InvalidArgumentException('unsupported_evidence_schema_version');
        }

        $supplier = $this->identifier($decoded['supplier'] ?? null, 'invalid_supplier_identity');
        if ($supplier !== 'apcom') {
            throw new InvalidArgumentException('supplier_scope_mismatch');
        }

        $supplierScope = $this->stringList($decoded['supplier_scope'] ?? null, 'invalid_supplier_scope');
        if (! in_array($supplier, $supplierScope, true)) {
            throw new InvalidArgumentException('supplier_scope_mismatch');
        }

        $policyVersions = $this->stringMap($decoded['policy_versions'] ?? null, 'invalid_policy_versions');
        if ($policyVersions !== self::REQUIRED_POLICY_VERSIONS) {
            throw new InvalidArgumentException('policy_version_mismatch');
        }

        $sourceIdentity = $this->sourceIdentity($decoded['source_identity'] ?? null);
        $freshnessPolicies = $this->freshnessPolicies($decoded['freshness_policies'] ?? null, $supplierScope);
        $snapshots = $this->snapshots($decoded['snapshots'] ?? null, $supplierScope, $supplier, $sourceIdentity);
        $productLifecycleEvidence = $this->productLifecycleEvidence($decoded['product_lifecycle_evidence'] ?? null);

        return new OperationalSupplierOfferEvidenceBundle(
            evidenceFingerprint: $evidenceFingerprint,
            supplierKey: $supplier,
            supplierScope: $supplierScope,
            policyVersions: $policyVersions,
            sourceIdentity: $sourceIdentity,
            freshnessPolicies: $freshnessPolicies,
            snapshots: $snapshots,
            productLifecycleEvidence: $productLifecycleEvidence,
        );
    }

    public function parseEvaluatedAt(string $value): CarbonImmutable
    {
        return $this->timestamp($value, 'invalid_evaluated_at');
    }

    private function validatedLocalPath(string $source): string
    {
        $source = trim($source);
        if ($source === '' || str_contains($source, "\0")) {
            throw new InvalidArgumentException('invalid_local_evidence_source');
        }
        if (str_starts_with($source, '\\\\') || str_starts_with($source, '//')) {
            throw new InvalidArgumentException('remote_evidence_source_rejected');
        }
        if (preg_match('/^(?:[a-z][a-z0-9+.-]*):(?:\/\/)?/i', $source) === 1 && preg_match('/^[a-zA-Z]:[\\\\\/]/', $source) !== 1) {
            throw new InvalidArgumentException('stream_wrapper_evidence_source_rejected');
        }
        if ($this->pathIsLink($source) || ! $this->pathIsFile($source)) {
            throw new InvalidArgumentException('invalid_local_evidence_source');
        }

        $realPath = $this->resolvePath($source);
        if ($realPath === false || ! $this->pathIsFile($realPath)) {
            throw new InvalidArgumentException('invalid_local_evidence_source');
        }

        return $realPath;
    }

    /** @return array{0: string, 1: string} */
    private function readVerifiedLocalFile(string $path, string $expectedSha256): array
    {
        $handle = $this->openReadHandle($path);
        if ($handle === false) {
            throw new InvalidArgumentException('invalid_local_evidence_source');
        }

        try {
            $initial = $this->handleStat($handle);
            $pathStat = $this->pathStat($path);
            if (! is_array($initial) || ! is_array($pathStat)
                || ! $this->isRegularFileStat($initial)
                || ! $this->isRegularFileStat($pathStat)
                || $this->pathIsLink($path)
                || ! $this->sameFileIdentity($initial, $pathStat)) {
                throw new InvalidArgumentException('invalid_local_evidence_source');
            }

            $initialSize = $initial['size'] ?? null;
            if (! is_int($initialSize) || $initialSize < 1 || $initialSize > self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException('invalid_evidence_file_size');
            }

            $hash = hash_init('sha256');
            $contents = '';
            $bytesRead = 0;

            while (! $this->endOfFile($handle)) {
                $chunk = $this->readChunk($handle, self::READ_CHUNK_BYTES);
                if ($chunk === false || ($chunk === '' && ! $this->endOfFile($handle))) {
                    throw new InvalidArgumentException('evidence_file_read_failed');
                }
                if ($chunk === '') {
                    break;
                }

                $bytesRead += strlen($chunk);
                if ($bytesRead > self::MAX_FILE_BYTES) {
                    throw new InvalidArgumentException('invalid_evidence_file_size');
                }

                hash_update($hash, $chunk);
                $contents .= $chunk;
            }

            $final = $this->handleStat($handle);
            if (! is_array($final)
                || ! $this->isRegularFileStat($final)
                || ! $this->sameFileIdentity($initial, $final)
                || $bytesRead !== $initialSize
                || ($final['size'] ?? null) !== $initialSize) {
                throw new InvalidArgumentException('evidence_file_changed_during_read');
            }

            $actualSha256 = hash_final($hash);
            if (! hash_equals($expectedSha256, $actualSha256)) {
                throw new InvalidArgumentException('evidence_fingerprint_mismatch');
            }

            return [$contents, $actualSha256];
        } finally {
            $this->closeReadHandle($handle);
        }
    }

    protected function pathIsLink(string $path): bool
    {
        return is_link($path);
    }

    protected function pathIsFile(string $path): bool
    {
        return is_file($path);
    }

    protected function resolvePath(string $path): string|false
    {
        return realpath($path);
    }

    protected function openReadHandle(string $path): mixed
    {
        return @fopen($path, 'rb');
    }

    /** @return array<string|int, mixed>|false */
    protected function handleStat(mixed $handle): array|false
    {
        return fstat($handle);
    }

    /** @return array<string|int, mixed>|false */
    protected function pathStat(string $path): array|false
    {
        return @lstat($path);
    }

    protected function endOfFile(mixed $handle): bool
    {
        return feof($handle);
    }

    protected function readChunk(mixed $handle, int $length): string|false
    {
        return fread($handle, $length);
    }

    protected function closeReadHandle(mixed $handle): void
    {
        fclose($handle);
    }

    /** @param array<string|int, mixed> $stat */
    private function isRegularFileStat(array $stat): bool
    {
        $mode = $stat['mode'] ?? null;

        return is_int($mode) && ($mode & 0170000) === 0100000;
    }

    /** @param array<string|int, mixed> $left @param array<string|int, mixed> $right */
    private function sameFileIdentity(array $left, array $right): bool
    {
        foreach (['dev', 'ino'] as $key) {
            if (isset($left[$key], $right[$key]) && (int) $left[$key] !== (int) $right[$key]) {
                return false;
            }
        }

        return true;
    }

    private function assertNoDuplicateJsonObjectKeys(string $json): void
    {
        $position = 0;
        $this->parseJsonValue($json, $position, 0);
        $this->skipJsonWhitespace($json, $position);

        if ($position !== strlen($json)) {
            throw new InvalidArgumentException('malformed_evidence_json');
        }
    }

    private function parseJsonValue(string $json, int &$position, int $depth): void
    {
        if ($depth > self::MAX_JSON_DEPTH) {
            throw new InvalidArgumentException('malformed_evidence_json');
        }

        $this->skipJsonWhitespace($json, $position);
        $character = $json[$position] ?? null;

        if ($character === '{') {
            $this->parseJsonObject($json, $position, $depth + 1);

            return;
        }
        if ($character === '[') {
            $this->parseJsonArray($json, $position, $depth + 1);

            return;
        }
        if ($character === '"') {
            $this->parseJsonString($json, $position);

            return;
        }
        if ($character === '-' || ($character !== null && $character >= '0' && $character <= '9')) {
            $this->parseJsonNumber($json, $position);

            return;
        }

        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $position, strlen($literal)) === $literal) {
                $position += strlen($literal);

                return;
            }
        }

        throw new InvalidArgumentException('malformed_evidence_json');
    }

    private function parseJsonObject(string $json, int &$position, int $depth): void
    {
        $position++;
        $this->skipJsonWhitespace($json, $position);
        if (($json[$position] ?? null) === '}') {
            $position++;

            return;
        }

        $keys = [];
        while (true) {
            $this->skipJsonWhitespace($json, $position);
            if (($json[$position] ?? null) !== '"') {
                throw new InvalidArgumentException('malformed_evidence_json');
            }

            $key = $this->parseJsonString($json, $position);
            $keyIdentity = 'key:'.base64_encode($key);
            if (isset($keys[$keyIdentity])) {
                throw new InvalidArgumentException('duplicate_json_object_key');
            }
            $keys[$keyIdentity] = true;

            $this->skipJsonWhitespace($json, $position);
            if (($json[$position] ?? null) !== ':') {
                throw new InvalidArgumentException('malformed_evidence_json');
            }
            $position++;
            $this->parseJsonValue($json, $position, $depth);
            $this->skipJsonWhitespace($json, $position);

            $separator = $json[$position] ?? null;
            if ($separator === '}') {
                $position++;

                return;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException('malformed_evidence_json');
            }
            $position++;
        }
    }

    private function parseJsonArray(string $json, int &$position, int $depth): void
    {
        $position++;
        $this->skipJsonWhitespace($json, $position);
        if (($json[$position] ?? null) === ']') {
            $position++;

            return;
        }

        while (true) {
            $this->parseJsonValue($json, $position, $depth);
            $this->skipJsonWhitespace($json, $position);

            $separator = $json[$position] ?? null;
            if ($separator === ']') {
                $position++;

                return;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException('malformed_evidence_json');
            }
            $position++;
        }
    }

    private function parseJsonString(string $json, int &$position): string
    {
        $start = $position;
        $length = strlen($json);
        $position++;

        while ($position < $length) {
            $character = $json[$position];
            if ($character === '"') {
                $position++;
                $token = substr($json, $start, $position - $start);

                try {
                    $decoded = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('malformed_evidence_json', previous: $exception);
                }

                if (! is_string($decoded)) {
                    throw new InvalidArgumentException('malformed_evidence_json');
                }

                return $decoded;
            }
            if (ord($character) < 0x20) {
                throw new InvalidArgumentException('malformed_evidence_json');
            }
            if ($character === '\\') {
                $position++;
                $escape = $json[$position] ?? null;
                if ($escape === null || ! in_array($escape, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)) {
                    throw new InvalidArgumentException('malformed_evidence_json');
                }
                if ($escape === 'u') {
                    $hex = substr($json, $position + 1, 4);
                    if (strlen($hex) !== 4 || ! ctype_xdigit($hex)) {
                        throw new InvalidArgumentException('malformed_evidence_json');
                    }
                    $position += 4;
                }
            }
            $position++;
        }

        throw new InvalidArgumentException('malformed_evidence_json');
    }

    private function parseJsonNumber(string $json, int &$position): void
    {
        $length = strlen($json);
        if (($json[$position] ?? null) === '-') {
            $position++;
        }

        $character = $json[$position] ?? null;
        if ($character === '0') {
            $position++;
        } elseif ($character !== null && $character >= '1' && $character <= '9') {
            do {
                $position++;
                $character = $json[$position] ?? null;
            } while ($character !== null && $character >= '0' && $character <= '9');
        } else {
            throw new InvalidArgumentException('malformed_evidence_json');
        }

        if (($json[$position] ?? null) === '.') {
            $position++;
            $start = $position;
            while ($position < $length && $json[$position] >= '0' && $json[$position] <= '9') {
                $position++;
            }
            if ($position === $start) {
                throw new InvalidArgumentException('malformed_evidence_json');
            }
        }

        if (in_array($json[$position] ?? null, ['e', 'E'], true)) {
            $position++;
            if (in_array($json[$position] ?? null, ['+', '-'], true)) {
                $position++;
            }
            $start = $position;
            while ($position < $length && $json[$position] >= '0' && $json[$position] <= '9') {
                $position++;
            }
            if ($position === $start) {
                throw new InvalidArgumentException('malformed_evidence_json');
            }
        }
    }

    private function skipJsonWhitespace(string $json, int &$position): void
    {
        $length = strlen($json);
        while ($position < $length && str_contains(" \t\r\n", $json[$position])) {
            $position++;
        }
    }

    /** @return array<string, array{policy_key: string, max_age_hours: int, approved: bool}> */
    private function freshnessPolicies(mixed $value, array $supplierScope): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('invalid_freshness_policies');
        }

        $policies = [];
        foreach ($value as $policy) {
            if (! is_array($policy) || array_is_list($policy)) {
                throw new InvalidArgumentException('invalid_freshness_policy');
            }
            $this->assertExactKeys($policy, ['approved', 'max_age_hours', 'policy_key', 'supplier'], 'freshness_policy');
            $supplier = $this->identifier($policy['supplier'] ?? null, 'invalid_freshness_policy_supplier');
            if (! in_array($supplier, $supplierScope, true) || isset($policies[$supplier])) {
                throw new InvalidArgumentException('invalid_freshness_policy_supplier');
            }
            $policyKey = $this->identifier($policy['policy_key'] ?? null, 'invalid_freshness_policy_key');
            $maxAgeHours = $this->integer(
                $policy['max_age_hours'] ?? null,
                'invalid_freshness_policy_hours',
                1,
                self::MAX_FRESHNESS_HOURS,
            );
            $approved = $this->boolean($policy['approved'] ?? null, 'invalid_freshness_policy_approval');
            if ($supplier === 'apcom' && ($policyKey !== self::APCOM_FRESHNESS_POLICY_KEY || $maxAgeHours !== self::APCOM_FRESHNESS_HOURS || ! $approved)) {
                throw new InvalidArgumentException('apcom_freshness_policy_mismatch');
            }
            $policies[$supplier] = [
                'approved' => $approved,
                'max_age_hours' => $maxAgeHours,
                'policy_key' => $policyKey,
            ];
        }
        ksort($policies);
        if (! isset($policies['apcom'])) {
            throw new InvalidArgumentException('apcom_freshness_policy_mismatch');
        }

        return $policies;
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshots(mixed $value, array $supplierScope, string $primarySupplier, string $primarySourceIdentity): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('missing_snapshot_evidence');
        }

        $snapshots = [];
        $snapshotIds = [];
        $previousGlobal = null;
        $previousBySupplier = [];
        $previousAuthoritativeBySupplier = [];
        $observationSets = [];
        $sourceIdentities = new OperationalSupplierSourceIdentityMap($primarySupplier, $primarySourceIdentity);

        foreach ($value as $snapshot) {
            if (! is_array($snapshot) || array_is_list($snapshot)) {
                throw new InvalidArgumentException('invalid_snapshot_evidence');
            }
            $this->assertExactKeys($snapshot, [
                'authoritative_snapshot_at', 'captured_at', 'comparable', 'fatal_integrity_blocker', 'fingerprint',
                'full', 'maximum_product_drop_percent', 'minimum_product_count', 'observations', 'product_count',
                'product_drop_percent', 'schema_valid', 'snapshot_id', 'source_identity', 'status', 'successful',
                'supplier', 'supplier_identity_confirmed', 'truncated',
            ], 'snapshot');

            $snapshotId = $this->identifier($snapshot['snapshot_id'] ?? null, 'invalid_snapshot_id');
            if (isset($snapshotIds[$snapshotId])) {
                throw new InvalidArgumentException('duplicate_snapshot_id');
            }
            $snapshotIds[$snapshotId] = true;
            $supplier = $this->identifier($snapshot['supplier'] ?? null, 'invalid_snapshot_supplier');
            if (! in_array($supplier, $supplierScope, true)) {
                throw new InvalidArgumentException('supplier_scope_mismatch');
            }
            $sourceIdentity = $this->sourceIdentity($snapshot['source_identity'] ?? null);
            $sourceIdentities->observe($supplier, $sourceIdentity);

            $capturedAt = $this->timestamp($snapshot['captured_at'] ?? null, 'invalid_snapshot_timestamp');
            $authoritativeAt = $this->timestamp($snapshot['authoritative_snapshot_at'] ?? null, 'invalid_authoritative_snapshot_timestamp');
            if ($authoritativeAt->greaterThan($capturedAt)) {
                throw new InvalidArgumentException('invalid_snapshot_chronology');
            }
            if ($previousGlobal !== null && $capturedAt->lessThan($previousGlobal)) {
                throw new InvalidArgumentException('unordered_snapshots');
            }
            if (isset($previousBySupplier[$supplier]) && ! $capturedAt->greaterThan($previousBySupplier[$supplier])) {
                throw new InvalidArgumentException('unordered_snapshots');
            }
            if (isset($previousAuthoritativeBySupplier[$supplier]) && ! $authoritativeAt->greaterThan($previousAuthoritativeBySupplier[$supplier])) {
                throw new InvalidArgumentException('invalid_snapshot_chronology');
            }
            $previousGlobal = $capturedAt;
            $previousBySupplier[$supplier] = $capturedAt;
            $previousAuthoritativeBySupplier[$supplier] = $authoritativeAt;

            $fingerprint = strtolower(trim((string) ($snapshot['fingerprint'] ?? '')));
            if (! $this->isSha256($fingerprint)) {
                throw new InvalidArgumentException('snapshot_fingerprint_missing');
            }

            $observations = $this->observations($snapshot['observations'] ?? null);
            $set = array_column($observations, 'supplier_sku_hash');
            sort($set, SORT_STRING);
            if (isset($observationSets[$supplier]) && $set !== $observationSets[$supplier]) {
                throw new InvalidArgumentException('incomplete_offer_presence_observations');
            }
            $observationSets[$supplier] = $set;

            $snapshots[] = CanonicalOnboardingData::normalize([
                'authoritative_snapshot_at' => $authoritativeAt->toAtomString(),
                'captured_at' => $capturedAt->toAtomString(),
                'comparable' => $this->boolean($snapshot['comparable'] ?? null, 'missing_qualification_evidence'),
                'fatal_integrity_blocker' => $this->boolean($snapshot['fatal_integrity_blocker'] ?? null, 'missing_qualification_evidence'),
                'fingerprint' => $fingerprint,
                'full' => $this->boolean($snapshot['full'] ?? null, 'missing_qualification_evidence'),
                'maximum_product_drop_percent' => (string) $this->integer(
                    $snapshot['maximum_product_drop_percent'] ?? null,
                    'missing_qualification_evidence',
                    0,
                    100,
                ),
                'minimum_product_count' => $this->integer(
                    $snapshot['minimum_product_count'] ?? null,
                    'missing_qualification_evidence',
                    0,
                    self::MAX_UNSIGNED_INTEGER,
                ),
                'observations' => $observations,
                'product_count' => $this->integer(
                    $snapshot['product_count'] ?? null,
                    'missing_qualification_evidence',
                    0,
                    self::MAX_UNSIGNED_INTEGER,
                ),
                'product_drop_percent' => $this->decimal(
                    $snapshot['product_drop_percent'] ?? null,
                    'missing_qualification_evidence',
                    self::MAX_PERCENT_SCALE,
                    self::MAX_PERCENT,
                ),
                'schema_valid' => $this->boolean($snapshot['schema_valid'] ?? null, 'missing_qualification_evidence'),
                'snapshot_id' => $snapshotId,
                'source_identity' => $sourceIdentity,
                'status' => $this->identifier($snapshot['status'] ?? null, 'missing_qualification_evidence'),
                'successful' => $this->boolean($snapshot['successful'] ?? null, 'missing_qualification_evidence'),
                'supplier' => $supplier,
                'supplier_identity_confirmed' => $this->boolean($snapshot['supplier_identity_confirmed'] ?? null, 'missing_qualification_evidence'),
                'truncated' => $this->boolean($snapshot['truncated'] ?? null, 'missing_qualification_evidence'),
            ]);
        }
        usort($snapshots, static fn (array $left, array $right): int => ($left['captured_at'] <=> $right['captured_at'])
            ?: ($left['supplier'] <=> $right['supplier'])
            ?: ($left['snapshot_id'] <=> $right['snapshot_id']));

        return $snapshots;
    }

    /** @return array<int, array<string, mixed>> */
    private function observations(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('missing_offer_presence_observations');
        }

        $observations = [];
        $seen = [];
        $allowedStatuses = array_map(static fn (CanonicalPublicAvailabilityStatus $status): string => $status->value, CanonicalPublicAvailabilityStatus::cases());

        foreach ($value as $observation) {
            if (! is_array($observation) || array_is_list($observation)) {
                throw new InvalidArgumentException('invalid_offer_presence_observation');
            }
            $this->assertExactKeys($observation, [
                'blocking_validation_issue', 'canonical_public_status', 'duplicate_offer', 'eol_flag',
                'exact_supplier_sku_match', 'identifier_conflict', 'present', 'price', 'raw_quantity_observed',
                'reliable_manufacturer_mpn_hash', 'supplier_mapper_valid', 'supplier_sku_hash',
            ], 'observation');
            $hash = strtolower(trim((string) ($observation['supplier_sku_hash'] ?? '')));
            if (! $this->isSha256($hash) || isset($seen[$hash])) {
                throw new InvalidArgumentException('invalid_supplier_sku_hash');
            }
            $seen[$hash] = true;
            $status = $observation['canonical_public_status'] ?? null;
            if ($status !== null && (! is_string($status) || ! in_array($status, $allowedStatuses, true))) {
                throw new InvalidArgumentException('invalid_canonical_public_status');
            }
            $mpnHash = $observation['reliable_manufacturer_mpn_hash'] ?? null;
            if ($mpnHash !== null && (! is_string($mpnHash) || ! $this->isSha256(strtolower($mpnHash)))) {
                throw new InvalidArgumentException('invalid_manufacturer_mpn_hash');
            }
            $quantity = $observation['raw_quantity_observed'] ?? null;
            if ($quantity !== null) {
                $quantity = $this->integer($quantity, 'invalid_raw_quantity_observed', 0, self::MAX_UNSIGNED_INTEGER);
            }
            $eol = $observation['eol_flag'] ?? null;
            if ($eol !== null) {
                $eol = $this->integer($eol, 'invalid_eol_flag', 0, 1);
            }

            $observations[] = CanonicalOnboardingData::normalize([
                'blocking_validation_issue' => $this->boolean($observation['blocking_validation_issue'] ?? null, 'missing_offer_presence_evidence'),
                'canonical_public_status' => $status,
                'duplicate_offer' => $this->boolean($observation['duplicate_offer'] ?? null, 'missing_offer_presence_evidence'),
                'eol_flag' => $eol,
                'exact_supplier_sku_match' => $this->boolean($observation['exact_supplier_sku_match'] ?? null, 'missing_offer_presence_evidence'),
                'identifier_conflict' => $this->boolean($observation['identifier_conflict'] ?? null, 'missing_offer_presence_evidence'),
                'present' => $this->boolean($observation['present'] ?? null, 'missing_offer_presence_evidence'),
                'price' => $this->decimal(
                    $observation['price'] ?? null,
                    'invalid_offer_price',
                    self::MAX_PRICE_SCALE,
                    self::MAX_PRICE,
                    true,
                ),
                'raw_quantity_observed' => $quantity,
                'reliable_manufacturer_mpn_hash' => $mpnHash === null ? null : strtolower($mpnHash),
                'supplier_mapper_valid' => $this->boolean($observation['supplier_mapper_valid'] ?? null, 'missing_offer_presence_evidence'),
                'supplier_sku_hash' => $hash,
            ]);
        }
        usort($observations, static fn (array $left, array $right): int => $left['supplier_sku_hash'] <=> $right['supplier_sku_hash']);

        return $observations;
    }

    /** @return array<string, array{continuous_qualified_absence_proven: bool, zero_active_offers_since: ?string}> */
    private function productLifecycleEvidence(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('invalid_product_lifecycle_evidence');
        }

        $result = [];
        foreach ($value as $evidence) {
            if (! is_array($evidence) || array_is_list($evidence)) {
                throw new InvalidArgumentException('invalid_product_lifecycle_evidence');
            }
            $this->assertExactKeys($evidence, ['continuous_qualified_absence_proven', 'product_reference_hash', 'zero_active_offers_since'], 'product_lifecycle_evidence');
            $productHash = strtolower(trim((string) ($evidence['product_reference_hash'] ?? '')));
            if (! $this->isSha256($productHash) || isset($result[$productHash])) {
                throw new InvalidArgumentException('invalid_product_reference_hash');
            }
            $zeroSince = $evidence['zero_active_offers_since'] ?? null;
            $result[$productHash] = [
                'continuous_qualified_absence_proven' => $this->boolean($evidence['continuous_qualified_absence_proven'] ?? null, 'invalid_product_lifecycle_evidence'),
                'zero_active_offers_since' => $zeroSince === null ? null : $this->timestamp($zeroSince, 'invalid_zero_active_offers_since')->toAtomString(),
            ];
        }
        ksort($result);

        return $result;
    }

    private function timestamp(mixed $value, string $error): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidArgumentException($error);
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $timestamp = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        if ($timestamp === false || $timestamp->format('Y-m-d\TH:i:sP') !== $normalized) {
            throw new InvalidArgumentException($error);
        }

        return $timestamp;
    }

    private function decimal(
        mixed $value,
        string $error,
        int $maximumScale,
        string $maximum,
        bool $nullable = false,
    ): ?string {
        if ($nullable && $value === null) {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException($error);
            }
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = $value;
        } else {
            throw new InvalidArgumentException($error);
        }

        if (preg_match('/^(?:0|[1-9]\d*)(?:\.(\d+))?$/D', $raw, $matches) !== 1
            || strlen($matches[1] ?? '') > $maximumScale) {
            throw new InvalidArgumentException($error);
        }

        $normalized = DecimalNormalizer::canonical($raw);
        if ($normalized === null || DecimalNormalizer::compare($normalized, $maximum) > 0) {
            throw new InvalidArgumentException($error);
        }

        return $normalized;
    }

    private function identifier(mixed $value, string $error): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $value) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function sourceIdentity(mixed $value): string
    {
        return OperationalSupplierSourceIdentity::validate($value);
    }

    /** @return array<int, string> */
    private function stringList(mixed $value, string $error): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException($error);
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = $this->identifier($item, $error);
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value, string $error): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException($error);
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException($error);
            }
            $result[$key] = trim($item);
        }
        ksort($result);

        return $result;
    }

    private function integer(mixed $value, string $error, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function boolean(mixed $value, string $error): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    /** @param array<string, mixed> $value @param array<int, string> $keys */
    private function assertExactKeys(array $value, array $keys, string $context): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new InvalidArgumentException("{$context}_keys_invalid");
        }
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function rejectRawIdentities(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), ['supplier_sku', 'ean', 'gtin', 'mpn', 'raw_record', 'source_path'], true)) {
                throw new InvalidArgumentException('raw_identity_or_source_path_not_allowed');
            }
            $this->rejectRawIdentities($item);
        }
    }
}
