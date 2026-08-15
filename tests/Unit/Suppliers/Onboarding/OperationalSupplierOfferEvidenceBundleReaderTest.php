<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundle;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundleReader;
use InvalidArgumentException;
use Tests\TestCase;

final class OperationalSupplierOfferEvidenceBundleReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        foreach ($this->temporaryDirectories as $directory) {
            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_valid_versioned_local_bundle_is_canonical_and_redacted(): void
    {
        $path = $this->fixturePath();
        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));

        $this->assertSame(OperationalSupplierOfferEvidenceBundle::SCHEMA_VERSION, $bundle->toArray()['schema_version']);
        $this->assertSame('apcom', $bundle->supplierKey);
        $this->assertSame('apcom-human-decisions-v4', $bundle->policyVersions['decision_register']);
        $this->assertSame(24, $bundle->freshnessPolicies['apcom']['max_age_hours']);
        $encoded = json_encode($bundle, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('supplier_sku"', $encoded);
        $this->assertStringNotContainsString('source_path', $encoded);
        $this->assertStringNotContainsString('http://', $encoded);
    }

    public function test_primary_source_identity_is_stable_and_drift_remains_rejected(): void
    {
        $stable = $this->fixtureArray();
        $stable['snapshots'][] = $this->snapshotForSupplier(
            $stable['snapshots'][0],
            'apcom',
            'synthetic-apcom-stock-price-v1',
            'synthetic-apcom-002',
            '2026-08-10T13:00:00+00:00',
        );
        $path = $this->write($stable);

        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));

        $this->assertCount(2, $bundle->snapshots);
        $this->assertSame('synthetic-apcom-stock-price-v1', $bundle->snapshots[1]['source_identity']);

        $drifting = $stable;
        $drifting['snapshots'][1]['source_identity'] = 'synthetic-apcom-stock-price-v2';
        $this->assertSourceIdentityMismatch($drifting, [
            'synthetic-apcom-stock-price-v1',
            'synthetic-apcom-stock-price-v2',
        ]);
    }

    public function test_primary_source_identity_is_compared_exactly_without_lossy_normalization(): void
    {
        foreach ([
            'SYNTHETIC-APCOM-STOCK-PRICE-V1',
            'synthetic-apcom-stock-price-v1 ',
            ' synthetic-apcom-stock-price-v1',
        ] as $driftedIdentity) {
            $data = $this->fixtureArray();
            $data['snapshots'][] = $this->snapshotForSupplier(
                $data['snapshots'][0],
                'apcom',
                $driftedIdentity,
                'synthetic-apcom-002',
                '2026-08-10T13:00:00+00:00',
            );

            $this->assertSourceIdentityMismatch($data, [
                'synthetic-apcom-stock-price-v1',
                $driftedIdentity,
            ]);
        }

        $reordered = $this->fixtureArray();
        $reordered['snapshots'][] = $this->snapshotForSupplier(
            $reordered['snapshots'][0],
            'apcom',
            'SYNTHETIC-APCOM-STOCK-PRICE-V1',
            'synthetic-apcom-002',
            '2026-08-10T13:00:00+00:00',
        );
        $reordered['snapshots'] = array_reverse($reordered['snapshots']);
        $this->assertSourceIdentityMismatch($reordered, [
            'synthetic-apcom-stock-price-v1',
            'SYNTHETIC-APCOM-STOCK-PRICE-V1',
        ]);
    }

    public function test_stable_exact_source_identity_preserves_case_whitespace_punctuation_and_bounds(): void
    {
        foreach ([
            'SYNTHETIC-APCOM-STOCK-PRICE-V1',
            ' synthetic-apcom-stock-price-v1 ',
            'synthetic:apcom/source.identity_v1',
            str_repeat('x', 128),
        ] as $identity) {
            $data = $this->fixtureArray();
            $data['source_identity'] = $identity;
            $data['snapshots'][0]['source_identity'] = $identity;
            $path = $this->write($data);

            $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));

            $this->assertSame($identity, $bundle->sourceIdentity);
            $this->assertSame($identity, $bundle->snapshots[0]['source_identity']);
        }

        foreach (['', ' ', "\t\r\n", "\u{00A0}", str_repeat('x', 129)] as $invalidIdentity) {
            $data = $this->fixtureArray();
            $data['source_identity'] = $invalidIdentity;
            $data['snapshots'][0]['source_identity'] = $invalidIdentity;
            $this->assertDataFails($data, 'invalid_source_identity');
        }
    }

    public function test_alternative_suppliers_may_have_distinct_individually_stable_source_identities(): void
    {
        $data = $this->fixtureArray();
        $data['supplier_scope'] = ['apcom', 'backup-one', 'backup-two'];
        $data['snapshots'][] = $this->snapshotForSupplier(
            $data['snapshots'][0],
            'backup-one',
            'synthetic-backup-one-stock-v1',
            'synthetic-backup-one-001',
            '2026-08-10T13:00:00+00:00',
        );
        $data['snapshots'][] = $this->snapshotForSupplier(
            $data['snapshots'][0],
            'backup-one',
            'synthetic-backup-one-stock-v1',
            'synthetic-backup-one-002',
            '2026-08-10T14:00:00+00:00',
        );
        $data['snapshots'][] = $this->snapshotForSupplier(
            $data['snapshots'][0],
            'backup-two',
            'synthetic-backup-two-stock-v1',
            'synthetic-backup-two-001',
            '2026-08-10T15:00:00+00:00',
        );
        $path = $this->write($data);

        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));

        $this->assertCount(4, $bundle->snapshots);
        $this->assertSame(
            ['synthetic-backup-one-stock-v1'],
            collect($bundle->snapshots)
                ->where('supplier', 'backup-one')
                ->pluck('source_identity')
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertSame(
            ['synthetic-backup-two-stock-v1'],
            collect($bundle->snapshots)
                ->where('supplier', 'backup-two')
                ->pluck('source_identity')
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertNotSame($bundle->sourceIdentity, $bundle->snapshots[1]['source_identity']);
    }

    public function test_alternative_source_identity_drift_is_rejected_regardless_of_identity_order_without_leakage(): void
    {
        foreach ([
            ['synthetic-backup-private-a', 'synthetic-backup-private-b'],
            ['synthetic-backup-private-b', 'synthetic-backup-private-a'],
            ['synthetic-backup-identity-v1', 'SYNTHETIC-BACKUP-IDENTITY-V1 '],
            ['synthetic-backup-identity-v1', ' synthetic-backup-identity-v1'],
        ] as [$firstIdentity, $secondIdentity]) {
            $data = $this->fixtureArray();
            $data['supplier_scope'] = ['apcom', 'backup-supplier'];
            $data['snapshots'][] = $this->snapshotForSupplier(
                $data['snapshots'][0],
                'backup-supplier',
                $firstIdentity,
                'synthetic-backup-001',
                '2026-08-10T13:00:00+00:00',
            );
            $data['snapshots'][] = $this->snapshotForSupplier(
                $data['snapshots'][0],
                'backup-supplier',
                $secondIdentity,
                'synthetic-backup-002',
                '2026-08-10T14:00:00+00:00',
            );

            $this->assertSourceIdentityMismatch($data, [$firstIdentity, $secondIdentity]);

            $data['snapshots'] = [
                $data['snapshots'][0],
                $data['snapshots'][2],
                $data['snapshots'][1],
            ];
            $this->assertSourceIdentityMismatch($data, [$firstIdentity, $secondIdentity]);
        }
    }

    public function test_unqualified_alternative_snapshot_drift_is_still_rejected(): void
    {
        $data = $this->fixtureArray();
        $data['supplier_scope'] = ['apcom', 'backup-supplier'];
        $data['snapshots'][] = $this->snapshotForSupplier(
            $data['snapshots'][0],
            'backup-supplier',
            'synthetic-backup-stable-v1',
            'synthetic-backup-001',
            '2026-08-10T13:00:00+00:00',
        );
        $frozen = $this->snapshotForSupplier(
            $data['snapshots'][0],
            'backup-supplier',
            'synthetic-backup-drifted-v2',
            'synthetic-backup-002',
            '2026-08-10T14:00:00+00:00',
        );
        $frozen['successful'] = false;
        $frozen['status'] = 'failed';
        $data['snapshots'][] = $frozen;

        $this->assertSourceIdentityMismatch($data, [
            'synthetic-backup-stable-v1',
            'synthetic-backup-drifted-v2',
        ]);
    }

    public function test_direct_bundle_construction_cannot_bypass_source_identity_stability(): void
    {
        $path = $this->fixturePath();
        $valid = (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
        $snapshots = $valid->snapshots;
        $snapshots[] = $this->snapshotForSupplier(
            $snapshots[0],
            'backup-supplier',
            'synthetic-direct-private-a',
            'synthetic-backup-001',
            '2026-08-10T13:00:00+00:00',
        );
        $snapshots[] = $this->snapshotForSupplier(
            $snapshots[0],
            'backup-supplier',
            'synthetic-direct-private-b',
            'synthetic-backup-002',
            '2026-08-10T14:00:00+00:00',
        );

        try {
            new OperationalSupplierOfferEvidenceBundle(
                evidenceFingerprint: $valid->evidenceFingerprint,
                supplierKey: $valid->supplierKey,
                supplierScope: ['apcom', 'backup-supplier'],
                policyVersions: $valid->policyVersions,
                sourceIdentity: $valid->sourceIdentity,
                freshnessPolicies: $valid->freshnessPolicies,
                snapshots: $snapshots,
                productLifecycleEvidence: $valid->productLifecycleEvidence,
            );
            $this->fail('Expected direct bundle construction to reject source identity drift.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('source_identity_mismatch', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-direct-private-a', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-direct-private-b', $exception->getMessage());
        }

        foreach ([
            ['synthetic-direct-private-a', 'SYNTHETIC-DIRECT-PRIVATE-A'],
            ['synthetic-direct-private-a', 'synthetic-direct-private-a '],
            ['synthetic-direct-private-a', ' synthetic-direct-private-a'],
        ] as [$firstIdentity, $secondIdentity]) {
            $directSnapshots = $valid->snapshots;
            $directSnapshots[] = $this->snapshotForSupplier(
                $directSnapshots[0],
                'backup-supplier',
                $firstIdentity,
                'synthetic-direct-backup-001',
                '2026-08-10T13:00:00+00:00',
            );
            $directSnapshots[] = $this->snapshotForSupplier(
                $directSnapshots[0],
                'backup-supplier',
                $secondIdentity,
                'synthetic-direct-backup-002',
                '2026-08-10T14:00:00+00:00',
            );

            try {
                new OperationalSupplierOfferEvidenceBundle(
                    evidenceFingerprint: $valid->evidenceFingerprint,
                    supplierKey: $valid->supplierKey,
                    supplierScope: ['apcom', 'backup-supplier'],
                    policyVersions: $valid->policyVersions,
                    sourceIdentity: $valid->sourceIdentity,
                    freshnessPolicies: $valid->freshnessPolicies,
                    snapshots: array_reverse($directSnapshots),
                    productLifecycleEvidence: $valid->productLifecycleEvidence,
                );
                $this->fail('Expected reordered direct bundle construction to reject exact source identity drift.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('source_identity_mismatch', $exception->getMessage());
                $this->assertStringNotContainsString($firstIdentity, $exception->getMessage());
                $this->assertStringNotContainsString($secondIdentity, $exception->getMessage());
            }
        }

        $stableIdentity = ' Stable-Direct-Identity-v1 ';
        $stableSnapshots = $valid->snapshots;
        $stableSnapshots[] = $this->snapshotForSupplier(
            $stableSnapshots[0],
            'backup-supplier',
            $stableIdentity,
            'synthetic-direct-stable-001',
            '2026-08-10T13:00:00+00:00',
        );
        $stableSnapshots[] = $this->snapshotForSupplier(
            $stableSnapshots[0],
            'backup-supplier',
            $stableIdentity,
            'synthetic-direct-stable-002',
            '2026-08-10T14:00:00+00:00',
        );
        $stable = new OperationalSupplierOfferEvidenceBundle(
            evidenceFingerprint: $valid->evidenceFingerprint,
            supplierKey: $valid->supplierKey,
            supplierScope: ['apcom', 'backup-supplier'],
            policyVersions: $valid->policyVersions,
            sourceIdentity: $valid->sourceIdentity,
            freshnessPolicies: $valid->freshnessPolicies,
            snapshots: $stableSnapshots,
            productLifecycleEvidence: $valid->productLifecycleEvidence,
        );
        $this->assertSame($stableIdentity, $stable->snapshots[1]['source_identity']);
    }

    public function test_source_identity_uses_decoded_json_value_without_unicode_normalization(): void
    {
        $escaped = $this->fixtureArray();
        $escaped['source_identity'] = 'synthetic-A';
        $escaped['snapshots'][0]['source_identity'] = 'synthetic-A';
        $raw = json_encode($escaped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $raw = preg_replace(
            '/"source_identity": "synthetic-A"/',
            '"source_identity": "synthetic-\\u0041"',
            $raw,
            1,
        );
        $this->assertIsString($raw);
        $path = $this->writeRaw($raw);
        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
        $this->assertSame('synthetic-A', $bundle->sourceIdentity);
        $this->assertSame('synthetic-A', $bundle->snapshots[0]['source_identity']);

        $composed = "synthetic-caf\u{00E9}";
        $decomposed = "synthetic-cafe\u{0301}";
        $unicode = $this->fixtureArray();
        $unicode['source_identity'] = $composed;
        $unicode['snapshots'][0]['source_identity'] = $composed;
        $unicode['snapshots'][] = $this->snapshotForSupplier(
            $unicode['snapshots'][0],
            'apcom',
            $decomposed,
            'synthetic-apcom-unicode-002',
            '2026-08-10T13:00:00+00:00',
        );
        $this->assertSourceIdentityMismatch($unicode, [$composed, $decomposed]);
    }

    public function test_schema_supplier_hash_json_and_qualification_failures_are_rejected(): void
    {
        $base = $this->fixtureArray();
        $cases = [
            'unsupported_evidence_schema_version' => fn (array $data): array => array_replace($data, ['schema_version' => 'unsupported-v1']),
            'supplier_scope_mismatch' => fn (array $data): array => array_replace($data, ['supplier' => 'other']),
            'snapshot_fingerprint_missing' => function (array $data): array {
                $data['snapshots'][0]['fingerprint'] = '';

                return $data;
            },
            'missing_qualification_evidence' => function (array $data): array {
                unset($data['snapshots'][0]['successful']);

                return $data;
            },
        ];

        foreach ($cases as $expected => $mutator) {
            $path = $this->write($mutator($base));
            try {
                (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
                $this->fail("Expected {$expected}.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($expected === 'missing_qualification_evidence' ? 'snapshot_keys_invalid' : $expected, $exception->getMessage());
            }
        }

        $path = $this->write($base);
        $this->expectExceptionMessage('evidence_fingerprint_mismatch');
        (new OperationalSupplierOfferEvidenceBundleReader)->read($path, str_repeat('f', 64));
    }

    public function test_remote_stream_wrapper_malformed_json_and_invalid_times_are_rejected(): void
    {
        $reader = new OperationalSupplierOfferEvidenceBundleReader;
        foreach (['https://example.invalid/evidence.json', 'ftp://example.invalid/evidence.json', 'php://memory', 'file:///tmp/evidence.json'] as $source) {
            try {
                $reader->read($source, str_repeat('a', 64));
                $this->fail('Expected unsafe source rejection.');
            } catch (InvalidArgumentException $exception) {
                $this->assertContains($exception->getMessage(), ['stream_wrapper_evidence_source_rejected', 'invalid_local_evidence_source']);
            }
        }

        $malformed = $this->writeRaw('{not-json');
        try {
            $reader->read($malformed, hash_file('sha256', $malformed));
            $this->fail('Expected malformed JSON rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('malformed_evidence_json', $exception->getMessage());
        }

        foreach (['', '2026-08-12', '2026-08-12 12:00:00', '2026-13-12T12:00:00+00:00'] as $timestamp) {
            try {
                $reader->parseEvaluatedAt($timestamp);
                $this->fail('Expected invalid timestamp rejection.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('invalid_evaluated_at', $exception->getMessage());
            }
        }
    }

    public function test_unordered_snapshots_are_rejected_and_duplicate_fingerprints_remain_explicit_evidence(): void
    {
        $data = $this->fixtureArray();
        $second = $data['snapshots'][0];
        $second['snapshot_id'] = 'synthetic-apcom-002';
        $second['captured_at'] = '2026-08-10T11:00:00+00:00';
        $second['authoritative_snapshot_at'] = '2026-08-10T11:00:00+00:00';
        $data['snapshots'][] = $second;
        $unordered = $this->write($data);

        try {
            (new OperationalSupplierOfferEvidenceBundleReader)->read($unordered, hash_file('sha256', $unordered));
            $this->fail('Expected unordered snapshots to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('unordered_snapshots', $exception->getMessage());
        }

        $data = $this->fixtureArray();
        $second = $data['snapshots'][0];
        $second['snapshot_id'] = 'synthetic-apcom-002';
        $second['captured_at'] = '2026-08-10T13:00:00+00:00';
        $second['authoritative_snapshot_at'] = '2026-08-10T13:00:00+00:00';
        $data['snapshots'][] = $second;
        $duplicate = $this->write($data);
        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($duplicate, hash_file('sha256', $duplicate));

        $this->assertSame($bundle->snapshots[0]['fingerprint'], $bundle->snapshots[1]['fingerprint']);
    }

    public function test_observation_input_order_is_canonical_and_apcom_freshness_is_mandatory(): void
    {
        $first = $this->fixtureArray();
        $secondObservation = $first['snapshots'][0]['observations'][0];
        $secondObservation['supplier_sku_hash'] = str_repeat('b', 64);
        $first['snapshots'][0]['observations'][] = $secondObservation;
        $second = $first;
        $second['snapshots'][0]['observations'] = array_reverse($second['snapshots'][0]['observations']);

        $firstPath = $this->write($first);
        $secondPath = $this->write($second);
        $reader = new OperationalSupplierOfferEvidenceBundleReader;
        $firstBundle = $reader->read($firstPath, hash_file('sha256', $firstPath));
        $secondBundle = $reader->read($secondPath, hash_file('sha256', $secondPath));
        $this->assertSame($firstBundle->snapshots, $secondBundle->snapshots);

        $withoutFreshness = $first;
        $withoutFreshness['freshness_policies'] = [];
        $path = $this->write($withoutFreshness);
        try {
            $reader->read($path, hash_file('sha256', $path));
            $this->fail('Expected APCOM freshness policy rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('apcom_freshness_policy_mismatch', $exception->getMessage());
        }
    }

    public function test_reader_hashes_and_parses_one_bounded_handle_capture(): void
    {
        $multiChunk = $this->writeRaw(
            json_encode($this->fixtureArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .str_repeat(' ', 70_000),
        );
        $reader = new FaultInjectingEvidenceReader;
        $bundle = $reader->read($multiChunk, hash_file('sha256', $multiChunk));
        $this->assertSame(hash_file('sha256', $multiChunk), $bundle->evidenceFingerprint);
        $this->assertSame(1, $reader->openCount);
        $this->assertSame(1, $reader->closeCount);

        $oversized = $this->writeRaw(str_repeat(' ', 8_388_609));
        $this->assertReadFails($oversized, 'invalid_evidence_file_size');
    }

    public function test_local_file_type_identity_limits_and_cleanup_are_enforced_behaviorally(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'offer-evidence-dir-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;
        $this->assertReadFails($directory, 'invalid_local_evidence_source', str_repeat('a', 64));

        $target = $this->write($this->fixtureArray());
        $link = $target.'.link';
        if (@symlink($target, $link)) {
            $this->temporaryFiles[] = $link;
            $this->assertReadFails($link, 'invalid_local_evidence_source', hash_file('sha256', $target));
        }

        $json = json_encode($this->fixtureArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $exact = $this->writeRaw($json.str_repeat(' ', 8_388_608 - strlen($json)));
        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read($exact, hash_file('sha256', $exact));
        $this->assertSame(hash_file('sha256', $exact), $bundle->evidenceFingerprint);

        $over = $this->writeRaw($json.str_repeat(' ', 8_388_609 - strlen($json)));
        $this->assertReadFails($over, 'invalid_evidence_file_size');

        $loopGuard = new FaultInjectingEvidenceReader;
        $loopGuard->reportedSize = 8_388_608;
        $this->assertReaderFails($loopGuard, $over, 'invalid_evidence_file_size');
        $this->assertSame(1, $loopGuard->closeCount);
    }

    public function test_file_replacement_truncation_and_read_failures_close_the_open_handle(): void
    {
        $path = $this->write($this->fixtureArray());

        $identity = new FaultInjectingEvidenceReader;
        $identity->mismatchPathIdentity = true;
        $this->assertReaderFails($identity, $path, 'invalid_local_evidence_source');
        $this->assertSame(1, $identity->closeCount);

        $truncated = new FaultInjectingEvidenceReader;
        $truncated->reportedSize = filesize($path) + 1;
        $this->assertReaderFails($truncated, $path, 'evidence_file_changed_during_read');
        $this->assertSame(1, $truncated->closeCount);

        $readFailure = new FaultInjectingEvidenceReader;
        $readFailure->failReadOnCall = 1;
        $this->assertReaderFails($readFailure, $path, 'evidence_file_read_failed');
        $this->assertSame(1, $readFailure->closeCount);

        $this->assertReadFails($path, 'evidence_fingerprint_mismatch', str_repeat('f', 64));
    }

    public function test_duplicate_json_object_keys_are_rejected_at_every_scope(): void
    {
        $unicodeKey = json_decode('"\\uD83D\\uDE00"', true, 2, JSON_THROW_ON_ERROR);
        $documents = [
            '{"schema_version":"a","schema_version":"b"}',
            '{"outer":{"supplier":"a","supplier":"b"}}',
            '{"snapshots":[{"successful":true,"successful":false}]}',
            '{"snapshots":[{"observations":[{"present":true,"present":false}]}]}',
            '{"snapshots":[{"observations":[{"price":"1.00","price":"2.00"}]}]}',
            '{"present":true,"\\u0070resent":false}',
            '{"price":"1","pr\\u0069ce":"2"}',
            '{"a/b":1,"a\\/b":2}',
            '{"quote\"key":1,"quote\\u0022key":2}',
            '{"backslash\\\\key":1,"backslash\\u005ckey":2}',
        ];
        $documents[] = '{'.json_encode($unicodeKey, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            .':1,"\\uD83D\\uDE00":2}';

        foreach ($documents as $json) {
            $this->assertRawFails($json, 'duplicate_json_object_key', $json);
        }
    }

    public function test_duplicate_key_parser_allows_separate_scopes_arrays_and_key_like_strings(): void
    {
        foreach ([
            '{"left":{"present":true},"right":{"present":false}}',
            '{"values":["present","present"]}',
            '{"value":"\\\"present\\\": true, \\\"present\\\": false"}',
            '{"escaped":{"quote\"key":true,"backslash\\\\key":false}}',
        ] as $json) {
            $path = $this->writeRaw($json);
            try {
                (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
                $this->fail('Expected schema rejection after duplicate-key validation.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('duplicate_json_object_key', $exception->getMessage());
            }
        }

        $data = $this->fixtureArray();
        $data['supplier_scope'][] = 'apcom';
        $path = $this->write($data);
        $this->assertSame('apcom', (new OperationalSupplierOfferEvidenceBundleReader)->read(
            $path,
            hash_file('sha256', $path),
        )->supplierKey);
    }

    public function test_duplicate_key_parser_rejects_malformed_escapes_and_json(): void
    {
        foreach ([
            '{"value":"\\q"}',
            '{"value":"unterminated}',
            '{"value":1,}',
            '{"\\uD800":1}',
            '{"\\uDC00":1}',
            '{"\\uD800\\u0041":1}',
            '{"\\u12G4":1}',
            '{"value":true} trailing',
        ] as $json) {
            $this->assertRawFails($json, 'malformed_evidence_json');
        }
    }

    public function test_integer_fields_enforce_json_type_and_unsigned_database_bounds(): void
    {
        $valid = $this->fixtureArray();
        $valid['snapshots'][0]['product_count'] = 4_294_967_295;
        $valid['snapshots'][0]['minimum_product_count'] = 0;
        $valid['snapshots'][0]['observations'][0]['raw_quantity_observed'] = 4_294_967_295;
        $validPath = $this->write($valid);
        $snapshot = (new OperationalSupplierOfferEvidenceBundleReader)->read(
            $validPath,
            hash_file('sha256', $validPath),
        )->snapshots[0];
        $this->assertSame(4_294_967_295, $snapshot['product_count']);
        $this->assertSame(0, $snapshot['minimum_product_count']);
        $this->assertSame(4_294_967_295, $snapshot['observations'][0]['raw_quantity_observed']);

        $freshness = $this->fixtureArray();
        $freshness['supplier_scope'][] = 'backup-supplier';
        $freshness['freshness_policies'][] = [
            'supplier' => 'backup-supplier',
            'policy_key' => 'backup-supplier-approved-freshness-v1',
            'max_age_hours' => 8_760,
            'approved' => true,
        ];
        $freshnessPath = $this->write($freshness);
        $bundle = (new OperationalSupplierOfferEvidenceBundleReader)->read(
            $freshnessPath,
            hash_file('sha256', $freshnessPath),
        );
        $this->assertSame(8_760, $bundle->freshnessPolicies['backup-supplier']['max_age_hours']);

        $freshness['freshness_policies'][1]['max_age_hours'] = 8_761;
        $this->assertDataFails($freshness, 'invalid_freshness_policy_hours');

        foreach ([4_294_967_296, -1, '100'] as $invalid) {
            $data = $this->fixtureArray();
            $data['snapshots'][0]['product_count'] = $invalid;
            $this->assertDataFails($data, 'missing_qualification_evidence');
        }

        $eol = $this->fixtureArray();
        $eol['snapshots'][0]['observations'][0]['eol_flag'] = 2;
        $this->assertDataFails($eol, 'invalid_eol_flag');

        $raw = str_replace(
            '"product_count": 100',
            '"product_count": '.str_repeat('9', 100),
            file_get_contents($this->fixturePath()),
        );
        $this->assertRawFails($raw, 'missing_qualification_evidence');
    }

    public function test_decimal_fields_are_exact_bounded_and_never_float_coerced(): void
    {
        foreach (['40', '40.000001'] as $validDrop) {
            $data = $this->fixtureArray();
            $data['snapshots'][0]['product_drop_percent'] = $validDrop;
            $path = $this->write($data);
            $snapshot = (new OperationalSupplierOfferEvidenceBundleReader)->read(
                $path,
                hash_file('sha256', $path),
            )->snapshots[0];
            $this->assertSame($validDrop, $snapshot['product_drop_percent']);
        }

        foreach (['40.0000001', '40.0000000000000000001', ' 40', '+40', '040', '101'] as $invalidDrop) {
            $data = $this->fixtureArray();
            $data['snapshots'][0]['product_drop_percent'] = $invalidDrop;
            $this->assertDataFails($data, 'missing_qualification_evidence');
        }

        $float = $this->fixtureArray();
        $float['snapshots'][0]['product_drop_percent'] = 40.5;
        $this->assertDataFails($float, 'missing_qualification_evidence');

        $exponent = str_replace(
            '"product_drop_percent": 0',
            '"product_drop_percent": 4e1',
            file_get_contents($this->fixturePath()),
        );
        $this->assertRawFails($exponent, 'missing_qualification_evidence');

        $price = $this->fixtureArray();
        $price['snapshots'][0]['observations'][0]['price'] = '9999999999.99';
        $path = $this->write($price);
        $this->assertSame('9999999999.99', (new OperationalSupplierOfferEvidenceBundleReader)->read(
            $path,
            hash_file('sha256', $path),
        )->snapshots[0]['observations'][0]['price']);

        foreach (['10000000000.00', '1.001'] as $invalidPrice) {
            $price['snapshots'][0]['observations'][0]['price'] = $invalidPrice;
            $this->assertDataFails($price, 'invalid_offer_price');
        }
    }

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/Suppliers/apcom_offer_lifecycle/operational-evidence-v1.json');
    }

    /** @return array<string, mixed> */
    private function fixtureArray(): array
    {
        return json_decode(file_get_contents($this->fixturePath()), true, 128, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $prototype @return array<string, mixed> */
    private function snapshotForSupplier(
        array $prototype,
        string $supplier,
        string $sourceIdentity,
        string $snapshotId,
        string $capturedAt,
    ): array {
        return array_replace($prototype, [
            'authoritative_snapshot_at' => $capturedAt,
            'captured_at' => $capturedAt,
            'fingerprint' => hash('sha256', $snapshotId),
            'snapshot_id' => $snapshotId,
            'source_identity' => $sourceIdentity,
            'supplier' => $supplier,
        ]);
    }

    /** @param array<string, mixed> $data @param array<int, string> $identities */
    private function assertSourceIdentityMismatch(array $data, array $identities): void
    {
        try {
            $path = $this->write($data);
            (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
            $this->fail('Expected source identity drift to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('source_identity_mismatch', $exception->getMessage());

            foreach ($identities as $identity) {
                $this->assertStringNotContainsString($identity, $exception->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): string
    {
        return $this->writeRaw(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function writeRaw(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'offer-evidence-');
        if ($path === false) {
            $this->fail('Unable to create test evidence file.');
        }
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $data */
    private function assertDataFails(array $data, string $reason): void
    {
        $this->assertReadFails($this->write($data), $reason);
    }

    private function assertRawFails(string $contents, string $reason, string $context = ''): void
    {
        $this->assertReadFails($this->writeRaw($contents), $reason, null, $context);
    }

    private function assertReadFails(string $path, string $reason, ?string $expectedHash = null, string $context = ''): void
    {
        $this->assertReaderFails(new OperationalSupplierOfferEvidenceBundleReader, $path, $reason, $expectedHash, $context);
    }

    private function assertReaderFails(OperationalSupplierOfferEvidenceBundleReader $reader, string $path, string $reason, ?string $expectedHash = null, string $context = ''): void
    {
        try {
            $reader->read($path, $expectedHash ?? hash_file('sha256', $path));
            $this->fail("Expected {$reason}.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($reason, $exception->getMessage(), $context);
        }
    }
}

final class FaultInjectingEvidenceReader extends OperationalSupplierOfferEvidenceBundleReader
{
    public int $openCount = 0;

    public ?int $reportedSize = null;

    public bool $mismatchPathIdentity = false;

    public ?int $failReadOnCall = null;

    public int $closeCount = 0;

    private int $readCalls = 0;

    protected function openReadHandle(string $path): mixed
    {
        $this->openCount++;

        return parent::openReadHandle($path);
    }

    protected function handleStat(mixed $handle): array|false
    {
        $stat = parent::handleStat($handle);
        if (is_array($stat) && $this->reportedSize !== null) {
            $stat['size'] = $this->reportedSize;
        }

        return $stat;
    }

    protected function pathStat(string $path): array|false
    {
        $stat = parent::pathStat($path);
        if (! is_array($stat)) {
            return $stat;
        }
        if ($this->reportedSize !== null) {
            $stat['size'] = $this->reportedSize;
        }
        if ($this->mismatchPathIdentity) {
            $stat['ino'] = ((int) ($stat['ino'] ?? 0)) + 1;
        }

        return $stat;
    }

    protected function readChunk(mixed $handle, int $length): string|false
    {
        $this->readCalls++;
        if ($this->failReadOnCall === $this->readCalls) {
            return false;
        }

        return parent::readChunk($handle, $length);
    }

    protected function closeReadHandle(mixed $handle): void
    {
        $this->closeCount++;
        parent::closeReadHandle($handle);
    }
}
