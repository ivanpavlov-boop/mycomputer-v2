<?php

namespace Tests\Unit\Suppliers\SourceProfiles;

use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0ConnectionOutcome;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0NamedLockResult;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Oracle;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaComparator;
use PDOException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../../database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php';
require_once __DIR__.'/../../../../database/migrations/support/CanonicalSupplierPhaseThreeP0ConnectionOutcome.php';
require_once __DIR__.'/../../../../database/migrations/support/CanonicalSupplierPhaseThreeP0NamedLockResult.php';
require_once __DIR__.'/../../../../database/migrations/support/CanonicalSupplierPhaseThreeP0SchemaComparator.php';

final class CanonicalSupplierPhaseThreeP0OracleTest extends TestCase
{
    public function test_generated_oracle_has_the_exact_frozen_cardinality_and_metadata_digest(): void
    {
        CanonicalSupplierPhaseThreeP0Oracle::assertIntegrity();

        $this->assertCount(375, CanonicalSupplierPhaseThreeP0Oracle::signatures());
        $this->assertCount(17, CanonicalSupplierPhaseThreeP0Oracle::states());
        $this->assertCount(15, CanonicalSupplierPhaseThreeP0Oracle::operations());
        $this->assertSame(168474, strlen(CanonicalSupplierPhaseThreeP0Oracle::metadataBytes()));
        $this->assertSame(
            '0ca5b057d4733cb791d791bbf6113e8e7f3a678ffdd61a7c11ca36306023def6',
            hash(
                'sha256',
                CanonicalSupplierPhaseThreeP0Oracle::METADATA_VERSION
                    ."\0"
                    .CanonicalSupplierPhaseThreeP0Oracle::metadataBytes(),
            ),
        );
    }

    public function test_matching_utf8mb4_schema_defaults_normalize_to_one_canonical_trigger_identity(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;
        $canonical = $comparator->expectedSignatures('P2');

        foreach (['utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_general_ci'] as $collation) {
            $raw = $this->observedSignatures('P2', $collation);
            $preservedRaw = $raw;
            $normalized = $comparator->normalizeObservedSignatures($raw, 'utf8mb4', $collation);

            $this->assertSame($preservedRaw, $raw, "Raw {$collation} inspection evidence was mutated.");
            $this->assertSame($canonical, $normalized, "{$collation} did not converge to canonical identity.");
            $this->assertSame('P2', $comparator->classifyObserved($raw, 'utf8mb4', $collation)['state']);
        }
    }

    public function test_trigger_database_collation_attestation_rejects_missing_null_empty_mismatched_or_non_utf8mb4_evidence(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;
        $exact = $this->observedSignatures('P2', 'utf8mb4_unicode_ci');
        $triggerIndex = $this->firstTriggerIndex($exact);
        $missing = $exact;
        unset($missing[$triggerIndex]['database_collation']);
        $null = $exact;
        $null[$triggerIndex]['database_collation'] = null;
        $empty = $exact;
        $empty[$triggerIndex]['database_collation'] = '';
        $mismatch = $exact;
        $mismatch[$triggerIndex]['database_collation'] = 'utf8mb4_general_ci';
        $canonicalTokenIsNotRawEvidence = $comparator->expectedSignatures('P2');
        $latin1 = $this->observedSignatures('P2', 'latin1_swedish_ci');

        foreach ([
            'missing field' => [$missing, 'utf8mb4', 'utf8mb4_unicode_ci'],
            'null field' => [$null, 'utf8mb4', 'utf8mb4_unicode_ci'],
            'empty field' => [$empty, 'utf8mb4', 'utf8mb4_unicode_ci'],
            'mismatched default' => [$mismatch, 'utf8mb4', 'utf8mb4_unicode_ci'],
            'canonical token supplied as raw evidence' => [$canonicalTokenIsNotRawEvidence, 'utf8mb4', 'utf8mb4_unicode_ci'],
            'non-utf8mb4 schema' => [$latin1, 'latin1', 'latin1_swedish_ci'],
            'null schema default' => [$exact, 'utf8mb4', null],
            'empty schema default' => [$exact, 'utf8mb4', ''],
        ] as $case => [$raw, $charset, $defaultCollation]) {
            $this->assertUnclassified(
                $comparator->classifyObserved($raw, $charset, $defaultCollation),
                $case,
            );
        }
    }

    public function test_observed_normalization_cannot_hide_trigger_context_or_closed_world_mutations(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;
        $exact = $this->observedSignatures('P2', 'utf8mb4_unicode_ci');
        $triggerIndex = $this->firstTriggerIndex($exact);

        foreach ([
            'action_statement' => ' ',
            'sql_mode' => ',ANSI',
            'character_set_client' => '_mutated',
            'collation_connection' => '_mutated',
        ] as $field => $suffix) {
            $mutated = $exact;
            $mutated[$triggerIndex][$field] .= $suffix;
            $this->assertUnclassified(
                $comparator->classifyObserved($mutated, 'utf8mb4', 'utf8mb4_unicode_ci'),
                $field,
            );
        }

        $missing = $exact;
        array_pop($missing);
        $this->assertUnclassified(
            $comparator->classifyObserved($missing, 'utf8mb4', 'utf8mb4_unicode_ci'),
            'missing object',
        );

        $extra = $exact;
        $extra[] = [
            'type' => 'table',
            'table' => 'unexpected_table',
            'table_type' => 'BASE TABLE',
            'engine' => 'InnoDB',
            'row_format' => 'Dynamic',
            'table_collation' => 'utf8mb4_unicode_ci',
            'create_options' => '',
            'table_comment' => '',
        ];
        $this->assertUnclassified(
            $comparator->classifyObserved($extra, 'utf8mb4', 'utf8mb4_unicode_ci'),
            'extra object',
        );

        $structural = $exact;
        foreach ($structural as &$signature) {
            if ($signature['type'] === 'table') {
                $signature['engine'] = 'MEMORY';
                break;
            }
        }
        unset($signature);
        $this->assertUnclassified(
            $comparator->classifyObserved($structural, 'utf8mb4', 'utf8mb4_unicode_ci'),
            'structural mutation',
        );
    }

    public function test_every_declared_state_is_recognized_only_by_exact_closed_world_equality(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;

        foreach (CanonicalSupplierPhaseThreeP0Oracle::states() as $expected) {
            if ($expected['state'] === 'P0_BASELINE') {
                continue;
            }

            $actual = $comparator->classify($comparator->expectedSignatures($expected['state']));

            $this->assertSame($expected['state'], $actual['state']);
            $this->assertSame($expected['classification'], $actual['classification']);
            $this->assertSame($expected['sha256'], $actual['sha256']);
            $this->assertSame($expected['object_count'], $actual['object_count']);
        }
    }

    public function test_future_recognized_partial_states_are_classified_but_not_treated_as_prefixes(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;

        foreach (['P9_DOWN_1', 'P9_DOWN_2', 'P8_DOWN_1', 'P8_DOWN_2', 'P7_DOWN_1', 'P7_DOWN_2'] as $state) {
            $this->assertSame(
                $state,
                $comparator->classify($comparator->expectedSignatures($state))['state'],
            );
        }
    }

    public function test_missing_extra_duplicate_reordered_or_mutated_objects_fail_closed(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;
        $exact = $comparator->expectedSignatures('P2');

        $missing = $exact;
        array_pop($missing);
        $this->assertUnclassified($comparator->classify($missing));

        $extra = $exact;
        $extra[] = [
            'type' => 'table',
            'table' => 'unexpected_table',
            'table_type' => 'BASE TABLE',
            'engine' => 'InnoDB',
            'row_format' => 'Dynamic',
            'table_collation' => 'utf8mb4_unicode_ci',
            'create_options' => '',
            'table_comment' => '',
        ];
        $this->assertUnclassified($comparator->classify($extra));

        $duplicate = $exact;
        $duplicate[] = $exact[0];
        $this->assertUnclassified($comparator->classify($duplicate));

        $reorderedFields = $exact;
        $reorderedFields[0] = array_reverse($reorderedFields[0], true);
        $this->assertUnclassified($comparator->classify($reorderedFields));

        $mutated = $exact;
        foreach ($mutated as &$signature) {
            if ($signature['type'] === 'table') {
                $signature['engine'] = 'MEMORY';
                break;
            }
        }
        unset($signature);
        $this->assertUnclassified($comparator->classify($mutated));
    }

    public function test_every_required_schema_mutation_dimension_fails_closed(): void
    {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;

        $this->assertMutationUnclassified('column', function (array $signature): array {
            $signature['column_type'] = 'varchar(127)';

            return $signature;
        });
        $this->assertMutationUnclassified('column', function (array $signature): array {
            $signature['nullable'] = ! $signature['nullable'];

            return $signature;
        });
        $this->assertMutationUnclassified('column', function (array $signature): array {
            $signature['default_kind'] = 'LITERAL';
            $signature['default_value'] = 'NULL';

            return $signature;
        });
        $this->assertMutationUnclassified('column', function (array $signature): array {
            unset($signature['default_value']);

            return $signature;
        });
        $this->assertMutationUnclassified('index', function (array $signature): array {
            if (count($signature['parts']) < 2) {
                return $signature;
            }

            $signature['parts'] = array_reverse($signature['parts']);

            return $signature;
        }, requireMultipleParts: true);
        $this->assertMutationUnclassified('foreign_key', function (array $signature): array {
            $signature['child_columns'] = array_reverse($signature['child_columns']);

            return $signature;
        }, requireMultipleParts: true);
        $this->assertMutationUnclassified('foreign_key', function (array $signature): array {
            $signature['delete_rule'] = $signature['delete_rule'] === 'RESTRICT' ? 'CASCADE' : 'RESTRICT';

            return $signature;
        });
        $this->assertMutationUnclassified('check', function (array $signature): array {
            $signature['clause'] .= ' ';

            return $signature;
        });
        $this->assertMutationUnclassified('trigger', function (array $signature): array {
            $signature['action_statement'] .= ' ';

            return $signature;
        });

        $this->assertSame('P2', $comparator->classify($comparator->expectedSignatures('P2'))['state']);
    }

    public function test_named_lock_and_connection_results_preserve_exact_mysql_distinctions(): void
    {
        $this->assertSame('ACQUIRED', CanonicalSupplierPhaseThreeP0NamedLockResult::acquisition(1));
        $this->assertSame('UNAVAILABLE', CanonicalSupplierPhaseThreeP0NamedLockResult::acquisition(0));
        $this->assertSame('UNAVAILABLE', CanonicalSupplierPhaseThreeP0NamedLockResult::acquisition(null));
        $this->assertSame('UNAVAILABLE', CanonicalSupplierPhaseThreeP0NamedLockResult::acquisition('1'));
        $this->assertSame('RELEASED', CanonicalSupplierPhaseThreeP0NamedLockResult::release(1));
        $this->assertSame('NOT_OWNED', CanonicalSupplierPhaseThreeP0NamedLockResult::release(0));
        $this->assertSame('UNAVAILABLE', CanonicalSupplierPhaseThreeP0NamedLockResult::release(null));
        $this->assertSame('UNAVAILABLE', CanonicalSupplierPhaseThreeP0NamedLockResult::release('0'));

        $uncertain = new PDOException('connection lost');
        $uncertain->errorInfo = ['08006', 2006, 'diagnostic'];
        $ordinary = new PDOException('constraint failure');
        $ordinary->errorInfo = ['23000', 1062, 'diagnostic'];
        $this->assertTrue(CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($uncertain));
        $this->assertFalse(CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($ordinary));
    }

    public function test_implemented_p0_down_operations_are_the_exact_single_statement_contracts(): void
    {
        $this->assertSame(
            'DROP TABLE `supplier_import_source_executions`',
            CanonicalSupplierPhaseThreeP0Oracle::operation('P0-03-DOWN-01')['sql'],
        );
        $this->assertSame(
            'DROP TABLE `supplier_import_source_profiles`',
            CanonicalSupplierPhaseThreeP0Oracle::operation('P0-02-DOWN-01')['sql'],
        );
        $this->assertSame(
            'ALTER TABLE `supplier_feeds` DROP INDEX `uq_supplier_feed_id_supplier`',
            CanonicalSupplierPhaseThreeP0Oracle::operation('P0-01-DOWN-01')['sql'],
        );
    }

    /** @param array{state: string, classification: string, sha256: ?string, object_count: int} $result */
    private function assertUnclassified(array $result, string $case = ''): void
    {
        $this->assertSame(
            CanonicalSupplierPhaseThreeP0SchemaComparator::UNCLASSIFIED_STATE,
            $result['state'],
            $case,
        );
        $this->assertNull($result['sha256'], $case);
    }

    /** @return list<array<string, mixed>> */
    private function observedSignatures(string $state, string $databaseCollation): array
    {
        $signatures = (new CanonicalSupplierPhaseThreeP0SchemaComparator)->expectedSignatures($state);

        foreach ($signatures as &$signature) {
            if ($signature['type'] === 'trigger') {
                $signature['database_collation'] = $databaseCollation;
            }
        }
        unset($signature);

        return $signatures;
    }

    /** @param list<array<string, mixed>> $signatures */
    private function firstTriggerIndex(array $signatures): int
    {
        foreach ($signatures as $index => $signature) {
            if (($signature['type'] ?? null) === 'trigger') {
                return $index;
            }
        }

        $this->fail('No trigger signature was available.');
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutation */
    private function assertMutationUnclassified(
        string $type,
        callable $mutation,
        bool $requireMultipleParts = false,
    ): void {
        $comparator = new CanonicalSupplierPhaseThreeP0SchemaComparator;
        $signatures = $comparator->expectedSignatures('P2');
        $mutated = false;

        foreach ($signatures as &$signature) {
            if ($signature['type'] !== $type) {
                continue;
            }

            $parts = $signature['parts'] ?? $signature['child_columns'] ?? [];
            if ($requireMultipleParts && count($parts) < 2) {
                continue;
            }

            $signature = $mutation($signature);
            $mutated = true;
            break;
        }
        unset($signature);

        $this->assertTrue($mutated, "No {$type} signature was available for mutation.");
        $this->assertUnclassified($comparator->classify($signatures));
    }
}
