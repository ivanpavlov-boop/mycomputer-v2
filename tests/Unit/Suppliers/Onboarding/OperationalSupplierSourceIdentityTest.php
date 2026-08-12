<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundle;
use App\Data\Suppliers\Onboarding\OperationalSupplierSourceIdentity;
use App\Data\Suppliers\Onboarding\OperationalSupplierSourceIdentityMap;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundleReader;
use InvalidArgumentException;
use JsonException;
use Tests\TestCase;

final class OperationalSupplierSourceIdentityTest extends TestCase
{
    public function test_shared_validator_rejects_invalid_values_and_preserves_valid_values_exactly(): void
    {
        foreach ($this->invalidIdentities() as $identity) {
            $this->assertInvalidSourceIdentity(
                static fn (): string => OperationalSupplierSourceIdentity::validate($identity),
            );
        }

        foreach ([
            str_repeat('x', 128),
            str_repeat("\u{00E9}", 128),
            ' Stable Exact Identity ',
            "\u{00A0}Stable Exact Identity\u{2003}",
            'IDENTITY',
        ] as $identity) {
            $this->assertSame($identity, OperationalSupplierSourceIdentity::validate($identity));
        }
    }

    /** @throws JsonException */
    public function test_reader_and_direct_dto_share_equivalent_validation_for_json_representable_values(): void
    {
        $valid = $this->validBundle();

        foreach (array_slice($this->invalidIdentities(), 0, -1) as $identity) {
            $this->assertInvalidSourceIdentity(fn (): OperationalSupplierOfferEvidenceBundle => $this->directBundle(
                $valid,
                $identity,
            ));
            $this->assertInvalidSourceIdentity(fn (): OperationalSupplierOfferEvidenceBundle => $this->readIdentity(
                $identity,
            ));
        }

        foreach ([
            str_repeat('x', 128),
            str_repeat("\u{00E9}", 128),
            "\u{00A0} Stable Exact Identity \u{2003}",
        ] as $identity) {
            $direct = $this->directBundle($valid, $identity);
            $read = $this->readIdentity($identity);

            $this->assertSame($identity, $direct->sourceIdentity);
            $this->assertSame($identity, $read->sourceIdentity);
        }
    }

    public function test_direct_dto_rejects_invalid_utf8_and_exact_drift_in_every_snapshot_state(): void
    {
        $valid = $this->validBundle();
        $this->assertInvalidSourceIdentity(fn (): OperationalSupplierOfferEvidenceBundle => $this->directBundle(
            $valid,
            "\xC3\x28",
        ));

        foreach ([
            ['identity', 'IDENTITY'],
            ['identity', 'identity '],
            ['identity', ' identity'],
            ["caf\u{00E9}", "cafe\u{0301}"],
        ] as [$baseline, $drift]) {
            foreach ([false, true] as $reordered) {
                $snapshots = $valid->snapshots;
                $snapshots[0]['source_identity'] = $baseline;
                $drifted = $snapshots[0];
                $drifted['snapshot_id'] = 'direct-drift-'.hash('sha256', $baseline.$drift);
                $drifted['fingerprint'] = hash('sha256', $drifted['snapshot_id']);
                $drifted['source_identity'] = $drift;
                $drifted['successful'] = false;
                $drifted['status'] = 'failed';
                $snapshots[] = $drifted;
                if ($reordered) {
                    $snapshots = array_reverse($snapshots);
                }

                $this->assertSourceIdentityMismatch(fn (): OperationalSupplierOfferEvidenceBundle => $this->directBundle(
                    $valid,
                    $baseline,
                    $snapshots,
                ));
            }
        }
    }

    public function test_invalid_identity_cannot_become_a_map_baseline(): void
    {
        foreach ($this->invalidIdentities() as $identity) {
            $this->assertInvalidSourceIdentity(
                static fn (): OperationalSupplierSourceIdentityMap => new OperationalSupplierSourceIdentityMap(
                    'apcom',
                    $identity,
                ),
            );
        }

        $map = new OperationalSupplierSourceIdentityMap('apcom', 'stable-primary');
        $this->assertInvalidSourceIdentity(static function () use ($map): void {
            $map->observe('backup-supplier', ' ');
        });

        $map->observe('backup-supplier', ' Stable Backup ');
        $map->observe('backup-supplier', ' Stable Backup ');
        $this->addToAssertionCount(1);
        $this->assertSourceIdentityMismatch(static function () use ($map): void {
            $map->observe('backup-supplier', 'stable backup');
        });
    }

    /** @return array<int, mixed> */
    private function invalidIdentities(): array
    {
        return [
            '',
            ' ',
            "\t\r\n",
            "\u{00A0}",
            "\u{2002}",
            "\u{2003}",
            " \t\u{00A0}\u{2002}\u{2003}\r\n",
            str_repeat('x', 129),
            str_repeat("\u{00E9}", 129),
            ['not-a-string'],
            "\xC3\x28",
        ];
    }

    private function validBundle(): OperationalSupplierOfferEvidenceBundle
    {
        $path = base_path('tests/Fixtures/Suppliers/apcom_offer_lifecycle/operational-evidence-v1.json');

        return (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
    }

    /** @param array<int, array<string, mixed>>|null $snapshots */
    private function directBundle(
        OperationalSupplierOfferEvidenceBundle $valid,
        mixed $identity,
        ?array $snapshots = null,
    ): OperationalSupplierOfferEvidenceBundle {
        $snapshots ??= array_map(static function (array $snapshot) use ($valid, $identity): array {
            if (($snapshot['supplier'] ?? null) === $valid->supplierKey) {
                $snapshot['source_identity'] = $identity;
            }

            return $snapshot;
        }, $valid->snapshots);

        return new OperationalSupplierOfferEvidenceBundle(
            evidenceFingerprint: $valid->evidenceFingerprint,
            supplierKey: $valid->supplierKey,
            supplierScope: $valid->supplierScope,
            policyVersions: $valid->policyVersions,
            sourceIdentity: $identity,
            freshnessPolicies: $valid->freshnessPolicies,
            snapshots: $snapshots,
            productLifecycleEvidence: $valid->productLifecycleEvidence,
        );
    }

    /** @throws JsonException */
    private function readIdentity(mixed $identity): OperationalSupplierOfferEvidenceBundle
    {
        $fixture = base_path('tests/Fixtures/Suppliers/apcom_offer_lifecycle/operational-evidence-v1.json');
        $data = json_decode(file_get_contents($fixture), true, 128, JSON_THROW_ON_ERROR);
        $data['source_identity'] = $identity;
        foreach ($data['snapshots'] as &$snapshot) {
            if (($snapshot['supplier'] ?? null) === 'apcom') {
                $snapshot['source_identity'] = $identity;
            }
        }
        unset($snapshot);

        $path = tempnam(sys_get_temp_dir(), 'source-identity-parity-');
        if ($path === false) {
            $this->fail('Unable to create source identity parity evidence.');
        }

        try {
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return (new OperationalSupplierOfferEvidenceBundleReader)->read($path, hash_file('sha256', $path));
        } finally {
            @unlink($path);
        }
    }

    private function assertInvalidSourceIdentity(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected invalid_source_identity.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid_source_identity', $exception->getMessage());
        }
    }

    private function assertSourceIdentityMismatch(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected source_identity_mismatch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('source_identity_mismatch', $exception->getMessage());
        }
    }
}
