<?php

namespace Tests\Unit\Suppliers\Snapshots;

use App\Data\Suppliers\Onboarding\SnapshotSourceIdentity;
use App\Data\Suppliers\Snapshots\CanonicalSupplierDispatchAlert;
use App\Data\Suppliers\Snapshots\CanonicalSupplierImportDispatchPayload;
use App\Data\Suppliers\Snapshots\CanonicalSupplierRecoveryExpectedStateV2;
use App\Data\Suppliers\Snapshots\CanonicalSupplierRecoveryResult;
use App\Data\Suppliers\Snapshots\CanonicalSupplierRecoveryResumeState;
use App\Data\Suppliers\Snapshots\CanonicalSupplierSnapshotEnrollment;
use App\Data\Suppliers\Snapshots\CanonicalSupplierSnapshotGenerationHeader;
use App\Data\Suppliers\Snapshots\CanonicalSupplierSnapshotObservation;
use App\Models\SupplierOfferSnapshotGeneration;
use App\Models\SupplierOfferSnapshotObservation;
use App\Services\Suppliers\Snapshots\SupplierSnapshotFingerprintService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CanonicalSupplierSnapshotByteContractTest extends TestCase
{
    private SupplierSnapshotFingerprintService $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fingerprints = new SupplierSnapshotFingerprintService;
    }

    public function test_identity_inventory_is_the_exact_approved_22_entry_contract(): void
    {
        $this->assertSame([
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
        ], SupplierSnapshotFingerprintService::IDENTITIES);
        $this->assertCount(22, SupplierSnapshotFingerprintService::IDENTITIES);
    }

    public function test_dispatch_payload_matches_both_normative_golden_vectors(): void
    {
        $first = CanonicalSupplierImportDispatchPayload::fromArray([
            'schema_version' => 'supplier-import-dispatch-payload-v1',
            'execution_claim_id' => 42,
            'logical_execution_key' => str_repeat('a', 64),
            'parent_type' => 'supplier_import_run',
            'parent_id' => 17,
            'transport_deadline_at' => '2026-08-20T12:34:56.123456Z',
            'force' => false,
        ]);
        $second = CanonicalSupplierImportDispatchPayload::fromArray([
            'schema_version' => 'supplier-import-dispatch-payload-v1',
            'execution_claim_id' => PHP_INT_MAX,
            'logical_execution_key' => str_repeat('0', 64),
            'parent_type' => 'supplier_feed',
            'parent_id' => 1,
            'transport_deadline_at' => '2026-12-31T23:59:59.999999Z',
            'force' => true,
        ]);

        $this->assertSame(
            '{"schema_version":"supplier-import-dispatch-payload-v1","execution_claim_id":42,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","parent_type":"supplier_import_run","parent_id":17,"transport_deadline_at":"2026-08-20T12:34:56.123456Z","force":false}',
            $first->canonicalBytes(),
        );
        $this->assertSame(
            'd2a1b00c8b6d70393fdd65b246daa6e7e0c3cbba7c4ac1ff13fa38e9e34d59d0',
            $this->fingerprints->dispatchPayloadHash($first),
        );
        $this->assertSame(
            '{"schema_version":"supplier-import-dispatch-payload-v1","execution_claim_id":9223372036854775807,"logical_execution_key":"0000000000000000000000000000000000000000000000000000000000000000","parent_type":"supplier_feed","parent_id":1,"transport_deadline_at":"2026-12-31T23:59:59.999999Z","force":true}',
            $second->canonicalBytes(),
        );
        $this->assertSame(
            '471b08a6da920cc82c9612f15fa812546ffa32daf1a8d499eaadecf3d9a2334e',
            $this->fingerprints->dispatchPayloadHash($second),
        );
    }

    public function test_expected_state_v2_is_the_exact_20_field_normative_vector(): void
    {
        $state = CanonicalSupplierRecoveryExpectedStateV2::fromArray($this->expectedStateFixture());
        $expectedBytes = '{"schema":"expected_state_fingerprint_v2","authorization_action":"recover_expired_queued_ownership","execution_claim_id":42,"dispatch_outbox_id":77,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","execution_path":"orchestrated","claim_state":"queued","outbox_state":"published","supplier_id":9,"supplier_import_run_id":501,"supplier_feed_id":12,"import_job_id":601,"import_history_id":701,"publication_attempt_count":2,"delivery_attempt_count":3,"transport_deadline_at":"2026-08-20T12:00:00.000000Z","delivery_watchdog_at":"2026-08-20T11:00:00.000000Z","active_attempt_token_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","claimed_at":"2026-08-20T10:00:00.000000Z","attempt_lease_expires_at":"2026-08-20T11:10:00.000000Z"}';

        $this->assertSame([
            'schema',
            'authorization_action',
            'execution_claim_id',
            'dispatch_outbox_id',
            'logical_execution_key',
            'execution_path',
            'claim_state',
            'outbox_state',
            'supplier_id',
            'supplier_import_run_id',
            'supplier_feed_id',
            'import_job_id',
            'import_history_id',
            'publication_attempt_count',
            'delivery_attempt_count',
            'transport_deadline_at',
            'delivery_watchdog_at',
            'active_attempt_token_hash',
            'claimed_at',
            'attempt_lease_expires_at',
        ], CanonicalSupplierRecoveryExpectedStateV2::fields());
        $this->assertCount(20, CanonicalSupplierRecoveryExpectedStateV2::fields());
        $this->assertSame(791, strlen($expectedBytes));
        $this->assertSame($expectedBytes, $state->canonicalBytes());
        $this->assertSame(
            '31d1cf23a2fceac08d71c0103b3093af392f916921ef2221d860a7ecf9f7a62c',
            $this->fingerprints->expectedStateFingerprint($state),
        );

        $reordered = CanonicalSupplierRecoveryExpectedStateV2::fromArray(
            array_reverse($this->expectedStateFixture(), true),
        );
        $changed = $this->expectedStateFixture();
        $changed['claimed_at'] = '2026-08-20T10:00:00.000001Z';

        $this->assertSame($state->canonicalBytes(), $reordered->canonicalBytes());
        $this->assertSame($state->fingerprint(), $reordered->fingerprint());
        $this->assertNotSame(
            $state->fingerprint(),
            CanonicalSupplierRecoveryExpectedStateV2::fromArray($changed)->fingerprint(),
        );
    }

    public function test_resume_and_result_contracts_match_independent_golden_vectors(): void
    {
        $resume = CanonicalSupplierRecoveryResumeState::fromArray($this->resumeFixture());
        $expectedResumeBytes = '{"schema":"supplier-import-dispatch-recovery-resume-v1","authorization_id":88,"authorization_action":"republish_same_key","authorized_operator_id":5,"execution_claim_id":42,"dispatch_outbox_id":77,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","target_parent_type":"supplier_import_run","target_parent_id":501,"claim_state":"queued","outbox_state":"recovery_required","recovery_reason_code":"dispatch_durable_progress_stalled","publication_attempt_count":2,"delivery_attempt_count":3,"transport_deadline_at":"2026-08-20T12:00:00.000000Z","delivery_watchdog_at":null}';

        $this->assertSame([
            'schema',
            'authorization_id',
            'authorization_action',
            'authorized_operator_id',
            'execution_claim_id',
            'dispatch_outbox_id',
            'logical_execution_key',
            'target_parent_type',
            'target_parent_id',
            'claim_state',
            'outbox_state',
            'recovery_reason_code',
            'publication_attempt_count',
            'delivery_attempt_count',
            'transport_deadline_at',
            'delivery_watchdog_at',
        ], CanonicalSupplierRecoveryResumeState::fields());
        $this->assertCount(16, CanonicalSupplierRecoveryResumeState::fields());
        $this->assertSame(610, strlen($expectedResumeBytes));
        $this->assertSame($expectedResumeBytes, $resume->canonicalBytes());
        $this->assertSame(
            '1773b68dacaae6c50b2305aec164b7135d0c43da06a69dd3ef676176e785aba3',
            $this->fingerprints->resumeStateFingerprint($resume),
        );

        $reordered = CanonicalSupplierRecoveryResumeState::fromArray(
            array_reverse($this->resumeFixture(), true),
        );
        $changed = $this->resumeFixture();
        $changed['delivery_attempt_count'] = 4;
        $this->assertSame($resume->canonicalBytes(), $reordered->canonicalBytes());
        $this->assertNotSame(
            $resume->fingerprint(),
            CanonicalSupplierRecoveryResumeState::fromArray($changed)->fingerprint(),
        );

        $result = CanonicalSupplierRecoveryResult::fromArray($this->resultFixture());
        $expectedResultBytes = '{"schema":"supplier-import-dispatch-recovery-result-v1","authorization_id":88,"authorization_action":"republish_same_key","authorized_operator_id":5,"execution_claim_id":42,"dispatch_outbox_id":77,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","target_parent_type":"supplier_import_run","target_parent_id":501,"event_sequence":1,"event_kind":"started","expected_state_fingerprint":"31d1cf23a2fceac08d71c0103b3093af392f916921ef2221d860a7ecf9f7a62c","resume_state_fingerprint":"1773b68dacaae6c50b2305aec164b7135d0c43da06a69dd3ef676176e785aba3","canonical_result_code":"authorization_attempt_started","occurred_at":"2026-08-20T10:01:00.000000Z"}';
        $this->assertCount(15, CanonicalSupplierRecoveryResult::fields());
        $this->assertSame($expectedResultBytes, $result->canonicalBytes());
        $this->assertSame(
            '68573a0b048d646cd4f52dc1e8b987256476cf65badff26c8b5a47e463b8968a',
            $this->fingerprints->resultFingerprint($result),
        );
    }

    public function test_alert_contract_matches_both_normative_golden_vectors(): void
    {
        $warning = CanonicalSupplierDispatchAlert::fromArray([
            'schema' => 'supplier-import-dispatch-alert-v1',
            'alert_type' => 'dispatch_watchdog_overdue',
            'dispatch_outbox_id' => 101,
            'delivery_watchdog_at' => '2026-08-20T10:15:30.123456Z',
            'severity' => 'warning',
            'critical_bucket' => null,
        ]);
        $critical = CanonicalSupplierDispatchAlert::fromArray([
            'schema' => 'supplier-import-dispatch-alert-v1',
            'alert_type' => 'dispatch_watchdog_overdue',
            'dispatch_outbox_id' => 202,
            'delivery_watchdog_at' => '2026-08-20T10:45:30.000000Z',
            'severity' => 'critical',
            'critical_bucket' => 0,
        ]);

        $this->assertCount(6, CanonicalSupplierDispatchAlert::fields());
        $this->assertSame(
            '{"schema":"supplier-import-dispatch-alert-v1","alert_type":"dispatch_watchdog_overdue","dispatch_outbox_id":101,"delivery_watchdog_at":"2026-08-20T10:15:30.123456Z","severity":"warning","critical_bucket":null}',
            $warning->canonicalBytes(),
        );
        $this->assertSame(
            '0784419b016bd71a2ad912c752ab64d5405899f261a22fa78c75f5a300002fe0',
            $this->fingerprints->alertIdentity($warning),
        );
        $this->assertSame(
            '{"schema":"supplier-import-dispatch-alert-v1","alert_type":"dispatch_watchdog_overdue","dispatch_outbox_id":202,"delivery_watchdog_at":"2026-08-20T10:45:30.000000Z","severity":"critical","critical_bucket":0}',
            $critical->canonicalBytes(),
        );
        $this->assertSame(
            'a4cfd7d96ada0678b7054d3bfe2f62a1b423a98bb9507ce7e664a9c549b14f31',
            $this->fingerprints->alertIdentity($critical),
        );
    }

    public function test_raw_and_sample_identities_match_independent_golden_values(): void
    {
        $bytes = implode('', array_map(chr(...), range(0, 31)));
        $hashes = [str_repeat('c', 64), str_repeat('a', 64)];

        $this->assertSame(
            '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
            $this->fingerprints->logicalExecutionKey($bytes),
        );
        $this->assertSame(
            '6c67b172bf27a2679b047e98290a51675f9438fc13dca1aa5020c75b0a3f7af4',
            $this->fingerprints->activeAttemptTokenHash('attempt-token-Ж'),
        );
        $this->assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $this->fingerprints->sourceFingerprint(''),
        );
        $this->assertSame(
            '2c80af130e7c29586a8e40b306691fd9726d60daa488ff3580121f95a823fc38',
            $this->fingerprints->leaseTokenHash('lease-token'),
        );
        $this->assertSame(
            'f2c32bd86ae6d0bf1207cbc60ec11b15e0bae12dcf746a62e5510eadb0ac822f',
            $this->fingerprints->publicationAttemptTokenHash('publication-token'),
        );
        $this->assertSame(
            '5eedb3d3c99217e8d7c733017efdc100256e432c54d56625c3053d530bfd48d7',
            $this->fingerprints->monitorOwnerTokenHash('monitor-token'),
        );
        $this->assertSame(
            '8affab5a39f9ab2ae43da2d8390cad25d47da11253c2f3d951a2188cc0d5b3df',
            $this->fingerprints->deliveryOwnerTokenHash('delivery-token'),
        );
        $this->assertSame(
            'bfc4f0f1526e4e25a761d5fdc4d93c213e996b5c729e953f38964537232f8382',
            $this->fingerprints->authorizationNonceHash($bytes),
        );
        $this->assertSame(
            '72f182cb17ed7372e67fe21f8c109004461fcf241d40446c60e18581e41c457d',
            $this->fingerprints->supplierSkuHash(' APCOM ', ' SKU/БГ-01 '),
        );

        $cohortSeedBytes = '{"cohort_authorization_version":"supplier_offer_cohort_v1","member_hashes":["aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"]}';
        $this->assertSame(
            $cohortSeedBytes,
            $this->fingerprints->cohortSeedCanonicalBytes('supplier_offer_cohort_v1', $hashes),
        );
        $this->assertSame(
            '2f58069191563613859a94a8396b68d2edee900187958af6ee4d647818a15ffa',
            $this->fingerprints->cohortSeedFingerprint('supplier_offer_cohort_v1', $hashes),
        );

        $this->assertSame(
            '{"import_history_id":701,"supplier_key":"apcom"}',
            $this->fingerprints->snapshotIdCanonicalBytes(' APCOM ', 701),
        );
        $this->assertSame(
            'dfbfacb6022c7e86862537cd08a2451e129a6e535d3fae736c53889bebec3dcc',
            $this->fingerprints->snapshotId(' APCOM ', 701),
        );
        $this->assertSame(
            '["aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"]',
            $this->fingerprints->cohortCanonicalBytes($hashes),
        );
        $this->assertSame(
            '86f7a593db0ffb8fe9f00aba83d759492dae39df709175edfe22f44293caff60',
            $this->fingerprints->cohortFingerprint($hashes),
        );
        $this->assertSame(
            '5de6bec42787eafdc146d7e9d4ddc43cf24369ca5b0117ee50ec0dabf0f15a98',
            $this->fingerprints->observationSetFingerprint($hashes),
        );
        $this->assertNull($this->fingerprints->reliableManufacturerMpnHash(null));
    }

    public function test_snapshot_header_enrollment_and_observation_match_golden_bytes_and_hashes(): void
    {
        $header = CanonicalSupplierSnapshotGenerationHeader::fromArray($this->generationFixture());
        $enrollment = CanonicalSupplierSnapshotEnrollment::fromArray($this->enrollmentFixture());
        $observation = CanonicalSupplierSnapshotObservation::fromArray($this->observationFixture());

        $expectedHeaderBytes = <<<'JSON'
{"authoritative_snapshot_at":"2026-08-20T11:58:00+00:00","capture_completed_at":"2026-08-20T12:00:00+00:00","capture_failure_reason_code":null,"capture_integrity_policy_key":"capture-integrity-v1","capture_outcome":"completed","capture_started_at":"2026-08-20T11:59:00+00:00","captured_at":"2026-08-20T12:00:00+00:00","cohort_fingerprint":"2222222222222222222222222222222222222222222222222222222222222222","comparable":false,"duplicate_observation_count":0,"enrolled_observation_count":2,"fatal_integrity_blocker":false,"freshness_max_age_hours":null,"freshness_policy_approved":false,"freshness_policy_key":null,"full":true,"import_history_id":701,"invalid_observation_count":0,"maximum_product_drop_percent":40,"minimum_product_count":1,"observation_set_fingerprint":"3333333333333333333333333333333333333333333333333333333333333333","policy_versions":{"capture":"capture-v1","label":"Версия / \"A\" \\ B"},"predecessor_snapshot_generation_id":null,"producer_version":"snapshot-producer-v1","product_drop_percent":null,"qualification_policy_key":"qualification-v1","qualification_reason_codes":[],"qualification_state":"qualified_baseline","rejected_observation_count":0,"schema_valid":true,"schema_version":"supplier-offer-snapshot-generation-v1","source_fingerprint":"1111111111111111111111111111111111111111111111111111111111111111","source_identity":"snapshot-source-v1:apcom:primary-stock-price","successful":true,"supplier_feed_id":12,"supplier_id":9,"supplier_identity_confirmed":true,"supplier_import_execution_claim_id":42,"supplier_key":"apcom","total_observed_count":2,"truncated":false,"valid_observation_count":2}
JSON;
        $expectedEnrollmentBytes = '{"effective_import_history_id":701,"enrollment_source":"capture_start_seed_and_exact_source_observation","source_identity":"snapshot-source-v1:apcom:primary-stock-price","supplier_key":"apcom","supplier_sku_hash":"4444444444444444444444444444444444444444444444444444444444444444"}';
        $expectedObservationBytes = '{"blocking_validation_issue":false,"canonical_public_status":"in_stock","currency":"EUR","duplicate_offer":false,"eol_flag":0,"exact_supplier_sku_match":true,"identifier_conflict":false,"present":true,"price":"100.00","raw_quantity_observed":4294967295,"reliable_manufacturer_mpn_hash":null,"supplier_mapper_valid":true,"supplier_sku_hash":"4444444444444444444444444444444444444444444444444444444444444444"}';

        $this->assertCount(42, CanonicalSupplierSnapshotGenerationHeader::fields());
        $this->assertSame(1634, strlen($expectedHeaderBytes));
        $this->assertSame($expectedHeaderBytes, $header->canonicalBytes());
        $this->assertSame(
            'fe9b7b9d6ba91912606d8498c6faa4968b8315df6e5646144586f461ac1d54f8',
            $this->fingerprints->generationFingerprint($header),
        );
        $this->assertSame(
            $header->canonicalBytes(),
            CanonicalSupplierSnapshotGenerationHeader::fromArray(
                array_reverse($this->generationFixture(), true),
            )->canonicalBytes(),
        );
        $this->assertCount(5, CanonicalSupplierSnapshotEnrollment::fields());
        $this->assertSame($expectedEnrollmentBytes, $enrollment->canonicalBytes());
        $this->assertSame(
            'c038d10a5f0ae13fabc7ba78bbd6a917a8552da4c9deef8fe1314d0fe0076d37',
            $this->fingerprints->enrollmentFingerprint($enrollment),
        );
        $this->assertCount(13, CanonicalSupplierSnapshotObservation::fields());
        $this->assertSame($expectedObservationBytes, $observation->canonicalBytes());
        $this->assertSame(
            '63ec48e5f5fcc378333707c727874643b3381550e093e1e62df20cc9b3591a09',
            $this->fingerprints->observationFingerprint($observation),
        );
    }

    public function test_strict_contracts_reject_unknown_missing_and_wrong_type_fields(): void
    {
        $contracts = [
            [CanonicalSupplierImportDispatchPayload::class, $this->dispatchFixture(), 'execution_claim_id'],
            [CanonicalSupplierRecoveryExpectedStateV2::class, $this->expectedStateFixture(), 'execution_claim_id'],
            [CanonicalSupplierRecoveryResumeState::class, $this->resumeFixture(), 'authorization_id'],
            [CanonicalSupplierRecoveryResult::class, $this->resultFixture(), 'authorization_id'],
            [CanonicalSupplierDispatchAlert::class, $this->alertFixture(), 'dispatch_outbox_id'],
            [CanonicalSupplierSnapshotGenerationHeader::class, $this->generationFixture(), 'supplier_id'],
            [CanonicalSupplierSnapshotEnrollment::class, $this->enrollmentFixture(), 'effective_import_history_id'],
            [CanonicalSupplierSnapshotObservation::class, $this->observationFixture(), 'raw_quantity_observed'],
        ];

        foreach ($contracts as [$class, $fixture, $typedField]) {
            $withUnknown = [...$fixture, 'unknown_security_field' => true];
            $missing = $fixture;
            unset($missing[array_key_first($missing)]);
            $wrongType = $fixture;
            $wrongType[$typedField] = '1';

            $this->assertRejects(fn () => $class::fromArray($withUnknown));
            $this->assertRejects(fn () => $class::fromArray($missing));
            $this->assertRejects(fn () => $class::fromArray($wrongType));
        }
    }

    public function test_contracts_reject_noncanonical_time_decimal_utf8_and_reserved_mpn_values(): void
    {
        $dispatch = $this->dispatchFixture();
        $dispatch['transport_deadline_at'] = '2026-08-20T15:00:00.000000+03:00';
        $this->assertRejects(fn () => CanonicalSupplierImportDispatchPayload::fromArray($dispatch));

        $observation = $this->observationFixture();
        $observation['price'] = 100.00;
        $this->assertRejects(fn () => CanonicalSupplierSnapshotObservation::fromArray($observation));

        $observation = $this->observationFixture();
        $observation['reliable_manufacturer_mpn_hash'] = str_repeat('a', 64);
        $this->assertRejects(fn () => CanonicalSupplierSnapshotObservation::fromArray($observation));
        $this->assertRejects(fn () => $this->fingerprints->reliableManufacturerMpnHash(str_repeat('a', 64)));

        $enrollment = $this->enrollmentFixture();
        $enrollment['supplier_key'] = "apcom\xFF";
        $this->assertRejects(fn () => CanonicalSupplierSnapshotEnrollment::fromArray($enrollment));
        $this->assertRejects(fn () => SnapshotSourceIdentity::from('snapshot-source-v1:Uppercase'));
        $this->assertRejects(fn () => $this->fingerprints->logicalExecutionKey('too-short'));
        $this->assertRejects(fn () => $this->fingerprints->authorizationNonceHash('too-short'));
        $this->assertRejects(fn () => $this->fingerprints->activeAttemptTokenHash(''));
    }

    public function test_cross_field_null_and_state_invariants_fail_closed(): void
    {
        $partialOwner = $this->expectedStateFixture();
        $partialOwner['claimed_at'] = null;
        $this->assertRejects(fn () => CanonicalSupplierRecoveryExpectedStateV2::fromArray($partialOwner));

        $legacyWithoutParent = $this->expectedStateFixture();
        $legacyWithoutParent['execution_path'] = 'legacy_xml';
        $legacyWithoutParent['supplier_import_run_id'] = null;
        $legacyWithoutParent['supplier_feed_id'] = null;
        $legacyWithoutParent['import_job_id'] = null;
        $this->assertRejects(fn () => CanonicalSupplierRecoveryExpectedStateV2::fromArray($legacyWithoutParent));

        $warningBucket = $this->alertFixture();
        $warningBucket['critical_bucket'] = 0;
        $this->assertRejects(fn () => CanonicalSupplierDispatchAlert::fromArray($warningBucket));

        $absentWithPrice = $this->observationFixture();
        $absentWithPrice['present'] = false;
        $this->assertRejects(fn () => CanonicalSupplierSnapshotObservation::fromArray($absentWithPrice));

        $invalidQualified = $this->generationFixture();
        $invalidQualified['qualification_reason_codes'] = ['capture_failed'];
        $this->assertRejects(fn () => CanonicalSupplierSnapshotGenerationHeader::fromArray($invalidQualified));
    }

    public function test_canonical_bytes_are_order_timezone_and_eloquent_serialization_independent(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Sofia');
            $sofia = CanonicalSupplierSnapshotObservation::fromArray(
                array_reverse($this->observationFixture(), true),
            );
            date_default_timezone_set('UTC');
            $utc = CanonicalSupplierSnapshotObservation::fromArray($this->observationFixture());
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        $model = new SupplierOfferSnapshotObservation($this->observationFixture());
        $model->makeVisible(['supplier_sku_hash', 'observation_fingerprint']);
        $model->setRelation('generation', new SupplierOfferSnapshotGeneration(['supplier_key' => 'other']));

        $this->assertSame($utc->canonicalBytes(), $sofia->canonicalBytes());
        $this->assertSame(
            $this->fingerprints->observationFingerprint($utc),
            $this->fingerprints->observationFingerprint($sofia),
        );
        $this->assertNotSame($utc->canonicalBytes(), $model->toJson());

        $changed = $this->observationFixture();
        $changed['raw_quantity_observed'] = 0;
        $this->assertNotSame(
            $this->fingerprints->observationFingerprint($utc),
            $this->fingerprints->observationFingerprint(
                CanonicalSupplierSnapshotObservation::fromArray($changed),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function dispatchFixture(): array
    {
        return [
            'schema_version' => 'supplier-import-dispatch-payload-v1',
            'execution_claim_id' => 42,
            'logical_execution_key' => str_repeat('a', 64),
            'parent_type' => 'supplier_import_run',
            'parent_id' => 17,
            'transport_deadline_at' => '2026-08-20T12:34:56.123456Z',
            'force' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function expectedStateFixture(): array
    {
        return [
            'schema' => 'expected_state_fingerprint_v2',
            'authorization_action' => 'recover_expired_queued_ownership',
            'execution_claim_id' => 42,
            'dispatch_outbox_id' => 77,
            'logical_execution_key' => str_repeat('a', 64),
            'execution_path' => 'orchestrated',
            'claim_state' => 'queued',
            'outbox_state' => 'published',
            'supplier_id' => 9,
            'supplier_import_run_id' => 501,
            'supplier_feed_id' => 12,
            'import_job_id' => 601,
            'import_history_id' => 701,
            'publication_attempt_count' => 2,
            'delivery_attempt_count' => 3,
            'transport_deadline_at' => '2026-08-20T12:00:00.000000Z',
            'delivery_watchdog_at' => '2026-08-20T11:00:00.000000Z',
            'active_attempt_token_hash' => str_repeat('b', 64),
            'claimed_at' => '2026-08-20T10:00:00.000000Z',
            'attempt_lease_expires_at' => '2026-08-20T11:10:00.000000Z',
        ];
    }

    /** @return array<string, mixed> */
    private function resumeFixture(): array
    {
        return [
            'schema' => 'supplier-import-dispatch-recovery-resume-v1',
            'authorization_id' => 88,
            'authorization_action' => 'republish_same_key',
            'authorized_operator_id' => 5,
            'execution_claim_id' => 42,
            'dispatch_outbox_id' => 77,
            'logical_execution_key' => str_repeat('a', 64),
            'target_parent_type' => 'supplier_import_run',
            'target_parent_id' => 501,
            'claim_state' => 'queued',
            'outbox_state' => 'recovery_required',
            'recovery_reason_code' => 'dispatch_durable_progress_stalled',
            'publication_attempt_count' => 2,
            'delivery_attempt_count' => 3,
            'transport_deadline_at' => '2026-08-20T12:00:00.000000Z',
            'delivery_watchdog_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function resultFixture(): array
    {
        return [
            'schema' => 'supplier-import-dispatch-recovery-result-v1',
            'authorization_id' => 88,
            'authorization_action' => 'republish_same_key',
            'authorized_operator_id' => 5,
            'execution_claim_id' => 42,
            'dispatch_outbox_id' => 77,
            'logical_execution_key' => str_repeat('a', 64),
            'target_parent_type' => 'supplier_import_run',
            'target_parent_id' => 501,
            'event_sequence' => 1,
            'event_kind' => 'started',
            'expected_state_fingerprint' => '31d1cf23a2fceac08d71c0103b3093af392f916921ef2221d860a7ecf9f7a62c',
            'resume_state_fingerprint' => '1773b68dacaae6c50b2305aec164b7135d0c43da06a69dd3ef676176e785aba3',
            'canonical_result_code' => 'authorization_attempt_started',
            'occurred_at' => '2026-08-20T10:01:00.000000Z',
        ];
    }

    /** @return array<string, mixed> */
    private function alertFixture(): array
    {
        return [
            'schema' => 'supplier-import-dispatch-alert-v1',
            'alert_type' => 'dispatch_watchdog_overdue',
            'dispatch_outbox_id' => 101,
            'delivery_watchdog_at' => '2026-08-20T10:15:30.123456Z',
            'severity' => 'warning',
            'critical_bucket' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function generationFixture(): array
    {
        return [
            'supplier_id' => 9,
            'supplier_key' => 'apcom',
            'supplier_feed_id' => 12,
            'supplier_import_execution_claim_id' => 42,
            'import_history_id' => 701,
            'predecessor_snapshot_generation_id' => null,
            'schema_version' => 'supplier-offer-snapshot-generation-v1',
            'producer_version' => 'snapshot-producer-v1',
            'qualification_policy_key' => 'qualification-v1',
            'capture_integrity_policy_key' => 'capture-integrity-v1',
            'policy_versions' => [
                'label' => 'Версия / "A" \\ B',
                'capture' => 'capture-v1',
            ],
            'freshness_policy_key' => null,
            'freshness_max_age_hours' => null,
            'freshness_policy_approved' => false,
            'source_identity' => 'snapshot-source-v1:apcom:primary-stock-price',
            'source_fingerprint' => str_repeat('1', 64),
            'captured_at' => '2026-08-20T12:00:00+00:00',
            'authoritative_snapshot_at' => '2026-08-20T11:58:00+00:00',
            'capture_started_at' => '2026-08-20T11:59:00+00:00',
            'capture_completed_at' => '2026-08-20T12:00:00+00:00',
            'capture_outcome' => 'completed',
            'capture_failure_reason_code' => null,
            'qualification_state' => 'qualified_baseline',
            'qualification_reason_codes' => [],
            'successful' => true,
            'full' => true,
            'schema_valid' => true,
            'truncated' => false,
            'fatal_integrity_blocker' => false,
            'supplier_identity_confirmed' => true,
            'comparable' => false,
            'total_observed_count' => 2,
            'valid_observation_count' => 2,
            'invalid_observation_count' => 0,
            'rejected_observation_count' => 0,
            'duplicate_observation_count' => 0,
            'enrolled_observation_count' => 2,
            'minimum_product_count' => 1,
            'product_drop_percent' => null,
            'maximum_product_drop_percent' => 40,
            'cohort_fingerprint' => str_repeat('2', 64),
            'observation_set_fingerprint' => str_repeat('3', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function enrollmentFixture(): array
    {
        return [
            'supplier_key' => 'apcom',
            'source_identity' => 'snapshot-source-v1:apcom:primary-stock-price',
            'supplier_sku_hash' => str_repeat('4', 64),
            'effective_import_history_id' => 701,
            'enrollment_source' => 'capture_start_seed_and_exact_source_observation',
        ];
    }

    /** @return array<string, mixed> */
    private function observationFixture(): array
    {
        return [
            'supplier_sku_hash' => str_repeat('4', 64),
            'present' => true,
            'price' => '100.00',
            'currency' => 'EUR',
            'raw_quantity_observed' => 4_294_967_295,
            'eol_flag' => 0,
            'canonical_public_status' => 'in_stock',
            'supplier_mapper_valid' => true,
            'exact_supplier_sku_match' => true,
            'identifier_conflict' => false,
            'blocking_validation_issue' => false,
            'duplicate_offer' => false,
            'reliable_manufacturer_mpn_hash' => null,
        ];
    }

    private function assertRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected canonical contract rejection.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
