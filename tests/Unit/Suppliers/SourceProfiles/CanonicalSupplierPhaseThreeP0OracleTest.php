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

        $this->assertCount(376, CanonicalSupplierPhaseThreeP0Oracle::signatures());
        $this->assertCount(18, CanonicalSupplierPhaseThreeP0Oracle::states());
        $this->assertCount(16, CanonicalSupplierPhaseThreeP0Oracle::operations());
        $this->assertSame(173874, strlen(CanonicalSupplierPhaseThreeP0Oracle::metadataBytes()));
        $this->assertSame(
            '14c7652e31e6eff49c722553f07582828118d92bd8b8c3694715740bfbaa91d7',
            hash(
                'sha256',
                CanonicalSupplierPhaseThreeP0Oracle::METADATA_VERSION
                    ."\0"
                    .CanonicalSupplierPhaseThreeP0Oracle::metadataBytes(),
            ),
        );
    }

    public function test_candidate_maps_match_independent_design_goldens_and_keep_each_successor_variant(): void
    {
        // Authored from frozen S/B_X plus the accepted design delta before PHP transcription.
        $expected = [
            'P0_BASELINE' => 'dd03ca3b3610207ea12afbd14373a62275e74c8cda50f72b9e129006192bc812',
            'P9' => '74071cc3a44b5c995d1164eded1ead2bb05b40b47fde6fc7935dcf794a7e66bd',
            'P9_DOWN_1' => '4bc9e218eba51e785454b97436f9fb55d2e7cd585c486499743c81e671bba69d',
            'P9_DOWN_2' => '2d56b8630560cef210e4f17636e497f5f5b7465158bfe50edb614d0363ce7b6b',
            'P8' => '2b4bc4e29959e536c354c80eee1e87e054f3abfa1c34955810b54ea2027a0755',
            'P8_DOWN_1' => 'df3bdedf29e9aded014b2d172926dd977bc372fbdb1c65089ac59b6ce8be8d68',
            'P8_DOWN_2' => 'b992f274eb7f13a26a44ed98eca879b40e2d3e30e45a55c1c0665630f2ef2ae3',
            'P7' => '765692fc06ba30ee87f27a7357edcb793927a52aef2375846a79257e5e8fcc83',
            'P7_DOWN_1' => '3f42d4567c985254acd7aaab903469c1035d870881adba431592abe6d509cd03',
            'P7_DOWN_2' => 'bf9efbbe90dc3add76109294b8de1801ce7a6e9af4684956415335a95485565a',
            'P7_DOWN_3' => '99b8610f13a307576198c2e1d77b5b903061d23ff82adbd57dae47ac703b7e00',
            'P6' => 'ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa',
            'P5' => '8cc5afed3a68c4152bf1456a10741c5e23bd077bf4dae39cae639208872ca56f',
            'P4' => '1fc28641da815cd4737b50b5b8ed065918b3f416f970ba2cccef82e5342e8d57',
            'P3' => '88e8c410ea052d66c6d8a920143f11fdf3ecca47f4c6c570afcecb16fce1cf2b',
            'P2' => 'af7261209342b681f587e145190ab217c67a14af4334f0c0e99241cfe8ad0169',
            'P1' => '0b857430da7679fd6692c1693ba1140e9826b68e497d23fc36abc81a7699e3bc',
            'P0' => 'dd03ca3b3610207ea12afbd14373a62275e74c8cda50f72b9e129006192bc812',
        ];
        $typeOrder = ['table', 'column', 'index', 'foreign_key', 'check', 'trigger'];
        foreach ($expected as $state => $hash) {
            $records = array_values(CanonicalSupplierPhaseThreeP0Oracle::signaturesFor($state));
            usort($records, static fn (array $a, array $b): int => array_search($a['signature']['type'], $typeOrder, true) <=> array_search($b['signature']['type'], $typeOrder, true)
                ?: strcmp($a['object_id'], $b['object_id']));
            $objects = array_map(static fn (array $r): array => ['id' => $r['object_id'], 'sha256' => hash('sha256', "phase-iii-p0-schema-signature-v1\0".json_encode($r['signature'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))], $records);
            $bytes = json_encode(['state' => $state === 'P0_BASELINE' ? 'P0' : $state, 'objects' => $objects], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $domain = str_contains($state, '_DOWN_') ? 'phase-iii-p0-partial-state-v1' : 'phase-iii-p0-prefix-v1';
            $this->assertSame($hash, hash('sha256', $domain."\0".$bytes), $state);
            $this->assertSame($hash, CanonicalSupplierPhaseThreeP0Oracle::state($state)['sha256']);
        }
        $changed = [
            0 => 'foreign_key:supplier_product_source_revisions:fk_supplier_product_revision_product_scope',
            1 => 'index:supplier_product_source_revisions:ix_supplier_product_revision_product_scope_fk',
            2 => 'index:supplier_products:uq_supplier_product_source_revision_scope',
            3 => 'trigger:supplier_products:trg_supplier_product_source_projection_guard',
            4 => 'trigger:supplier_products:trg_supplier_product_source_pointer_insert_guard',
        ];
        $frozenRemainders = [
            'P8' => '6e19d6a8923bfa9b3ea472321453ce21d26fcb1194dd2e1d9ea009a37f6f8e8b',
            'P8_DOWN_1' => '3aba457947731cca1492526e92eb2eb6c68b7570f44855c5e2096e37cb9712da',
            'P8_DOWN_2' => 'f062239ceb7336c65a3900cad98cbda041f34e4a901ceeb4ce230ead8f14c08a',
            'P9' => 'fccf7c8f9e77f191accafee5d0130fee935ec2fff2a1c01cda5c46305a79122a',
            'P9_DOWN_1' => '1c4f707f83b81f95cec2b8840fd231ca700bae32787404a52b49dd035a26e74e',
            'P9_DOWN_2' => 'dde7d4e8847c4520d66639c978e6e67de2a866ffeb704a009e947a2ac0bd1834',
        ];
        foreach ($frozenRemainders as $state => $hash) {
            $map = [];
            foreach (CanonicalSupplierPhaseThreeP0Oracle::signaturesFor($state) as $id => $record) {
                if (! in_array($id, $changed, true)) {
                    $map[$id] = $record['sha256'];
                }
            }
            ksort($map, SORT_STRING);
            $this->assertSame($hash, hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $state.' frozen B_X remainder');
        }
        $variant = 'check:supplier_offer_snapshot_generations:chk_snapshot_generation_qualification_tuple';
        $this->assertSame('08e50d9848873b9ded0bb0f449f4229120fcde4f6bdba08cdbd9e8adbb78046e', CanonicalSupplierPhaseThreeP0Oracle::signaturesFor('P8_DOWN_2')[$variant]['sha256']);
        $this->assertNotSame(CanonicalSupplierPhaseThreeP0Oracle::signaturesFor('P8')[$variant]['sha256'], CanonicalSupplierPhaseThreeP0Oracle::signaturesFor('P8_DOWN_2')[$variant]['sha256']);
    }

    public function test_candidate_has_four_exact_down_steps_and_strict_d1_d2_guards(): void
    {
        $expected = [
            'P0-07-DOWN-01' => [
                'precondition_state' => 'P7',
                'result_state' => 'P7_DOWN_1',
                'next_operation' => 'P0-07-DOWN-02',
                'sql_sha256' => 'fae83a8cc42a6c4622548fda5e48b5863e7ad3657ad76c5aa3868d4e17e44200',
            ],
            'P0-07-DOWN-02' => [
                'precondition_state' => 'P7_DOWN_1',
                'result_state' => 'P7_DOWN_2',
                'next_operation' => 'P0-07-DOWN-03',
                'sql_sha256' => '28d5d6f9a81299fe2c9d1e6515eadaa9a39be4aae142bab5910a2eebd5228983',
            ],
            'P0-07-DOWN-03' => [
                'precondition_state' => 'P7_DOWN_2',
                'result_state' => 'P7_DOWN_3',
                'next_operation' => 'P0-07-DOWN-04',
                'sql_sha256' => 'd2603aa14ecedc2b3cc33461a9b6bd2ebfc50864cb790e6d05d1f0f32e918e9a',
            ],
            'P0-07-DOWN-04' => [
                'precondition_state' => 'P7_DOWN_3',
                'result_state' => 'P6',
                'next_operation' => 'NONE_TARGET_PREFIX_VERIFICATION',
                'sql_sha256' => '09dab898d7998022683aedfadce79825d449623de2d136bd2c3106b70c86723c',
            ],
        ];
        foreach ($expected as $id => $golden) {
            $actual = CanonicalSupplierPhaseThreeP0Oracle::operation($id);
            foreach (['precondition_state', 'result_state', 'next_operation'] as $field) {
                $this->assertSame($golden[$field], $actual[$field]);
            }
            $this->assertSame($golden['sql_sha256'], hash('sha256', "phase-iii-p0-downgrade-sql-v1\0".$actual['sql']));
        }
        $p7 = CanonicalSupplierPhaseThreeP0Oracle::signaturesFor('P7');
        $reverse = $p7['foreign_key:supplier_product_source_revisions:fk_supplier_product_revision_product_scope']['signature'];
        $this->assertSame(['supplier_product_id', 'supplier_id', 'supplier_feed_id'], $reverse['child_columns']);
        $this->assertSame(['id', 'supplier_id', 'supplier_feed_id'], $reverse['parent_columns']);
        $this->assertSame(['current_source_revision_id', 'id', 'supplier_product_identity_head_id', 'supplier_id', 'supplier_feed_id'], $p7['foreign_key:supplier_products:fk_supplier_product_current_revision']['signature']['child_columns']);
        $body = $p7['trigger:supplier_products:trg_supplier_product_source_projection_guard']['signature']['action_statement'];
        $positions = [];
        foreach (['source pointers must be complete', 'identity scope is immutable', 'source revision cannot be cleared', 'Bound source pointer scope cannot be NULL', 'INNER JOIN', 'ELSEIF NEW.'] as $branch) {
            $position = strpos($body, $branch);
            $this->assertNotFalse($position, $branch);
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Ordered six-branch decision contract');
        foreach ([
            0 => 'supplier_sku',
            1 => 'ean',
            2 => 'mpn',
            3 => 'name',
            4 => 'brand_name',
            5 => 'category_name',
            6 => 'external_availability_status',
            7 => 'external_availability_label',
            8 => 'currency',
            9 => 'payload_hash',
        ] as $field) {
            $this->assertStringContainsString('NOT (CAST(OLD.`'.$field.'` AS BINARY) <=> CAST(NEW.`'.$field.'` AS BINARY))', $body);
        }
        foreach ([
            0 => 'price',
            1 => 'supplier_price_raw',
            2 => 'recommended_price',
            3 => 'quantity',
            4 => 'availability_status_id',
            5 => 'raw_data',
            6 => 'received_at',
        ] as $field) {
            $this->assertStringContainsString('NOT (OLD.`'.$field.'` <=> NEW.`'.$field.'`)', $body);
        }
        $this->assertStringContainsString('h.`supplier_sku_bytes` = CAST(NEW.`supplier_sku` AS BINARY)', $body);
        $this->assertSame('INSERT', $p7['trigger:supplier_products:trg_supplier_product_source_pointer_insert_guard']['signature']['event']);
    }

    public function test_p008_candidate_source_trigger_has_exact_five_field_update_authority(): void
    {
        // Independently authored contract delta; no DB/migration output supplies expected bytes.
        $expected = 'BEGIN
  IF OLD.`cohort_source_identity` IS NOT NULL
     AND NOT (
       (CAST(OLD.`cohort_authorization_version` AS BINARY) <=> CAST(NEW.`cohort_authorization_version` AS BINARY))
       AND (OLD.`cohort_authorized_at` <=> NEW.`cohort_authorized_at`)
       AND (OLD.`cohort_seed_count` <=> NEW.`cohort_seed_count`)
       AND (CAST(OLD.`cohort_seed_fingerprint` AS BINARY) <=> CAST(NEW.`cohort_seed_fingerprint` AS BINARY))
       AND (CAST(OLD.`cohort_source_identity` AS BINARY) <=> CAST(NEW.`cohort_source_identity` AS BINARY))
     ) THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Execution claim cohort source identity is immutable\';
  END IF;
  IF OLD.`cohort_source_identity` IS NULL
     AND NEW.`cohort_source_identity` IS NOT NULL
     AND NOT (
       OLD.`cohort_authorization_version` IS NULL
       AND OLD.`cohort_authorized_at` IS NULL
       AND OLD.`cohort_seed_count` IS NULL
       AND OLD.`cohort_seed_fingerprint` IS NULL
       AND NEW.`cohort_authorization_version` IS NOT NULL
       AND NEW.`cohort_authorized_at` IS NOT NULL
       AND NEW.`cohort_seed_count` IS NOT NULL
       AND NEW.`cohort_seed_fingerprint` IS NOT NULL
     ) THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Execution claim cohort source requires atomic authorization\';
  END IF;
END';
        $record = CanonicalSupplierPhaseThreeP0Oracle::signaturesFor('P8')['trigger:supplier_import_execution_claims:trg_import_execution_claim_cohort_source_immutable'];
        $this->assertSame($expected, $record['signature']['action_statement']);
        $this->assertSame('4becd5052c9bca3c39fd4e3b6423fa08828d9f289a24a40e85ade13018fa6a66', $record['sha256']);
        $this->assertSame('UPDATE', $record['signature']['event']);
        $this->assertSame(['P9', 'P9_DOWN_1', 'P9_DOWN_2', 'P8'], $record['memberships']);
        foreach (['state', 'active_attempt_token_hash', 'terminalized_at'] as $field) {
            $this->assertStringNotContainsString('OLD.'.chr(96).$field.chr(96), $expected);
        }
    }

    public function test_p008_candidate_executable_sql_bytes_are_frozen_separately_from_metadata(): void
    {
        // Fixed byte goldens from the independent SQL specification, not Oracle output.
        $expected = [
            'P0-08-DOWN-02' => [1526, '0de42fd713379a86203b735d0770590897a4e455a2944f7f2ae56891661f06a8', '20ab6b7e5fd3132aa9e439be7ffcdc639796030de262c2210ba39982bc475d6e'],
            'P0-08-DOWN-03' => [1685, 'd0c59adde64aee5a187d42821b37f5e9e70b1fe5d50c1b163c4d7be2f1dfbb4d', '77dea316cf5f6c7af72b533620b2bb1e636bdb744964ca6065ba57c2467c19bf'],
        ];
        foreach ($expected as $id => [$length, $rawHash, $domainHash]) {
            $sql = CanonicalSupplierPhaseThreeP0Oracle::operation($id)['sql'];
            $this->assertSame($length, strlen($sql));
            $this->assertSame($rawHash, hash('sha256', $sql));
            $this->assertSame($domainHash, hash('sha256', "phase-iii-p0-downgrade-sql-v1\0".$sql));
        }
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

        foreach (['P9_DOWN_1', 'P9_DOWN_2', 'P8_DOWN_1', 'P8_DOWN_2', 'P7_DOWN_1', 'P7_DOWN_2', 'P7_DOWN_3'] as $state) {
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
