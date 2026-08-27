<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierOfferLifecycleDocumentationContractTest extends TestCase
{
    public function test_policy_documents_record_the_preview_only_lifecycle_contract(): void
    {
        $missing = file_get_contents(base_path('docs/SUPPLIER_OFFER_MISSING_LIFECYCLE_POLICY.md'));
        $visibility = file_get_contents(base_path('docs/CATALOG_PRODUCT_VISIBILITY_ARCHIVAL_POLICY.md'));
        $retention = file_get_contents(base_path('docs/SUPPLIER_TECHNICAL_RETENTION_POLICY.md'));
        $apcom = file_get_contents(base_path('docs/APCOM_MISSING_OFFER_DECISIONS_V4.md'));

        $this->assertIsString($missing);
        $this->assertIsString($visibility);
        $this->assertIsString($retention);
        $this->assertIsString($apcom);

        $apcom = preg_replace('/\s+/', ' ', $apcom);
        $this->assertIsString($apcom);

        foreach (['three consecutive qualified snapshots', '48-Hour Duration', 'At least 48 elapsed', 'cannot deactivate or', 'Source absence never means EOL', 'No automatic product deletion, soft deletion, product unpublish, supplier link'] as $needle) {
            $this->assertStringContainsString($needle, $missing);
        }
        foreach (['Multi-Supplier Aggregation', 'direct product page', 'remains HTTP 200', 'noindex, follow', 'sitemap', 'cold_archive_candidate', 'No Automatic Product Deletion'] as $needle) {
            $this->assertStringContainsString($needle, $visibility);
        }
        foreach (['90 days', '24 months', 'Indefinite', 'No Cleanup Execution In This Phase'] as $needle) {
            $this->assertStringContainsString($needle, $retention);
        }
        foreach (['APCOM Missing Offer Decisions V4', 'current documentation-only decision-closure register', 'APCOM-STAGING-ONLY-001', 'remains confirmed and unchanged', 'Cart maximum quantity remains outside this phase', 'blocked_pending_implementation_approvals', 'Merging this document does not authorize implementation', 'No database or queue mutation is authorized'] as $needle) {
            $this->assertStringContainsString($needle, $apcom);
        }
    }

    public function test_v4_closes_only_the_approved_decisions_without_authorizing_mutation(): void
    {
        $apcom = file_get_contents(base_path('docs/APCOM_MISSING_OFFER_DECISIONS_V4.md'));

        $this->assertIsString($apcom);

        $apcom = preg_replace('/\s+/', ' ', $apcom);
        $this->assertIsString($apcom);

        foreach (['APCOM-SOURCE-ONLY-001', 'APCOM-MPN-001', 'APCOM-ZERO-PRICE-001', 'APCOM-SNAPSHOT-FRESHNESS-001'] as $id) {
            $this->assertStringContainsString("`{$id}` - Approved", $apcom);
        }

        foreach (['classified as `potential_create`', 'visible only as a candidate in preview', 'manually selected and explicitly confirmed', 'must re-check eligibility, permissions', '`CATALOG_SYNC_CREATE_ENABLED`'] as $needle) {
            $this->assertStringContainsString($needle, $apcom);
        }

        foreach (['`partno` is supplier SKU only', 'must not automatically be treated as manufacturer MPN', 'MPN remains empty', 'must not be inferred from EAN', 'classified as `manual_review`'] as $needle) {
            $this->assertStringContainsString($needle, $apcom);
        }

        foreach (['`fd_price = 0` does not mean that the Product is free', 'must not set a catalog or selling price to zero', 'excluded from valid commercial-offer selection and price calculations', 'must not increment the missing counter'] as $needle) {
            $this->assertStringContainsString($needle, $apcom);
        }

        foreach (['up to and including 24 hours', '`age <= 24 hours`', '`age > 24 hours`', 'authoritative evidence-snapshot timestamp', '`received_at` and `last_seen_at` are not substitutes', 'APCOM-specific'] as $needle) {
            $this->assertStringContainsString($needle, $apcom);
        }

        foreach (['A present zero-price offer and a stale offer', 'neither counts as missing', 'None of these states authorizes automatic catalog mutation', 'implementation gate remains', 'separate explicit implementation request', 'There is no execution authorization'] as $needle) {
            $this->assertStringContainsString($needle, $apcom);
        }
    }

    public function test_current_c3d_references_point_to_v4_while_v3_remains_preserved(): void
    {
        $v3 = file_get_contents(base_path('docs/APCOM_MISSING_OFFER_DECISIONS_V3.md'));
        $documents = [
            'docs/APCOM_HUMAN_DECISION_REGISTER.md',
            'docs/APCOM_PREVIEW_ONLY_FEED_PROFILE_DESIGN.md',
            'docs/CATALOG_SYNC_SAFETY.md',
            'docs/SUPPLIER_INTEGRATION_INVENTORY.md',
            'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md',
        ];

        $this->assertIsString($v3);
        $this->assertStringContainsString('APCOM-SOURCE-ONLY-001` remains pending', $v3);

        foreach ($documents as $document) {
            $contents = file_get_contents(base_path($document));

            $this->assertIsString($contents);
            $this->assertStringContainsString('APCOM_MISSING_OFFER_DECISIONS_V4.md', $contents);
        }
    }

    public function test_c3d_snapshot_recovery_design_closes_aggregate_review_contracts(): void
    {
        $design = file_get_contents(base_path('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md'));
        $documents = [
            'docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md',
            'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md',
            'docs/PHASES.md',
            'docs/ROADMAP.md',
            'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md',
        ];

        $this->assertIsString($design);
        $normalizedDesign = preg_replace('/\s+/', ' ', $design);
        $this->assertIsString($normalizedDesign);
        $this->assertStringContainsString('dispatch_durable_progress_stalled', $design);
        $this->assertStringNotContainsString('dispatch_payload_unobserved', $design);
        $this->assertStringContainsString('supplier-import-dispatch-recovery-resume-v1', $design);
        $this->assertStringContainsString('Phase B0, committed-start resume validation', $design);
        $this->assertStringContainsString('Phase B1, physical-attempt reservation', $design);
        $this->assertStringContainsString('expected_state_fingerprint_v2', $design);
        $this->assertStringContainsString('mycomputer:supplier-recovery-expected-state:v2', $design);
        $this->assertStringNotContainsString('supplier-import-dispatch-recovery-state-v1', $design);
        $this->assertStringNotContainsString('19-field', $design);

        foreach ([
            'republish_same_key',
            'recover_expired_queued_ownership',
            'terminalize_stale_dispatch',
            'terminalize_publication_mismatch',
            'terminalize_abandoned_processing',
            'authenticated Filament action',
            'uq_import_recovery_auth_complete_tuple',
            'fk_import_recovery_result_complete_auth_tuple',
            'mycomputer:supplier-dispatch-payload:v1',
            'd2a1b00c8b6d70393fdd65b246daa6e7e0c3cbba7c4ac1ff13fa38e9e34d59d0',
            '471b08a6da920cc82c9612f15fa812546ffa32daf1a8d499eaadecf3d9a2334e',
            'supplier_import_dispatch_monitor_health',
            'supplier_import_dispatch_alert_intents',
            'SupplierImportDispatchMonitorGate',
            'supplier-import-dispatch-observer-v1',
            'suppliers:observe-import-dispatch-monitor-health --quiet',
            'chk_import_recovery_result_action_event_code',
            'action_stopped/republish_response_window_expired_after_start',
            'ownership_recovery_succeeded/queued_ownership_lease_expired',
            'supplier-import-dispatch-monitor-alert-v1',
            'supplier-import-dispatch-alert-v1',
            '0784419b016bd71a2ad912c752ab64d5405899f261a22fa78c75f5a300002fe0',
            'a4cfd7d96ada0678b7054d3bfe2f62a1b423a98bb9507ce7e664a9c549b14f31',
            'uq_import_dispatch_monitor_identity',
            'chk_import_dispatch_monitor_owner_tuple',
            'uq_import_dispatch_alert_identity',
            'fk_import_dispatch_alert_outbox',
            'chk_import_dispatch_alert_state_tuple',
            'delivery_outcome_unknown_exhausted',
            'publication_attempt_generation',
            'publication_attempt_state',
            'publication_attempt_token_hash',
            'publication_attempt_reserved_at',
            'publication_attempt_lease_expires_at',
            'publication_external_fence_installed_at',
            'publication_call_boundary_at',
            'publication_attempt_resolved_at',
            'supplier_import_advance_fence_v1',
            'supplier_import_publish_fenced_v1',
            'supplier_import_retire_fence_v1',
            'supplier-import:dispatch-fence:v1:{<logical_execution_key>}',
            'last_successful_sink_contract_key',
            'sink_contract_key',
            'native_generation_fence',
            'provider_enforced_idempotency',
            'External side-effect boundary inventory',
            'The 103-row dependency audit checks 104 prerequisite edges',
            'forward-only operational rollback',
            'SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED=true',
        ] as $needle) {
            $this->assertStringContainsString($needle, $design);
        }

        $this->assertMatchesRegularExpression(
            '/It never writes a\s+terminal claim\/\s*outbox\/\s*parent result\./',
            $design,
        );
        $this->assertMatchesRegularExpression(
            '/There is no\s+unauthenticated clear-first step\./',
            $design,
        );

        $this->assertSame(
            1,
            preg_match(
                "/\\(BINARY authorization_action = BINARY _ascii'republish_same_key'(?<republish>.*?)\\n    OR\\n    \\(BINARY authorization_action = BINARY _ascii'recover_expired_queued_ownership'/s",
                $design,
                $actionConstraint,
            ),
        );
        $this->assertStringContainsString("event_kind = BINARY _ascii'republish_succeeded'", $actionConstraint['republish']);
        $this->assertStringContainsString("event_kind = BINARY _ascii'publish_failed'", $actionConstraint['republish']);
        $this->assertStringContainsString("event_kind = BINARY _ascii'action_stopped'", $actionConstraint['republish']);
        $this->assertStringNotContainsString("event_kind = BINARY _ascii'terminalization_succeeded'", $actionConstraint['republish']);

        $protocolTable = $this->structuralMarkdownTable(
            $this->markdownSection(
                $design,
                '### Operationally governed recovery protocol outcomes',
                '## Cohort Enrollment Contract',
            ),
            '| Ownership and payload observation | Transport/response boundary | Permitted protocol outcome |',
            '| --- | --- | --- |',
            'recovery protocol outcome',
            3,
            'This table contains exactly 19 data rows and 3 columns. Merely reaching',
        );
        $crashTable = $this->structuralMarkdownTable(
            $this->markdownSection(
                $design,
                '### Crash and recovery matrix',
                'Rows 52 through 66 are coordination-only crash domains.',
            ),
            '| Boundary | Path | SupplierImportRun | ImportJob | ImportHistory | Claim | Outbox | Evidence | Allowed recovery | Prohibited actions | Required operator action |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |',
            'crash and recovery',
            11,
            null,
        );
        $rolloutTable = $this->rolloutCheckpointContract(
            $this->markdownSection(
                $design,
                '### Fine-grained rollout checkpoints',
                '### Forward-only operational rollback and bounded schema downgrade',
            ),
        );
        $stateFieldTable = $this->structuralMarkdownTable(
            $this->markdownSection(
                $design,
                'The canonical object contains exactly these 20 keys in this exact order;',
                'The state machine still enforces every cross-field path',
            ),
            '| Position | Key | Exact JSON type and value contract | Nullable |',
            '| --- | --- | --- | --- |',
            'expected-state field inventory',
            4,
            null,
        );
        $digestTable = $this->structuralMarkdownTable(
            $this->markdownSection(
                $design,
                '### Authoritative cryptographic and digest identity inventory',
                '### Exact hexadecimal storage contract',
            ),
            '| # | Identity | Purpose | Producer | Canonical bytes and domain | Algorithm | Persistence location | Immutability | Comparison point |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
            'cryptographic identity inventory',
            9,
            'The inventory count is contractual. Repeated persistence of one identity, such',
        );
        $hexStorageTable = $this->structuralMarkdownTable(
            $this->markdownSection(
                $design,
                '### Exact hexadecimal storage contract',
                '## Append-only Enforcement',
            ),
            '| Table | Non-null lowercase hexadecimal columns | Nullable lowercase hexadecimal columns |',
            '| --- | --- | --- |',
            'hexadecimal storage',
            3,
            'No listed field uses `BINARY(32)` in this design. A later implementation may',
        );

        foreach ([
            'recovery protocol outcome' => $protocolTable,
            'crash and recovery' => $crashTable,
            'rollout checkpoint' => $rolloutTable,
            'expected-state field inventory' => $stateFieldTable,
            'cryptographic identity inventory' => $digestTable,
            'hexadecimal storage' => $hexStorageTable,
        ] as $context => $table) {
            $this->assertSame([], $table['violations'], $context.': '.implode(PHP_EOL, $table['violations']));
        }

        $protocolRows = $protocolTable['rows'];
        $crashRows = $crashTable['rows'];
        $rolloutRows = $rolloutTable['rows'];
        $stateFieldRows = $stateFieldTable['rows'];
        $digestRows = $digestTable['rows'];
        $hexStorageRows = $hexStorageTable['rows'];

        $this->assertCount(19, $protocolRows);
        $this->assertCount(66, $crashRows);
        $this->assertCount(103, $rolloutRows);
        $this->assertCount(20, $stateFieldRows);
        $this->assertCount(22, $digestRows);
        $this->assertCount(10, $hexStorageRows);

        foreach ([3 => $protocolRows, 11 => $crashRows, 8 => $rolloutRows] as $expectedColumns => $rows) {
            foreach ($rows as $row) {
                $this->assertCount($expectedColumns, explode('|', trim($row, '|')));
            }
        }

        $this->assertStringContainsString('exactly 19 data rows and 3 columns', $design);
        $this->assertStringContainsString('exactly 66 data rows and 11 columns', $design);
        $this->assertStringContainsString('canonical 103-row fine-grained checkpoint matrix', $design);
        $this->assertStringContainsString('every 64-item acceptance criterion', $normalizedDesign);
        $this->assertStringContainsString('all 53 focused watchdog/authorization/mismatch cases', $normalizedDesign);
        $this->assertStringNotContainsString('every 63-item acceptance criterion', $design);
        $this->assertStringNotContainsString('all 44 focused watchdog/authorization/mismatch cases', $design);

        $this->assertSame(
            1,
            preg_match(
                '/MySQL\/Redis\/provider-adapter integration tests proving all of these exact cases:\n\n(?<cases>.*?)\n\nThe same future MySQL\/Redis\/provider-adapter suite must add focused/s',
                $design,
                $acceptancePlan,
            ),
        );
        preg_match_all('/^(\d+)\./m', $acceptancePlan['cases'], $acceptanceNumbers);
        $this->assertSame(range(1, 64), array_map('intval', $acceptanceNumbers[1]));

        $this->assertSame(
            1,
            preg_match(
                '/The same future MySQL\/Redis\/provider-adapter suite must add focused watchdog, authorization, and\n.*?exactly these cases:\n\n(?<cases>.*?)\n\nThose tests must also prove/s',
                $design,
                $focusedPlan,
            ),
        );
        preg_match_all('/^(\d+)\./m', $focusedPlan['cases'], $focusedNumbers);
        $this->assertSame(range(1, 53), array_map('intval', $focusedNumbers[1]));
        $normalizedFocusedPlan = preg_replace('/\s+/', ' ', $focusedPlan['cases']);
        $this->assertIsString($normalizedFocusedPlan);
        foreach ([
            'adversarial Redis test pausing A after local call-boundary CAS and before Redis, allowing B to retire/advance the Redis fence, resuming A, and asserting `stale_generation` plus external publish effect count for A exactly zero',
            'adversarial alert test pausing A after the last local DB authority step, allowing B takeover, resuming A at the provider boundary, and asserting native stale rejection/zero A effects or provider total logical effects `<= 1` from an external fake/receipt counter',
        ] as $externalRaceContract) {
            $this->assertStringContainsString($externalRaceContract, $normalizedFocusedPlan);
        }

        foreach ([
            '`delivery_outcome_unknown_exhausted`, preserve `attempt_count = 8`',
            'no worker can acquire a new automatic delivery lease',
            'terminal only for automatic delivery, not proof of delivery or failure',
            'It cannot transition to `permanent_failed`',
            'it cannot synthesize an ACK',
            'No reset, identity replacement, counter decrement, automatic retry, or ninth attempt is permitted',
            'A stale native-fence worker is rejected at the provider boundary',
            'an idempotency-mode worker may reach the API but cannot create an additional logical alert effect',
            'the first admitted effect sets it atomically, generation advancement or retirement never clears it',
            'If A won the provider race and consumed the logical effect before B\'s fence advance, B observes the permanent consumed latch and cannot create a second effect',
        ] as $attemptEightContract) {
            $this->assertStringContainsString($attemptEightContract, $normalizedDesign);
        }

        $this->assertStringNotContainsString(
            'exhausted eighth attempt marks the intent `permanent_failed`',
            $design,
        );

        foreach ([
            'Every physical Redis publication, initial or recovery, requires one committed reservation first',
            'increments `attempt_count = N + 1` and `publication_attempt_generation` by one',
            'The transaction commits before any Redis external operation',
            'local CAS is explicitly insufficient as an external-effect fence',
            'Direct worker use of an unguarded Redis `PUBLISH`, queue push, or separate `GET/check` followed by publication is forbidden',
            'There is no impossible per-execution generation-zero bootstrap',
            'The only legal absent-key transitions require the exact committed DB tuple `publication_attempt_generation = 1`, `publication_attempt_state = reserved` and all fence/call/result timestamps null',
            'If retirement wins, a delayed advance for the same generation returns `not_authorized`',
            'Every other absent, evicted, rolled-back, malformed, or conflicting Redis key is loss of fence state',
            'the Redis Function, not process scheduling, enforces the external effect',
            'A second call returns `already_consumed` without a second publication',
            'After successor generation `N + 1` is installed, a resumed worker from `N` receives `stale_generation` and creates zero external publish effects',
            'B1 does not pretend that the original resume fingerprint is unchanged',
            'One physical Redis effect therefore has one committed DB reservation and one Redis-consumed fence',
        ] as $publicationReservationContract) {
            $this->assertStringContainsString($publicationReservationContract, $normalizedDesign);
        }

        $this->assertStringNotContainsString(
            'its immutable started tuple is the durable reservation',
            $design,
        );
        foreach ([
            'There is no asynchronous yield',
            'cannot be interrupted between the successful CAS and invocation',
            'no scheduling point between the final CAS and the external call',
            'install the reviewed generation-zero fence while publishers are stopped',
        ] as $unsafeSchedulingClaim) {
            $this->assertStringNotContainsString($unsafeSchedulingClaim, $design);
        }

        $this->assertSame(
            1,
            preg_match(
                '/CONSTRAINT chk_import_recovery_auth_action CHECK \((?<actions>.*?)\n\),/s',
                $design,
                $canonicalActions,
            ),
        );
        preg_match_all("/BINARY _ascii'([^']+)'/", $canonicalActions['actions'], $actionValues);
        $this->assertSame(
            [
                'republish_same_key',
                'recover_expired_queued_ownership',
                'terminalize_stale_dispatch',
                'terminalize_publication_mismatch',
                'terminalize_abandoned_processing',
            ],
            $actionValues[1],
        );

        foreach ($actionValues[1] as $action) {
            $this->assertTrue(
                collect($protocolRows)->contains(
                    static fn (string $row): bool => str_contains($row, "`{$action}`"),
                ),
                "Canonical recovery action missing from protocol matrix: {$action}",
            );
        }

        $this->assertTrue(
            collect($protocolRows)->contains(
                static fn (string $row): bool => str_contains($row, '`terminalize_abandoned_processing`')
                    && str_contains($row, '`processing/published`')
                    && str_contains($row, 'processing_lease_abandoned'),
            ),
            'The protocol matrix lacks the dedicated abandoned-processing outcome.',
        );

        $this->assertTrue(
            collect($protocolRows)->contains(
                static fn (string $row): bool => str_contains($row, 'committed `republish_same_key` start with exact unchanged baseline')
                    && str_contains($row, 'Phase B0')
                    && str_contains($row, 'Phase B1')
                    && str_contains($row, 'external-fence-pending before Redis'),
            ),
            'The protocol matrix lacks the B0/B1 reservation-before-call outcome.',
        );
        $this->assertTrue(
            collect($protocolRows)->contains(
                static fn (string $row): bool => str_contains($row, '`external_fence_installed` or `call_boundary_entered`')
                    && str_contains($row, '`supplier_import_publish_fenced_v1`')
                    && str_contains($row, '`already_consumed`')
                    && str_contains($row, '`stale_generation`')
                    && str_contains($row, 'zero effects'),
            ),
            'The protocol matrix lacks the Redis-side one-use and stale-generation boundary.',
        );
        $this->assertTrue(
            collect($protocolRows)->contains(
                static fn (string $row): bool => str_contains($row, 'configured adapter has a proven native-fence or provider-idempotency')
                    && str_contains($row, 'unsupported/unverified providers fail closed')
                    && str_contains($row, 'cannot create a second logical alert effect'),
            ),
            'The protocol matrix lacks the provider capability gate.',
        );
        $this->assertTrue(
            collect($protocolRows)->contains(
                static fn (string $row): bool => str_contains($row, '`attempt_count = 8`')
                    && str_contains($row, 'final DB `outcome_unknown`')
                    && str_contains($row, 'no publish or attempt nine'),
            ),
            'The protocol matrix lacks the final unknown publication outcome.',
        );

        $this->assertSame(
            [
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
            ],
            array_map(
                static fn (string $row): string => trim(explode('|', trim($row, '|'))[1], ' `'),
                $stateFieldRows,
            ),
        );

        $expectedStateJson = '{"schema":"expected_state_fingerprint_v2","authorization_action":"recover_expired_queued_ownership","execution_claim_id":42,"dispatch_outbox_id":77,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","execution_path":"orchestrated","claim_state":"queued","outbox_state":"published","supplier_id":9,"supplier_import_run_id":501,"supplier_feed_id":12,"import_job_id":601,"import_history_id":701,"publication_attempt_count":2,"delivery_attempt_count":3,"transport_deadline_at":"2026-08-20T12:00:00.000000Z","delivery_watchdog_at":"2026-08-20T11:00:00.000000Z","active_attempt_token_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","claimed_at":"2026-08-20T10:00:00.000000Z","attempt_lease_expires_at":"2026-08-20T11:10:00.000000Z"}';
        $expectedStateHash = '31d1cf23a2fceac08d71c0103b3093af392f916921ef2221d860a7ecf9f7a62c';

        $this->assertSame(791, strlen($expectedStateJson));
        $this->assertStringContainsString($expectedStateJson, $design);
        $this->assertStringContainsString($expectedStateHash, $design);
        $this->assertSame(
            $expectedStateHash,
            hash('sha256', "mycomputer:supplier-recovery-expected-state:v2\0".$expectedStateJson),
        );

        $this->assertSame(
            [
                'supplier_import_execution_claims',
                'supplier_import_dispatch_outbox',
                'supplier_import_dispatch_monitor_health',
                'supplier_import_dispatch_alert_intents',
                'supplier_import_dispatch_recovery_authorizations',
                'supplier_import_dispatch_recovery_results',
                'supplier_import_cohort_authorization_members',
                'supplier_offer_snapshot_generations',
                'supplier_offer_snapshot_enrollments',
                'supplier_offer_snapshot_observations',
            ],
            (static function (string $contents): array {
                preg_match(
                    '/## Canonical Ten-Table Inventory(?<inventory>.*?)## Exact Index And Foreign-key Contract/s',
                    $contents,
                    $matches,
                );
                preg_match_all('/^\d+\. `([^`]+)`/m', $matches['inventory'] ?? '', $tables);

                return $tables[1] ?? [];
            })($design),
        );

        foreach ([
            'Monitor lease crash before successful cycle persistence',
            'Alert-delivery lease crash before external attempt',
            'Independent observer fails or stops updating',
            'Alert attempt 8 may have created the logical effect and crashes before durable ACK',
        ] as $boundary) {
            $this->assertTrue(
                collect($crashRows)->contains(
                    static fn (string $row): bool => str_contains($row, $boundary),
                ),
                "Missing canonical crash boundary: {$boundary}",
            );
        }

        foreach ([
            'Alert attempt 8 is reserved and worker disappears before provider boundary',
            'Alert attempt 8 may have created the logical effect and crashes before durable ACK',
            'Alert unknown-exhausted state is revisited',
            'Phase B1 reservation commits with external fence pending',
            'Crash after DB reservation and before Redis fence advancement',
            'Crash after Redis fence advancement but before DB confirmation',
            'Worker A pauses after local call-boundary CAS; B takes over and advances Redis fence',
            'Stale publication worker returns after external retirement or successor fence',
            'Alert worker A pauses after local DB check and resumes after B takeover',
        ] as $newBoundary) {
            $this->assertTrue(
                collect($crashRows)->contains(
                    static fn (string $row): bool => str_contains($row, $newBoundary),
                ),
                "Missing A1/A2 crash boundary: {$newBoundary}",
            );
        }

        $redisRaceRow = collect($crashRows)->first(
            static fn (string $row): bool => str_contains($row, 'Worker A pauses after local call-boundary CAS'),
        );
        $this->assertIsString($redisRaceRow);
        foreach (['B takes over', '`stale_generation`', 'zero Redis publish effects', 'effect counter for A is zero'] as $marker) {
            $this->assertStringContainsString($marker, $redisRaceRow);
        }

        $alertRaceRow = collect($crashRows)->first(
            static fn (string $row): bool => str_contains($row, 'Alert worker A pauses after local DB check'),
        );
        $this->assertIsString($alertRaceRow);
        foreach (['after B takeover', 'native mode rejects A before effect', 'external receipt count for the logical identity', '`<= 1`', 'effect counter'] as $marker) {
            $this->assertStringContainsString($marker, $alertRaceRow);
        }

        $rolloutNames = array_map(
            static fn (string $row): string => trim(explode('|', trim($row, '|'))[1]),
            $rolloutRows,
        );
        $prChains = [
            ['Establish current local design candidate', 'Validate local design candidate', 'Independent local design review', 'Remediate blocked design findings or record not-required', 'Fresh independent design re-review/PASS', 'Authorize design branch push', 'Push exact design branch', 'Verify design remote branch', 'Create design Draft PR', 'Verify design PR base/head', 'Run design PR CI', 'Independent design PR review', 'Authorize design merge', 'Merge design PR'],
            ['Authorize schema implementation', 'Implement schema candidate locally', 'Validate schema candidate', 'Authorize capture/idempotency/outbox implementation', 'Implement capture candidate locally', 'Validate complete implementation candidate', 'Independent implementation review', 'Remediate blocked implementation findings or record not-required', 'Fresh independent implementation re-review/PASS', 'Authorize implementation branch push', 'Push exact implementation branch', 'Verify implementation remote branch', 'Create implementation Draft PR', 'Verify implementation PR base/head', 'Run implementation PR CI', 'Independent implementation PR review', 'Authorize implementation merge', 'Merge implementation PR'],
            ['Authorize monitor/observer implementation', 'Verify monitor implementation branch/repository state', 'Implement monitor/observer candidate locally', 'Run focused monitor/observer validation', 'Validate monitor database/migrations', 'Validate monitor security and Catalog Sync safety', 'Independent monitor implementation review', 'Remediate blocked monitor findings or record not-required', 'Independent monitor re-review/PASS', 'Authorize monitor branch push', 'Push exact monitor branch', 'Verify monitor remote branch', 'Create monitor Draft PR', 'Verify monitor PR base/head', 'Run monitor Draft PR CI', 'Independent monitor PR review', 'Authorize monitor PR merge', 'Merge monitor PR'],
            ['Authorize evidence-producer implementation', 'Implement producer candidate locally', 'Validate producer candidate', 'Independent producer review', 'Remediate blocked producer findings or record not-required', 'Fresh independent producer re-review/PASS', 'Authorize producer branch push', 'Push exact producer branch', 'Verify producer remote branch', 'Create producer Draft PR', 'Verify producer PR base/head', 'Run producer PR CI', 'Independent producer PR review', 'Authorize producer merge', 'Merge producer PR'],
            ['Authorize documentation closeout', 'Implement closeout documentation candidate', 'Validate closeout documentation candidate', 'Independent closeout review', 'Remediate blocked closeout findings or record not-required', 'Fresh independent closeout re-review/PASS', 'Authorize closeout branch push', 'Push exact closeout branch', 'Verify closeout remote branch', 'Create closeout Draft PR', 'Verify closeout PR base/head', 'Run closeout PR CI', 'Independent closeout PR review', 'Authorize closeout merge', 'Merge closeout PR'],
        ];

        foreach ($prChains as $chain) {
            $positions = array_map(
                static fn (string $checkpoint): int|false => array_search($checkpoint, $rolloutNames, true),
                $chain,
            );

            $this->assertNotContains(false, $positions);

            for ($index = 1; $index < count($positions); $index++) {
                $this->assertGreaterThan($positions[$index - 1], $positions[$index]);
            }
        }

        $rolloutCheckpointRows = array_map(
            static function (string $row): array {
                $cells = array_map('trim', explode('|', trim($row, '|')));

                return ['id' => (int) $cells[0]];
            },
            $rolloutRows,
        );
        $this->assertSame(
            [],
            $this->duplicateStructuralKeyViolations($rolloutCheckpointRows, 'id', 'rollout checkpoint'),
        );
        $this->assertSame(range(1, 103), array_column($rolloutCheckpointRows, 'id'));

        $rolloutByCheckpoint = collect($rolloutRows)->mapWithKeys(
            static function (string $row): array {
                $cells = array_map('trim', explode('|', trim($row, '|')));

                return [(int) $cells[0] => $row];
            },
        );
        foreach ([
            19 => ['supplier_import_advance_fence_v1', 'supplier_import_publish_fenced_v1', 'retirement/reconciliation', 'direct publish is forbidden'],
            20 => ['suspended A after local CAS', 'B fence advance', 'resumed A stale rejection', 'Redis effect count zero'],
            21 => ['Redis Function/ACL/first-generation initialization/loss behavior', 'external effect counter evidence'],
            39 => ['capability-gated adapter', 'unsupported provider cannot lease or report healthy'],
            40 => ['paused A', 'B takeover', 'resumed provider call', 'total-effect `<= 1`'],
            42 => ['provider capability/version/idempotency horizon or native fence', 'unsupported-provider closure'],
            43 => ['provider contract', 'adversarial external-effect evidence, not DB state alone'],
            58 => ['selected provider', 'approved native/idempotency mode', 'stale-worker/duplicate test evidence'],
            59 => ['stale-worker oracle', 'stored matching `sink_contract_key`', 'external-effect proof'],
            60 => ['provider-capability and Redis-fence readiness gates'],
        ] as $checkpoint => $markers) {
            $this->assertTrue($rolloutByCheckpoint->has($checkpoint));

            foreach ($markers as $marker) {
                $this->assertStringContainsString($marker, $rolloutByCheckpoint->get($checkpoint));
            }
        }

        $this->assertNotContains('Implement/validate schema locally', $rolloutNames);
        $this->assertNotContains('Implement/validate capture locally', $rolloutNames);
        $this->assertNotContains('Implement/validate producer locally', $rolloutNames);

        $prerequisiteEdges = 0;

        foreach ($rolloutRows as $index => $row) {
            $cells = array_map('trim', explode('|', trim($row, '|')));
            $checkpoint = $index + 1;

            $this->assertSame($checkpoint, (int) $cells[0]);

            preg_match_all('/checkpoint(?:s)?\s+(\d+)(?:\s+through\s+(\d+))?/', $cells[2], $references, PREG_SET_ORDER);

            foreach ($references as $reference) {
                $first = (int) $reference[1];
                $last = isset($reference[2]) && $reference[2] !== '' ? (int) $reference[2] : $first;

                foreach (range($first, $last) as $dependency) {
                    $prerequisiteEdges++;
                    $this->assertGreaterThanOrEqual(1, $dependency);
                    $this->assertLessThan($checkpoint, $dependency);
                    $this->assertArrayHasKey($dependency - 1, $rolloutRows);
                }
            }
        }

        $this->assertSame(104, $prerequisiteEdges);

        foreach ([
            'Rollback disables only the capture gate',
            'Reverse order drops the alert FK/table before the monitor table',
            'Every reverse migration must first drop',
        ] as $supersededRollbackContract) {
            $this->assertStringNotContainsString($supersededRollbackContract, $design);
        }

        foreach ([
            'leaves all ten tables, FKs, triggers, rows and uncertain states',
            'Any false, unknown, unreadable or partially evaluated predicate rejects',
            '**Empty schema:**',
            '**Evidence exists:**',
            '**Operational rollback:**',
            '**Partial evidence/schema:**',
        ] as $rollbackContract) {
            $this->assertStringContainsString($rollbackContract, $design);
        }

        $alertVectors = [
            '{"schema":"supplier-import-dispatch-alert-v1","alert_type":"dispatch_watchdog_overdue","dispatch_outbox_id":101,"delivery_watchdog_at":"2026-08-20T10:15:30.123456Z","severity":"warning","critical_bucket":null}' => '0784419b016bd71a2ad912c752ab64d5405899f261a22fa78c75f5a300002fe0',
            '{"schema":"supplier-import-dispatch-alert-v1","alert_type":"dispatch_watchdog_overdue","dispatch_outbox_id":202,"delivery_watchdog_at":"2026-08-20T10:45:30.000000Z","severity":"critical","critical_bucket":0}' => 'a4cfd7d96ada0678b7054d3bfe2f62a1b423a98bb9507ce7e664a9c549b14f31',
        ];

        foreach ($alertVectors as $canonicalJson => $expectedHash) {
            $this->assertStringContainsString($canonicalJson, $design);
            $this->assertSame(
                $expectedHash,
                hash('sha256', "supplier-import-dispatch-monitor-alert-v1\0".$canonicalJson),
            );
        }

        $this->assertMatchesRegularExpression(
            '/`stale`,\s+`failed`\s+and\s+`unknown`\s+always fail closed/',
            $design,
        );
        $this->assertMatchesRegularExpression(
            '/observer timestamp no more\s+than 120 seconds old/',
            $design,
        );

        $this->assertStringContainsString(
            'The `logical_execution_key` itself remains durably persisted',
            $design,
        );
        $this->assertStringNotContainsString(
            'The raw canonical JSON and logical execution key exist only',
            $design,
        );

        foreach ($documents as $document) {
            $contents = file_get_contents(base_path($document));

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('dispatch_payload_unobserved', $contents);
            $this->assertStringNotContainsString('49 fine-grained checkpoints', $contents);
            $this->assertStringNotContainsString('49 checkpoints', $contents);
            $this->assertStringNotContainsString('eleven-commit', $contents);
            $this->assertStringNotContainsString('twelve-commit', $contents);
            $this->assertStringNotContainsString('fourteen-commit', $contents);
            $this->assertStringNotContainsString('53-row fine-grained checkpoint matrix', $contents);
            $this->assertStringNotContainsString('53 fine-grained checkpoints', $contents);
            $this->assertStringNotContainsString('protocol table remains 12 outcomes', $contents);
            $this->assertStringNotContainsString('36-by-11 crash matrix', $contents);
            $this->assertStringNotContainsString('41-row by 11-column', $contents);
            $this->assertStringNotContainsString('41-by-11', $contents);
            $this->assertStringNotContainsString('71-row fine-grained checkpoint matrix', $contents);
            $this->assertStringNotContainsString('14 by 3', $contents);
            $this->assertStringNotContainsString('53-row by 11-column', $contents);
            $this->assertStringNotContainsString('53-by-11', $contents);
            $this->assertStringNotContainsString('89-row fine-grained checkpoint matrix', $contents);
            $this->assertStringNotContainsString('89 fine-grained checkpoints', $contents);
            $this->assertStringNotContainsString('19-field', $contents);
            $this->assertStringContainsString('103-row fine-grained checkpoint matrix', $contents);
        }

        foreach (['docs/PHASES.md', 'docs/ROADMAP.md'] as $document) {
            $contents = file_get_contents(base_path($document));

            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression('/103(?: fine-grained)? checkpoints/', $contents);
        }
    }

    public function test_phase_three_readiness_remediation_is_explicit_and_fail_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $readiness = $this->markdownSection(
            $design,
            '### Historical Phase III readiness findings (superseded)',
            '### Canonical source scope',
        );

        $readinessStatusContract = $this->readinessStatusContract($readiness);
        $expectedReadinessStatuses = [
            'PH3-RDY-001' => 'CLOSED',
            'PH3-RDY-002' => 'CLOSED',
            'PH3-RDY-003' => 'BLOCKED',
            'PH3-RDY-004' => 'CLOSED',
        ];
        $this->assertSame(
            [],
            $readinessStatusContract['violations'],
            implode(PHP_EOL, $readinessStatusContract['violations']),
        );
        $this->assertSame(4, $readinessStatusContract['raw_count']);
        $this->assertSame(4, $readinessStatusContract['unique_count']);
        $this->assertSame(4, $readinessStatusContract['expected_count']);
        $this->assertSame($expectedReadinessStatuses, $readinessStatusContract['statuses']);
        $this->assertStringContainsString('| `PH3-RDY-001` | `CLOSED` |', $readiness);
        $this->assertStringContainsString('| `PH3-RDY-002` | `CLOSED` |', $readiness);
        $this->assertStringContainsString(
            'immutable source execution plus immutable supplier-product source revision',
            $readiness,
        );
        $this->assertStringContainsString('historical, superseded and non-authoritative', $readiness);
        $this->assertStringContainsString('block alone defines current statuses', $readiness);
        $this->assertStringContainsString(
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-readiness-remediation-v1 -->',
            $design,
        );
        $this->assertStringContainsString(
            '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->',
            $design,
        );

        $sourceScope = $this->markdownSection(
            $design,
            '### Canonical source scope',
            '### Normative same-feed source A to B invariant',
        );
        $sourceAB = $this->markdownSection(
            $design,
            '### Normative same-feed source A to B invariant',
            '### Deterministic `supplier_products` selection',
        );
        $supplierProducts = $this->markdownSection(
            $design,
            '### Deterministic `supplier_products` selection',
            '### Deterministic `product_supplier_offers` selection',
        );
        $productSupplierOffers = $this->markdownSection(
            $design,
            '### Deterministic `product_supplier_offers` selection',
            '### Ambiguity, identity, sorting, and seed construction',
        );

        $this->assertStringContainsString(
            '(supplier_id, supplier_feed_id, source_identity)',
            $sourceScope,
        );
        $this->assertStringContainsString('supplier/feed equality alone cannot', $sourceScope);
        foreach ([
            'feed ID `F` represents canonical source `A`',
            'source-defining feed configuration changes from `A` to `B` without',
            'historical `A` rows MUST NOT be admitted into the `B` authorization',
            'current candidate rows do not immutably record `A`',
            'selected immutable candidate-revision contract below prevents that',
        ] as $adversarialInvariant) {
            $this->assertStringContainsString($adversarialInvariant, $sourceAB);
        }
        foreach (['feed_url', 'feed_type', 'mapping', 'credentials'] as $mutableSourceFact) {
            $this->assertStringContainsString($mutableSourceFact, $sourceAB);
        }

        foreach ([
            '`supplier_products.supplier_feed_id` equality proves feed ownership only',
            'exact supplier/feed but immutable original-source provenance is missing',
            '`NOT ADMISSIBLE / FAIL CLOSED / BLOCKED BY PROVENANCE`',
            'provenance inconsistent with the capture source',
            'null, empty, whitespace-only, malformed, conflicting',
        ] as $candidateContract) {
            $this->assertStringContainsString($candidateContract, $supplierProducts);
        }
        $this->assertStringNotContainsString(
            '| exact supplier/feed and one valid canonical supplier SKU | `INCLUDE` |',
            $supplierProducts,
        );

        foreach ([
            'may establish supplier/feed/SKU',
            'does not add immutable source provenance',
            'does not solve the same-feed A to B',
            'exact scope supplier/feed but no immutable original-source provenance',
            '`NOT ADMISSIBLE / FAIL CLOSED / BLOCKED BY PROVENANCE`',
        ] as $offerContract) {
            $this->assertStringContainsString($offerContract, $productSupplierOffers);
        }
        $this->assertStringNotContainsString(
            'The offer contributes the same source-authorized identity as its staging parent.',
            $productSupplierOffers,
        );

        $sourceBinding = $this->markdownSection(
            $design,
            '### PH3-RDY-002 authorization binding and PH3-RDY-001 candidate provenance',
            '### PH3-RDY-003 authoritative-limit inventory and unresolved gate',
        );
        foreach ([
            '**Problem A - authorization binding (`PH3-RDY-002`).**',
            '**Problem B - candidate-row provenance (`PH3-RDY-001`).**',
            'Claim source binding does not solve this problem.',
            'combined append-only execution plus revision chain',
            '**CLOSED IN DESIGN / NOT IMPLEMENTED**',
            'supplier_import_execution_claims.cohort_source_identity VARCHAR(128)',
            'CHARACTER SET ascii COLLATE ascii_bin NULL',
            '^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$',
            'cohort_authorization_version`, `cohort_authorized_at`,',
            '`cohort_seed_count`, `cohort_seed_fingerprint`, and',
            '`cohort_source_identity`',
            'trg_import_execution_claim_cohort_source_immutable',
            '`NULL -> A` is allowed only',
            '`A -> B`, `A -> NULL`',
            'current mutable SupplierFeed URL/type/mapping/configuration',
            'is not safe and must fail migration/readiness',
            'canonical serializer/fingerprint revision is **NOT REQUIRED**',
        ] as $bindingContract) {
            $this->assertStringContainsString($bindingContract, $sourceBinding);
        }

        $expectedAuthorizationTuple = $this->expectedAuthorizationTuple();
        $canonicalAuthorizationTuple = $this->canonicalAuthorizationCompletenessTuple($sourceBinding);
        $authorizationProcedureContract = $this->authorizationProcedureContract(
            $design,
            $canonicalAuthorizationTuple,
        );

        $this->assertSame($expectedAuthorizationTuple, $canonicalAuthorizationTuple);
        $this->assertSame(
            [],
            $authorizationProcedureContract['violations'],
            implode(PHP_EOL, $authorizationProcedureContract['violations']),
        );
        foreach ([
            'authorization-member-persistence',
            'capture-start-coordinator',
            'bounded-capture-collector',
        ] as $currentProcedure) {
            $this->assertContains($currentProcedure, $authorizationProcedureContract['registry_ids']);
        }

        preg_match(
            '/- parent unique key:\s+`(?<table>[a-z_]+)` must define exactly\s+`(?<name>[a-z_]+)\((?<columns>[^)`]+)\)`;/m',
            $sourceBinding,
            $parentUnique,
        );
        $this->assertSame('supplier_import_execution_claims', $parentUnique['table'] ?? null);
        $this->assertSame('uq_import_execution_claim_id_cohort_source', $parentUnique['name'] ?? null);
        $this->assertSame(
            ['id', 'cohort_source_identity'],
            $this->commaSeparatedColumns($parentUnique['columns'] ?? ''),
        );

        preg_match(
            '/- child composite index:\s+`(?<table>[a-z_]+)` must define exactly\s+`(?<name>[a-z_]+)\((?<columns>[^)`]+)\)` in that column order;/m',
            $sourceBinding,
            $childIndex,
        );
        $this->assertSame('supplier_offer_snapshot_generations', $childIndex['table'] ?? null);
        $this->assertSame('ix_snapshot_generation_claim_source', $childIndex['name'] ?? null);
        $this->assertSame(
            ['supplier_import_execution_claim_id', 'source_identity'],
            $this->commaSeparatedColumns($childIndex['columns'] ?? ''),
        );

        preg_match(
            '/- composite FK:\s+`(?<name>[a-z_]+)` binds child\s+`(?<child_table>[a-z_]+)\((?<child_columns>[^)`]+)\)` to parent\s+`(?<parent_table>[a-z_]+)\((?<parent_columns>[^)`]+)\)`, with\s+`(?<update_action>ON UPDATE [A-Z]+)` and `(?<delete_action>ON DELETE [A-Z]+)`;/m',
            $sourceBinding,
            $compositeForeignKey,
        );
        $this->assertSame('fk_snapshot_generation_claim_source', $compositeForeignKey['name'] ?? null);
        $this->assertSame('supplier_offer_snapshot_generations', $compositeForeignKey['child_table'] ?? null);
        $this->assertSame(
            ['supplier_import_execution_claim_id', 'source_identity'],
            $this->commaSeparatedColumns($compositeForeignKey['child_columns'] ?? ''),
        );
        $this->assertSame('supplier_import_execution_claims', $compositeForeignKey['parent_table'] ?? null);
        $this->assertSame(
            ['id', 'cohort_source_identity'],
            $this->commaSeparatedColumns($compositeForeignKey['parent_columns'] ?? ''),
        );
        $this->assertSame('ON UPDATE RESTRICT', $compositeForeignKey['update_action'] ?? null);
        $this->assertSame('ON DELETE RESTRICT', $compositeForeignKey['delete_action'] ?? null);

        $supplierFeedModel = $this->readDocument('app/Models/SupplierFeed.php');
        $supplierProductModel = $this->readDocument('app/Models/SupplierProduct.php');
        $productSupplierOfferModel = $this->readDocument('app/Models/ProductSupplierOffer.php');
        $claimMigration = $this->readDocument(
            'database/migrations/2026_08_20_120001_create_supplier_import_execution_claims_table.php',
        );
        $generationMigration = $this->readDocument(
            'database/migrations/2026_08_20_120008_create_supplier_offer_snapshot_generations_table.php',
        );

        foreach (["'feed_url'", "'feed_type'", "'mapping'"] as $mutableFeedField) {
            $this->assertStringContainsString($mutableFeedField, $supplierFeedModel);
        }
        $this->assertStringContainsString("return ['id', 'supplier_id'];", $supplierFeedModel);
        $this->assertStringContainsString("'supplier_feed_id'", $supplierProductModel);
        $this->assertStringNotContainsString("'source_identity'", $supplierProductModel);
        $this->assertStringContainsString("'supplier_product_id'", $productSupplierOfferModel);
        $this->assertStringNotContainsString("'source_identity'", $productSupplierOfferModel);
        $this->assertStringContainsString("Schema::create('supplier_import_execution_claims'", $claimMigration);
        $this->assertStringNotContainsString("'cohort_source_identity'", $claimMigration);
        $this->assertStringContainsString("Schema::create('supplier_offer_snapshot_generations'", $generationMigration);
        $this->assertStringContainsString(
            "unsignedBigInteger('supplier_import_execution_claim_id')",
            $generationMigration,
        );
        $this->assertStringContainsString("string('source_identity', 128)", $generationMigration);
        $this->assertStringContainsString(
            "_ascii'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$'",
            $generationMigration,
        );
        foreach ([
            'uq_import_execution_claim_id_cohort_source',
            'ix_snapshot_generation_claim_source',
            'fk_snapshot_generation_claim_source',
        ] as $futureSchemaName) {
            $this->assertStringNotContainsString($futureSchemaName, $claimMigration.$generationMigration);
        }

        $limits = $this->markdownSection(
            $design,
            '### PH3-RDY-003 authoritative-limit inventory and unresolved gate',
            '## Generation Header Data Dictionary',
        );
        foreach ([
            '| `max_source_rows` | `NOT SPECIFIED` |',
            '| `max_spool_rows` | `NOT SPECIFIED` |',
            '| `max_spool_bytes` | `NOT SPECIFIED` |',
            '| `max_enrollments` | `NOT SPECIFIED` |',
            '| `max_observations` | `NOT SPECIFIED` |',
            '| `max_canonical_children` | `NOT SPECIFIED` |',
            '| `external_sort_chunk` | `NOT SPECIFIED` | approved maximum canonical records per in-memory run;',
            '| `db_insert_batch_ceiling` | `NOT SPECIFIED` | approved maximum child rows per SQL insert statement',
            '| `snapshot_transaction_bound` | `NOT SPECIFIED` | approved maximum inserted/updated rows per finalization transaction',
            '5,000, 500, 1,000, 8,388,608 bytes (8 MiB)',
            '65,536-byte evidence chunks',
            'Catalog Sync diagnostic values 1,000, 2,000,',
            'frozen `capture_outcome=overflow` header with `capture_overflow`',
            'Overflow is not retryable under unchanged bounds',
            'Temporary files are removed in `finally`',
        ] as $limitContract) {
            $this->assertStringContainsString($limitContract, $limits);
        }

        foreach ([
            '31d1cf23a2fceac08d71c0103b3093af392f916921ef2221d860a7ecf9f7a62c',
            '1773b68dacaae6c50b2305aec164b7135d0c43da06a69dd3ef676176e785aba3',
            'fe9b7b9d6ba91912606d8498c6faa4968b8315df6e5646144586f461ac1d54f8',
            '2342382283afc7bf368d49b0d3c561c03d4b1542a1ef84e1ad3f1757f9fed1a4',
        ] as $frozenHash) {
            $this->assertStringContainsString($frozenHash, $design);
        }

        foreach ([
            'Phase I canonical schema: implemented, merged through PR #212',
            'Phase II guarded models and canonical byte contracts: implemented, merged',
            'Phase III snapshot persistence/cohort authorization: provenance and durable',
            '<!-- phase-iii-architecture-status-reference authority=phase-iii-architecture-contract-v1 -->',
            'This plan does not mirror or restate that map.',
        ] as $status) {
            $this->assertStringContainsString($status, $plan);
        }
        $this->assertStringNotContainsString('`PH3-RDY-001`', $plan);
        $this->assertStringNotContainsString('`PH3-RDY-002`', $plan);
        $this->assertStringNotContainsString('`PH3-RDY-003`', $plan);
        $this->assertStringNotContainsString('`PH3-RDY-004`', $plan);
        $this->assertStringContainsString('remaining numeric-evidence gate requires separately authorized production-', $plan);
        $this->assertStringContainsString('Phase III implementation, which remains unimplemented and', $plan);
        $this->assertStringNotContainsString(
            'separate design work for immutable candidate-row source provenance, durable',
            $plan,
        );

        $runtimeInventory = $this->markdownSection(
            $plan,
            '### Current deployed artifact inventory',
            '### Remaining runtime implementation gaps',
        );
        $runtimeInventoryContract = $this->runtimeInventoryContract($runtimeInventory);
        $this->assertSame(
            [],
            $runtimeInventoryContract['violations'],
            implode(PHP_EOL, $runtimeInventoryContract['violations']),
        );
        $this->assertSame(23, $runtimeInventoryContract['raw_count']);
        $this->assertSame(23, $runtimeInventoryContract['unique_count']);
        $this->assertSame(23, $runtimeInventoryContract['expected_count']);
        $runtimeInventoryRows = $runtimeInventoryContract['artifacts'];
        $phaseOneTables = [
            'supplier_import_execution_claims' => 'database/migrations/2026_08_20_120001_create_supplier_import_execution_claims_table.php',
            'supplier_import_dispatch_outbox' => 'database/migrations/2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php',
            'supplier_import_dispatch_monitor_health' => 'database/migrations/2026_08_20_120003_create_supplier_import_dispatch_monitor_health_table.php',
            'supplier_import_dispatch_alert_intents' => 'database/migrations/2026_08_20_120004_create_supplier_import_dispatch_alert_intents_table.php',
            'supplier_import_dispatch_recovery_authorizations' => 'database/migrations/2026_08_20_120005_create_supplier_import_dispatch_recovery_authorizations_table.php',
            'supplier_import_dispatch_recovery_results' => 'database/migrations/2026_08_20_120006_create_supplier_import_dispatch_recovery_results_table.php',
            'supplier_import_cohort_authorization_members' => 'database/migrations/2026_08_20_120007_create_supplier_import_cohort_authorization_members_table.php',
            'supplier_offer_snapshot_generations' => 'database/migrations/2026_08_20_120008_create_supplier_offer_snapshot_generations_table.php',
            'supplier_offer_snapshot_enrollments' => 'database/migrations/2026_08_20_120009_create_supplier_offer_snapshot_enrollments_table.php',
            'supplier_offer_snapshot_observations' => 'database/migrations/2026_08_20_120010_create_supplier_offer_snapshot_observations_table.php',
        ];
        $phaseTwoModels = [
            'SupplierImportExecutionClaim',
            'SupplierImportDispatchOutbox',
            'SupplierImportDispatchMonitorHealth',
            'SupplierImportDispatchAlertIntent',
            'SupplierImportDispatchRecoveryAuthorization',
            'SupplierImportDispatchRecoveryResult',
            'SupplierImportCohortAuthorizationMember',
            'SupplierOfferSnapshotGeneration',
            'SupplierOfferSnapshotEnrollment',
            'SupplierOfferSnapshotObservation',
        ];

        foreach ($phaseOneTables as $table => $migration) {
            $this->assertSame('PRESENT / DEPLOYED', $runtimeInventoryRows[$table]['artifact_status'] ?? null);
            $this->assertSame('INACTIVE / UNWIRED', $runtimeInventoryRows[$table]['runtime_status'] ?? null);
            $this->assertFileExists(base_path($migration));
            $this->assertStringContainsString("Schema::create('{$table}'", $this->readDocument($migration));
        }
        foreach ($phaseTwoModels as $model) {
            $this->assertSame('PRESENT / DEPLOYED', $runtimeInventoryRows[$model]['artifact_status'] ?? null);
            $this->assertSame('UNCALLED', $runtimeInventoryRows[$model]['runtime_status'] ?? null);
            $this->assertFileExists(base_path("app/Models/{$model}.php"));
        }
        foreach ([
            'app/Data/Suppliers/Snapshots/CanonicalSupplierContract.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierImportDispatchPayload.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierRecoveryExpectedStateV2.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierRecoveryResumeState.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierRecoveryResult.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierSnapshotGenerationHeader.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierSnapshotEnrollment.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierSnapshotObservation.php',
            'app/Data/Suppliers/Snapshots/CanonicalSupplierSnapshotReasonCode.php',
        ] as $canonicalContract) {
            $this->assertFileExists(base_path($canonicalContract));
        }
        $this->assertSame(
            ['artifact_status' => 'PRESENT / DEPLOYED', 'runtime_status' => 'UNCALLED'],
            $runtimeInventoryRows['Phase II canonical byte/value contracts'] ?? null,
        );
        $this->assertSame(
            ['artifact_status' => 'PRESENT / DEPLOYED', 'runtime_status' => 'UNCALLED'],
            $runtimeInventoryRows['SupplierSnapshotFingerprintService'] ?? null,
        );
        $this->assertFileExists(base_path('app/Services/Suppliers/Snapshots/SupplierSnapshotFingerprintService.php'));
        $this->assertSame(
            ['artifact_status' => 'PRESENT / DEPLOYED', 'runtime_status' => 'UNCALLED'],
            $runtimeInventoryRows['SnapshotSourceIdentity'] ?? null,
        );
        $this->assertFileExists(base_path('app/Data/Suppliers/Onboarding/SnapshotSourceIdentity.php'));

        $apcomScope = $this->markdownSection(
            $this->readDocument('docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md'),
            '## Scope',
            '## Immutable Persistence Rollout Checkpoints',
        );
        $phasesInProgress = $this->markdownSection(
            $this->readDocument('docs/PHASES.md'),
            '## In Progress',
            '### Phase 9C.6.5C.3D.1-PRE.A Rollout Checkpoints',
        );
        $roadmap = $this->readDocument('docs/ROADMAP.md');
        $onboarding = $this->readDocument('docs/SUPPLIER_ONBOARDING_FRAMEWORK.md');
        $outboxMigration = $this->readDocument(
            'database/migrations/2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php',
        );

        $this->assertMatchesRegularExpression('/Phase I\'s\s+persistence schema/', $apcomScope);
        $this->assertStringContainsString('tables and migrations, is implemented', $apcomScope);
        $this->assertStringContainsString('later runtime claim/outbox/recovery', $apcomScope);
        $this->assertStringNotContainsString(
            'No runtime outbox/claim/recovery-authorization table, migration',
            $apcomScope,
        );
        $this->assertStringContainsString('Design PR #211 is merged.', $phasesInProgress);
        $this->assertStringContainsString('Phase I\'s historical canonical ten-table schema is implemented through PR #212', $phasesInProgress);
        $this->assertStringContainsString('Phase II\'s guarded models', $phasesInProgress);
        $this->assertStringNotContainsString(
            'remediated locally and require a fresh aggregate review',
            $phasesInProgress,
        );
        $this->assertStringNotContainsString(
            'no outbox/claim/recovery/monitor schema',
            $this->readDocument('docs/PHASES.md'),
        );
        $this->assertStringNotContainsString('but no outbox/claim', $roadmap);
        $this->assertStringContainsString(
            "\$table->timestamp('delivery_watchdog_at', 6)->nullable();",
            $outboxMigration,
        );
        $this->assertStringContainsString(
            "['state', 'delivery_watchdog_at', 'id']",
            $outboxMigration,
        );
        $this->assertStringContainsString(
            "'ix_import_dispatch_outbox_state_watchdog_id'",
            $outboxMigration,
        );
        $watchdogContract = $this->watchdogDocumentationContract(
            $this->watchdogDocumentation(),
            $outboxMigration,
        );
        $this->assertSame([], $watchdogContract['violations'], implode(PHP_EOL, $watchdogContract['violations']));

        $reviewBoundaryStart = strpos($plan, '## Review and rollout boundary');
        $this->assertNotFalse($reviewBoundaryStart);
        $reviewBoundary = substr($plan, $reviewBoundaryStart);
        $this->assertIsString($reviewBoundary);

        $this->assertCount(
            1,
            array_filter(
                $readinessStatusContract['statuses'],
                static fn (string $status): bool => $status === 'BLOCKED',
            ),
        );

        foreach ([$phasesInProgress, $roadmap, $onboarding] as $statusReference) {
            $this->assertStringContainsString(
                '<!-- phase-iii-architecture-status-reference authority=phase-iii-architecture-contract-v1 -->',
                $statusReference,
            );
            $this->assertStringNotContainsString('`PH3-RDY-001`', $statusReference);
            $this->assertStringNotContainsString('`PH3-RDY-002`', $statusReference);
            $this->assertStringNotContainsString('`PH3-RDY-003`', $statusReference);
            $this->assertStringNotContainsString('`PH3-RDY-004`', $statusReference);
        }
    }

    public function test_phase_three_provenance_source_binding_and_bounds_architecture_is_fail_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $authority = $this->phaseThreeArchitectureAuthorityContract($design);
        $this->assertSame([], $authority['violations'], implode(PHP_EOL, $authority['violations']));
        $this->assertSame(1, $authority['current_count']);
        $this->assertSame(1, $authority['historical_count']);
        $contract = $this->phaseThreeArchitectureContract($design);
        $this->assertSame([], $contract['violations'], implode(PHP_EOL, $contract['violations']));
        $this->assertSame(3, $contract['marker_candidate_count']);
        $this->assertSame(1, $contract['valid_block_count']);
        $architecture = $contract['body'];

        $alternatives = $this->structuralMarkdownTable(
            $architecture,
            '| Alternative | Immutable evidence | A to B / reused-row safety | Retry/concurrency | Decision |',
            '| --- | --- | --- | --- | --- |',
            'Phase III provenance alternatives',
            5,
            'The canonical design is **B + C**.',
        );
        $this->assertSame([], $alternatives['violations'], implode(PHP_EOL, $alternatives['violations']));
        $this->assertSame(4, $alternatives['physical_count']);

        foreach ([
            'supplier_import_source_profiles',
            'supplier_import_source_executions',
            'supplier_import_source_payload_receipts',
            'supplier_product_identity_heads',
            'supplier_product_source_revisions',
            'current_source_revision_id',
            'source_execution_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin',
            'uq_import_source_profile_descriptor',
            'uq_import_source_profile_identity',
            'uq_import_source_execution_scope',
            'uq_import_source_payload_receipt_execution',
            'uq_import_source_payload_receipt_fingerprint',
            'uq_supplier_product_identity_head',
            'uq_supplier_product_identity_head_revision_scope',
            'supplier_sku_bytes VARBINARY(1020)',
            'uq_supplier_product_revision_execution_head',
            'supplier_import_source_execution_v1',
            'supplier_product_source_revision_v1',
        ] as $provenanceAuthority) {
            $this->assertStringContainsString($provenanceAuthority, $architecture);
        }
        foreach ([
            '`descriptor_version`, `source_locator_contract_key` and'."\n".
                '`importer_key` are `VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin`',
            '`source_locator_contract_version` and `importer_version` are `VARCHAR(32)'."\n".
                'CHARACTER SET ascii COLLATE ascii_bin`',
            '`feed_type` is `VARCHAR(16) CHARACTER'."\n".
                'SET ascii COLLATE ascii_bin` and lowercase',
            '`supplier_sku_bytes` is non-null `VARBINARY(1020)` and must be byte-equal to'."\n".
                'the logical head',
        ] as $exactColumnContract) {
            $this->assertStringContainsString($exactColumnContract, $architecture);
        }
        $this->assertSame([
            'schema',
            'supplier_id',
            'supplier_feed_id',
            'source_locator_contract_key',
            'source_locator_contract_version',
            'source_locator_key',
            'source_access_scope_key',
            'feed_type',
            'importer_key',
            'importer_version',
            'mapping_contract_version',
            'mapping_contract_fingerprint',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical source-profile descriptor fields (ordered):',
            'Canonical source-profile descriptor fields',
        ));
        $this->assertSame([
            'schema',
            'source_locator_contract_key',
            'source_locator_contract_version',
            'scheme',
            'ascii_host',
            'port',
            'path_components',
            'query_components',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical non-secret source-locator fields (ordered):',
            'Canonical non-secret source-locator fields',
        ));
        $this->assertSame([
            'schema',
            'source_profile_id',
            'source_identity',
            'source_descriptor_version',
            'source_descriptor_fingerprint',
            'supplier_id',
            'supplier_feed_id',
            'source_locator_contract_key',
            'source_locator_contract_version',
            'source_locator_key',
            'source_locator_canonical_bytes',
            'source_access_scope_key',
            'feed_type',
            'importer_key',
            'importer_version',
            'mapping_contract_version',
            'mapping_canonical_bytes',
            'mapping_contract_fingerprint',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical resolved source context fields (ordered):',
            'Resolved source context fields',
        ));
        $this->assertSame([
            'schema',
            'import_job_id',
            'supplier_id',
            'supplier_feed_id',
            'xml_mapping_template_id',
            'import_type',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical ImportJob identity fields (ordered):',
            'Canonical ImportJob identity fields',
        ));
        $this->assertSame([
            'schema',
            'supplier_id',
            'supplier_feed_id',
            'import_job_id',
            'import_history_id',
            'supplier_import_source_profile_id',
            'source_identity',
            'source_descriptor_version',
            'source_descriptor_fingerprint',
            'import_job_identity_version',
            'import_job_identity_fingerprint',
            'resolved_source_context_version',
            'source_locator_contract_key',
            'source_locator_contract_version',
            'source_locator_key',
            'source_access_scope_key',
            'feed_type',
            'importer_key',
            'importer_version',
            'mapping_contract_version',
            'mapping_contract_fingerprint',
            'captured_at',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical source-execution fingerprint fields (ordered):',
            'Canonical source-execution fingerprint fields',
        ));
        $this->assertSame([
            'schema',
            'supplier_import_source_execution_id',
            'source_execution_fingerprint',
            'accepted_payload_bytes',
            'accepted_payload_sha256',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical source-payload receipt fingerprint fields (ordered):',
            'Canonical source-payload receipt fingerprint fields',
        ));
        $this->assertSame([
            'supplier_import_source_execution_id',
            'source_execution_fingerprint',
            'source_payload_receipt_id',
            'receipt_version',
            'accepted_payload_bytes',
            'accepted_payload_sha256',
            'payload_receipt_fingerprint',
            'payload_storage_kind',
            'payload_file_identity',
            'payload_lifecycle_state',
            'authoritative_read_handle',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Bounded immutable source payload fields (ordered):',
            'Bounded immutable source payload fields',
        ));
        $this->assertSame([
            'supplier_id',
            'supplier_feed_id',
            'supplier_sku_bytes',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical supplier-product logical-head key fields (ordered):',
            'Canonical supplier-product logical-head key fields',
        ));
        $fingerprints = $this->structuralMarkdownTable(
            $architecture,
            '| Fingerprint | Stored authority | Canonical input/version | Comparison and failure |',
            '| :--- | :--- | :--- | --- |',
            'Phase III fingerprint authority',
            4,
            'This design introduces ten future, separately versioned identities:',
        );
        $this->assertSame([], $fingerprints['violations'], implode(PHP_EOL, $fingerprints['violations']));
        $actualFingerprints = [];
        foreach ($fingerprints['rows'] as $position => $row) {
            $parsed = $this->structuralMarkdownRowCells(
                $row,
                4,
                'Phase III fingerprint authority',
                $position + 1,
            );
            $this->assertSame([], $parsed['violations'], implode(PHP_EOL, $parsed['violations']));
            $this->assertNotNull($parsed['cells']);
            $actualFingerprints[] = $parsed['cells'][0];
        }
        $this->assertSame([
            '`mapping_contract_fingerprint`',
            '`source_locator_key`',
            '`source_descriptor_fingerprint`',
            '`import_job_identity_fingerprint`',
            '`source_execution_fingerprint`',
            '`payload_receipt_fingerprint`',
            '`source_member_identity_hash`',
            '`source_row_fingerprint`',
            '`staging_projection_fingerprint`',
            '`revision_fingerprint`',
        ], $actualFingerprints);
        foreach ([
            'current configuration is not historical evidence',
            'supplier_id + supplier_feed_id` is **NO**',
            '`product_supplier_offers.supplier_product_id` is also',
            'Legacy rows keep `current_source_revision_id = NULL` and are ineligible',
            'must not infer provenance from current feed URL/type/mapping',
            'rather than a gap or nonexistent SupplierProduct row lock',
            'legacy_supplier_product_identity_ambiguous',
            'if the insert loses',
            'different fingerprints, B cannot match the descriptor unique',
            'cannot reuse A\'s globally unique `source_identity`',
            'candidate_provenance_missing',
            'candidate_source_mismatch',
            'candidate_revision_mismatch',
            'candidate_projection_mismatch',
        ] as $failClosedProvenance) {
            $this->assertStringContainsString($failClosedProvenance, $architecture);
        }

        $this->assertSame($this->expectedAuthorizationTuple(), $this->canonicalAuthorizationCompletenessTuple($architecture));
        foreach ([
            '`NULL -> A`',
            '`A -> B`',
            '`A -> NULL`',
            'trg_import_execution_claim_cohort_source_immutable',
            'uq_import_execution_claim_id_cohort_source',
            'ix_snapshot_generation_claim_source',
            'fk_snapshot_generation_claim_source',
            'persisted A',
            'Both proofs are mandatory',
            'It is never derived independently from current `SupplierFeed` configuration',
        ] as $sourceBindingAuthority) {
            $this->assertStringContainsString($sourceBindingAuthority, $architecture);
        }

        $bounds = $this->structuralMarkdownTable(
            $architecture,
            '| Bound | Exact semantic, unit and scope | Enforcement and failure | Value/status |',
            '| :--- | --- | --- | ---: |',
            'Phase III operational bounds',
            4,
            'All are hard, application-owned, supplier-invariant limits.',
        );
        $this->assertSame([], $bounds['violations'], implode(PHP_EOL, $bounds['violations']));
        $this->assertSame(10, $bounds['physical_count']);
        $expectedBounds = [
            '`max_source_rows`',
            '`max_source_bytes`',
            '`max_spool_rows`',
            '`max_spool_bytes`',
            '`max_enrollments`',
            '`max_observations`',
            '`max_canonical_children`',
            '`external_sort_chunk`',
            '`db_insert_batch_ceiling`',
            '`snapshot_transaction_bound`',
        ];
        $actualBounds = [];
        foreach ($bounds['rows'] as $position => $row) {
            $parsed = $this->structuralMarkdownRowCells($row, 4, 'Phase III operational bounds', $position + 1);
            $this->assertSame([], $parsed['violations'], implode(PHP_EOL, $parsed['violations']));
            $this->assertNotNull($parsed['cells']);
            $actualBounds[] = $parsed['cells'][0];
            $this->assertSame('`NOT SPECIFIED`', $parsed['cells'][3]);
        }
        $this->assertSame($expectedBounds, $actualBounds);
        $this->assertStringNotContainsString('| `APPROVED` |', $architecture);
        $this->assertStringContainsString(
            '| `external_sort_chunk` | maximum canonical records admitted to one in-memory external-sort run; records/run only |',
            $architecture,
        );
        $this->assertStringNotContainsString('maximum records and their encoded bytes', $architecture);
        $this->assertStringNotContainsString('`H`', $architecture);
        $this->assertStringContainsString('`K` always means records per', $architecture);
        $this->assertStringContainsString('T >= C + 6 for the policy worst case', $architecture);
        $this->assertSame([
            'supplier_offer_snapshot_generation_insert',
            'import_history_terminal_update',
            'import_job_terminal_update',
            'supplier_feed_terminal_update',
            'supplier_import_execution_claim_terminal_update',
            'supplier_import_run_terminal_update_when_orchestrated',
        ], $this->phaseThreeOrderedFields(
            $architecture,
            'Canonical finalization fixed row mutations (ordered):',
            'Canonical finalization fixed row mutations',
        ));
        foreach ([
            'deployment capacity evidence',
            'test limit',
            'staging/preview or diagnostic limit',
            'current dataset evidence unavailable',
            '`capture_overflow`',
            'Silent truncation',
            'unchanged-policy retry are forbidden',
            'supplier_snapshot_operational_bounds_policy_v2',
            'cohort_bounds_policy_key',
            'cohort_bounds_policy_version',
            'cohort_bounds_policy_fingerprint',
            'chk_import_claim_cohort_policy_authority',
            'Retry/recovery always uses the persisted policy A',
            'supplier_import_resolved_source_context_v1',
            'downloadSource(ResolvedSupplierImportSourceContext)',
            'parseSource(',
            'MUST NOT read',
            'EA downloads locator A with redirects disabled and parses mapping A exclusively from context A',
        ] as $boundAuthority) {
            $this->assertStringContainsString($boundAuthority, $architecture);
        }

        $concurrency = $this->structuralMarkdownTable(
            $architecture,
            '| Case | Authority and winner | Loser / retry behavior |',
            '| :--- | --- | ---: |',
            'Phase III concurrency matrix',
            3,
            '#### Crash/protocol integration and closure',
        );
        $this->assertSame([], $concurrency['violations'], implode(PHP_EOL, $concurrency['violations']));
        $this->assertSame(11, $concurrency['physical_count']);
        foreach (range('A', 'K') as $position => $letter) {
            $this->assertStringStartsWith("| {$letter}.", $concurrency['rows'][$position]);
        }

        $architectureStatus = $this->phaseThreeArchitectureStatusContract($architecture);
        $this->assertSame([], $architectureStatus['violations'], implode(PHP_EOL, $architectureStatus['violations']));
        $this->assertSame([
            'PH3-RDY-001' => 'CLOSED IN DESIGN',
            'PH3-RDY-002' => 'CLOSED IN DESIGN',
            'PH3-RDY-003' => 'BLOCKED',
            'PH3-RDY-004' => 'CLOSED',
        ], $architectureStatus['statuses']);
        foreach ([
            'existing 66 by 11 crash matrix keeps its row numbers',
            'existing 19 by 3 protocol matrix needs no new protocol state',
            'frozen 22 identities',
            'Exactly one architecture blocker remains: `PH3-RDY-003`',
        ] as $closureAuthority) {
            $this->assertStringContainsString($closureAuthority, $architecture);
        }

        $futureAllocation = $this->structuralMarkdownTable(
            $plan,
            '| Artifact | Migration/subphase | Dependency | Model/service phase | Activation gate | Rollback/readiness prerequisite |',
            '| --- | --- | --- | --- | --- | --- |',
            'Phase III-P0 provenance allocation',
            6,
            'Forward order is exactly migrations 1, 2, 3, 4, 5, 6, 7A and 7B, followed by',
        );
        $this->assertSame([], $futureAllocation['violations'], implode(PHP_EOL, $futureAllocation['violations']));
        $this->assertSame(9, $futureAllocation['physical_count']);
        foreach ([
            '`supplier_import_source_profiles`',
            '`supplier_import_source_executions`',
            '`supplier_import_source_payload_receipts`',
            '`supplier_product_identity_heads`',
            '`supplier_product_source_revisions`',
            '`supplier_products` identity-head/current-revision pointers, indexes, checks, triggers and composite FKs',
            'claim `cohort_source_identity` plus generation-to-claim source authority',
            'policy-v2 key/version/fingerprint authority on claim/generation',
            '`supplier_import_job_identity_v1`, `supplier_import_resolved_source_context_v1` and same-handle bounded source-payload boundary',
        ] as $position => $artifact) {
            $this->assertStringStartsWith("| {$artifact} |", $futureAllocation['rows'][$position]);
        }
        foreach ([
            'The historical deployed Phase I canonical table count remains exactly ten.',
            'does not amend any Phase I migration',
            'Forward order is exactly migrations 1, 2, 3, 4, 5, 6, 7A and 7B',
            'Phase III-P0 must precede Phase III.',
            'with no approved numeric policy key until all ten values are authorized',
        ] as $allocationAuthority) {
            $this->assertStringContainsString($allocationAuthority, $plan);
        }

        foreach ([
            'app/Models/SupplierImportSourceProfile.php',
            'app/Models/SupplierImportSourceExecution.php',
            'app/Models/SupplierImportSourcePayloadReceipt.php',
            'app/Models/SupplierProductIdentityHead.php',
            'app/Models/SupplierProductSourceRevision.php',
            'config/supplier_snapshot.php',
        ] as $futureRuntimeArtifact) {
            $this->assertFileDoesNotExist(base_path($futureRuntimeArtifact));
        }
    }

    public function test_phase_three_architecture_contract_rejects_shadowing_and_semantic_regressions(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $canonical = $this->phaseThreeArchitectureSemanticContract($design);
        $this->assertSame([], $canonical['violations'], 'AC10 canonical: '.implode(PHP_EOL, $canonical['violations']));
        $block = $canonical['full_block'];
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $statusOne = '| `PH3-RDY-001` | `CLOSED IN DESIGN` | One locking source-resolution boundary locks and fingerprints exact ImportJob selector J plus feed/template A; execution retry never rereads mutable selectors; protected redirects fail closed; one append-only receipt binds accepted SHA-256/size to execution A; same-handle parser EOF verification forbids pathname reopen or undetected replacement; profile/execution/revision, first-insert, admission, concurrency and legacy rules are exact; runtime remains absent. |';
        $statusThree = '| `PH3-RDY-003` | `BLOCKED` | All ten semantics, including bounded decoded source bytes, are exact; all ten numeric values remain `NOT SPECIFIED` pending separately authorized production evidence. |';
        $conflictingBlock = str_replace(
            $statusOne,
            '| `PH3-RDY-001` | `BLOCKED` | Contradictory architecture mutation. |',
            $block,
        );

        $mutations = [
            'AC1 duplicate identical architecture block after canonical block' => $design.$lineEnding.$block,
            'AC2 contradictory duplicate after canonical block' => $design.$lineEnding.$conflictingBlock,
            'AC3 contradictory duplicate before canonical block' => $conflictingBlock.$lineEnding.$design,
            'AC4 malformed second block marker' => $design.$lineEnding.'<!-- phase-iii-architecture-contract:start id=phase-iii-architecture-contract-v1 ->',
            'AC5 Markdown-prefixed malformed marker' => $design.$lineEnding.'- <!-- phase-iii-architecture-contract:start id=phase-iii-architecture-contract-v1 -->',
            'AC6 missing canonical block' => $this->replaceStructuralText($design, $block, ''),
            'AC6A unmarked duplicate architecture heading' => $design.$lineEnding.'### Phase III provenance and bounds architecture decision',
            'AC7 duplicated PH3-RDY-001 closure declaration' => $this->replaceStructuralText(
                $design,
                $statusOne,
                $statusOne.$lineEnding.$statusOne,
            ),
            'AC8 duplicated PH3-RDY-003 semantic declaration' => $this->replaceStructuralText(
                $design,
                $statusThree,
                $statusThree.$lineEnding.$statusThree,
            ),
            'AC9 stale architecture-status declaration outside canonical section' => $design.$lineEnding.'<!-- phase-iii-architecture-contract:status id=phase-iii-architecture-contract-v1 -->',
        ];
        foreach ($mutations as $mutation => $mutatedDesign) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($mutatedDesign)['violations'],
                $mutation,
            );
        }

        $semanticMutations = [
            'P1 descriptor omitted' => [
                'Canonical source-profile descriptor fields (ordered):'.$lineEnding.$lineEnding.'```text'.$lineEnding.
                    'schema'.$lineEnding.'supplier_id'.$lineEnding.'supplier_feed_id'.$lineEnding.
                    'source_locator_contract_key'.$lineEnding.'source_locator_contract_version'.$lineEnding.'source_locator_key',
                'Canonical source-profile descriptor fields (ordered):'.$lineEnding.$lineEnding.'```text'.$lineEnding.
                    'schema'.$lineEnding.'supplier_id'.$lineEnding.'supplier_feed_id'.$lineEnding.
                    'source_locator_contract_key'.$lineEnding.'source_locator_key',
            ],
            'P2 source execution descriptor omitted' => [
                'source_descriptor_version'.$lineEnding.'source_descriptor_fingerprint'.$lineEnding.
                    'import_job_identity_version'.$lineEnding.'import_job_identity_fingerprint',
                'source_descriptor_version'.$lineEnding.'import_job_identity_version'.$lineEnding.
                    'import_job_identity_fingerprint',
            ],
            'P3 execution fingerprint storage undefined' => [
                'source_execution_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin',
                'source execution digest',
            ],
            'P4 A to B identity reuse allowed' => [
                'cannot reuse A\'s globally unique `source_identity`',
                'may reuse A\'s globally unique `source_identity`',
            ],
            'P5 source profile registry omitted' => [
                'The sole future registry is `supplier_import_source_profiles`.',
                'No source profile registry is selected.',
            ],
            'P6 first insert authority omitted' => [
                'The sole first-insert coordination authority is the append-only',
                'No first-insert coordination authority is selected; the append-only',
            ],
            'P7 nonexistent row lock accepted' => [
                'rather than a gap or nonexistent SupplierProduct row lock',
                'using a nonexistent SupplierProduct row lock',
            ],
            'P8 legacy provenance fabricated' => [
                'Migration must not infer provenance from current feed URL/type/mapping',
                'Migration may infer provenance from current feed URL/type/mapping',
            ],
            'B1 external sort mixes rows and bytes' => [
                'maximum canonical records admitted to one in-memory external-sort run; records/run only',
                'maximum canonical records and bytes admitted to one in-memory external-sort run',
            ],
            'B2 undefined H restored' => [
                'T >= C + 6 for the policy worst case',
                'T >= 1 + C + H',
            ],
            'B3 fixed mutations omitted' => [
                'Canonical finalization fixed row mutations (ordered):',
                'Finalization mutations:',
            ],
            'B4 transaction unit ambiguous' => [
                'rows/transaction, never statements, bytes or time',
                'rows, statements, bytes or time per transaction',
            ],
            'B5 numeric bound guessed' => [
                '| `max_source_rows` | physical parser records emitted for one execution, including valid, invalid and duplicate records; rows | hard check before accepting the next record; overflow aborts source processing | `NOT SPECIFIED` |',
                '| `max_source_rows` | physical parser records emitted for one execution, including valid, invalid and duplicate records; rows | hard check before accepting the next record; overflow aborts source processing | `5000` |',
            ],
            'B6 partial success allowed' => [
                'overflow never opens or commits a partial transaction',
                'overflow may commit a partial transaction',
            ],
        ];
        foreach ($semanticMutations as $mutation => [$search, $replacement]) {
            $this->assertSame(1, substr_count($design, $search), "{$mutation}: mutation target must be unique.");
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract(
                    str_replace($search, $replacement, $design),
                )['violations'],
                $mutation,
            );
        }
    }

    public function test_phase_three_payload_receipt_contract_is_exact_and_fail_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";

        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'P0 canonical payload contract');

        $receiptFields = implode($lineEnding, [
            'schema',
            'supplier_import_source_execution_id',
            'source_execution_fingerprint',
            'accepted_payload_bytes',
            'accepted_payload_sha256',
        ]);
        $mutations = [
            'P1 mode alone is treated as immutable proof' => [
                'Mode 0600 alone is never treated as immutability proof; digest verification is',
                'Mode 0600 alone is treated as immutability proof; digest verification is optional and',
            ],
            'P2 parser may reopen pathname' => [
                'No protected parser/service may reopen payload contents by pathname.',
                'A protected parser may reopen payload contents by pathname.',
            ],
            'P3 receipt byte count omitted' => [
                $receiptFields,
                implode($lineEnding, [
                    'schema',
                    'supplier_import_source_execution_id',
                    'source_execution_fingerprint',
                    'accepted_payload_sha256',
                ]),
            ],
            'P4 receipt SHA-256 omitted' => [
                $receiptFields,
                implode($lineEnding, [
                    'schema',
                    'supplier_import_source_execution_id',
                    'source_execution_fingerprint',
                    'accepted_payload_bytes',
                ]),
            ],
            'P5 receipt execution binding omitted' => [
                $receiptFields,
                implode($lineEnding, [
                    'schema',
                    'supplier_import_source_execution_id',
                    'accepted_payload_bytes',
                    'accepted_payload_sha256',
                ]),
            ],
            'P6 committed receipt replacement allowed' => [
                'partial digest/size visibility, UPDATE and DELETE are forbidden.',
                'replacement after receipt commit is allowed.',
            ],
            'P7 parser consumption verification omitted' => [
                'A verification wrapper reads from the same handle, recomputes byte count',
                'The parser trusts receipt metadata without recomputing byte count',
            ],
            'P8 parser succeeds before verified EOF' => [
                'Early parser success before verified EOF is forbidden.',
                'Early parser success before verified EOF is allowed.',
            ],
            'P9 pathname is immutable identity' => [
                'metadata, not persisted receipt identity and not a pathname.',
                'metadata; the pathname is the persisted immutable payload identity.',
            ],
            'P10 retry trusts leftover pathname' => [
                'no leftover pathname is trusted',
                'a leftover pathname is trusted',
            ],
        ];

        foreach ($mutations as $mutation => [$search, $replacement]) {
            $mutated = $this->replaceStructuralText($design, $search, $replacement);
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($mutated)['violations'],
                $mutation,
            );
        }
    }

    public function test_phase_three_import_job_selector_contract_is_exact_and_fail_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";

        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'J0 canonical ImportJob selector contract');

        $jobFields = implode($lineEnding, [
            'schema',
            'import_job_id',
            'supplier_id',
            'supplier_feed_id',
            'xml_mapping_template_id',
            'import_type',
        ]);
        $executionIdentityFields = implode($lineEnding, [
            'source_descriptor_fingerprint',
            'import_job_identity_version',
            'import_job_identity_fingerprint',
            'resolved_source_context_version',
        ]);
        $mutations = [
            'J1 ImportJob row is not locked' => [
                'locks the exact ImportJob row with `SELECT ... FOR UPDATE`',
                'reads the ImportJob row without a locking read',
            ],
            'J2 template selector omitted from identity' => [
                $jobFields,
                implode($lineEnding, [
                    'schema',
                    'import_job_id',
                    'supplier_id',
                    'supplier_feed_id',
                    'import_type',
                ]),
            ],
            'J3 ImportJob identity fields unspecified' => [
                'Canonical ImportJob identity fields (ordered):',
                'ImportJob identity fields are implementation-defined:',
            ],
            'J4 selector relationships verified after transaction' => [
                'performs exactly this sequence:',
                'commits before selector relationships are verified:',
            ],
            'J5 retry rereads current ImportJob' => [
                'reconstructs A without rereading current ImportJob/feed/template selectors,',
                'reconstructs A by rereading current ImportJob/feed/template selectors,',
            ],
            'J6 mutable template becomes historical mapping authority' => [
                'canonical mapping bytes contain every effective parser instruction.',
                'current mutable template becomes historical mapping authority.',
            ],
            'J7 source execution omits ImportJob identity' => [
                $executionIdentityFields,
                implode($lineEnding, [
                    'source_descriptor_fingerprint',
                    'resolved_source_context_version',
                ]),
            ],
            'J8 feed and template locks declared sufficient' => [
                'then, only for XML, `xml_mapping_templates` by primary key.',
                'then `xml_mapping_templates`; feed/template locks are sufficient without locking ImportJob.',
            ],
        ];

        foreach ($mutations as $mutation => [$search, $replacement]) {
            $mutated = $this->replaceStructuralText($design, $search, $replacement);
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($mutated)['violations'],
                $mutation,
            );
        }
    }

    public function test_phase_three_p0_rollback_table_sets_are_exact_and_fail_closed(): void
    {
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $lineEnding = str_contains($plan, "\r\n") ? "\r\n" : "\n";
        $canonical = $this->phaseThreeP0RollbackSetContract($plan);
        $this->assertSame([], $canonical['violations'], 'RB10 canonical: '.implode(PHP_EOL, $canonical['violations']));
        $this->assertSame($this->expectedPhaseThreeP0Tables(), $canonical['allocation_set']);
        $this->assertSame($this->expectedPhaseThreeP0Tables(), $canonical['guard_set']);

        $allocationReceipt = '| 3 | `supplier_import_source_payload_receipts` |';
        $allocationReceiptBlock = $allocationReceipt.$lineEnding.
            '| 4 | `supplier_product_identity_heads` |';
        $guardProfile = '| 1 | `supplier_import_source_profiles` | table exists and contains zero rows; all dependent executions are already proven absent |';
        $guardExecution = '| 2 | `supplier_import_source_executions` | table exists and contains zero rows; all dependent receipts and revisions are already proven absent |';
        $guardReceipt = '| 3 | `supplier_import_source_payload_receipts` | table exists and contains zero rows; no committed payload receipt evidence may be discarded |';
        $guardHead = '| 4 | `supplier_product_identity_heads` | table exists and contains zero rows; all staging head pointers are separately proven null |';
        $guardRevision = '| 5 | `supplier_product_source_revisions` | table exists and contains zero rows; all staging current-revision pointers are separately proven null |';
        $unknownGuard = '| 6 | `supplier_import_unknown_evidence` | table exists and contains zero rows |';

        $rb5 = $this->removeStructuralRow($plan, $guardReceipt);
        $rb5 = $this->insertStructuralRow($rb5, $guardProfile, $guardProfile);
        $mutations = [
            'RB1 payload receipt omitted from guard' => $this->removeStructuralRow($plan, $guardReceipt),
            'RB2 source profiles omitted from guard' => $this->removeStructuralRow($plan, $guardProfile),
            'RB3 allocation five and guard four' => $this->removeStructuralRow($plan, $guardHead),
            'RB4 unknown sixth guard table' => $this->insertStructuralRow($plan, $guardRevision, $unknownGuard),
            'RB5 duplicate guard table and omitted table' => $rb5,
            'RB7 allocation changes without guard' => $this->replaceStructuralText(
                $plan,
                $allocationReceiptBlock,
                '| 3 | `supplier_import_source_payload_receipt_blobs` |'.$lineEnding.
                    '| 4 | `supplier_product_identity_heads` |',
            ),
            'RB8 guard changes without allocation' => $this->replaceStructuralText(
                $plan,
                $guardExecution,
                '| 2 | `supplier_import_source_execution_archive` | table exists and contains zero rows |',
            ),
            'RB9 stale four-table prose' => $this->replaceStructuralText(
                $plan,
                'five structurally registered new tables',
                'four new tables',
            ),
        ];

        foreach ($mutations as $case => $mutatedPlan) {
            $this->assertNotSame(
                [],
                $this->phaseThreeP0RollbackSetContract($mutatedPlan)['violations'],
                $case,
            );
        }

        $this->assertSame([], $this->phaseThreeP0RollbackSetContract($plan)['violations'], 'RB6 exact set equality');
        $this->assertSame([], $this->phaseThreeP0RollbackSetContract($plan.$lineEnding)['violations'], 'RB10 canonical document');
    }

    public function test_phase_three_semantic_registries_reject_current_coexistence_contradictions(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $canonical = $this->phaseThreeExclusiveSemanticContract($design);
        $this->assertSame([], $canonical['violations'], 'CO19 canonical: '.implode(PHP_EOL, $canonical['violations']));

        $payloadRegistry = $this->phaseThreeSemanticRegistryBlock(
            $design,
            'phase-iii-payload-integrity-contract-v1',
        );
        $rejected = [
            'CO1 contradictory receipt relation' => ['phase-iii-payload-integrity-contract-v1', 'A payload receipt may belong to a different source execution.'],
            'CO2 SHA-1 payload digest' => ['phase-iii-payload-integrity-contract-v1', 'The payload receipt digest uses SHA-1.'],
            'CO3 pathname reopen allowed' => ['phase-iii-payload-integrity-contract-v1', 'The protected parser may reopen the temporary payload by pathname.'],
            'CO4 parser success before EOF' => ['phase-iii-payload-integrity-contract-v1', 'The parser may return protected success before complete EOF digest verification.'],
            'CO5 template selector omitted' => ['phase-iii-import-job-selector-contract-v1', 'The xml_mapping_template_id is optional in ImportJobIdentity.'],
            'CO6 reversed lock order' => ['phase-iii-import-job-selector-contract-v1', 'Current lock authority: SupplierFeed -> ImportJob -> XML mapping template.'],
            'CO7 mutable template reread' => ['phase-iii-import-job-selector-contract-v1', 'Execution A may reread the current mutable mapping template after source-resolution commit.'],
            'CO8 receipt replacement allowed' => ['phase-iii-payload-integrity-contract-v1', 'A committed payload receipt may be replaced by a later byte-valid receipt.'],
            'CO9 MD5 payload digest' => ['phase-iii-payload-integrity-contract-v1', 'The payload receipt digest algorithm is MD5.'],
            'CO10 receipt binding optional' => ['phase-iii-payload-integrity-contract-v1', 'Payload receipt execution binding is optional.'],
            'CO11 retry pathname reopen' => ['phase-iii-payload-integrity-contract-v1', 'The protected parser may reopen the payload path only on retry.'],
            'CO12 EOF verification advisory' => ['phase-iii-payload-integrity-contract-v1', 'Parser EOF receipt verification is advisory rather than mandatory.'],
            'CO13 ImportJob row lock optional' => ['phase-iii-import-job-selector-contract-v1', 'The ImportJob row lock is optional during source resolution.'],
            'CO14 selector outside transaction' => ['phase-iii-import-job-selector-contract-v1', 'The template selector may be verified outside the source-resolution transaction.'],
            'CO15 retry current ImportJob reread' => ['phase-iii-import-job-selector-contract-v1', 'Retry may reread the current ImportJob when source_identity matches.'],
            'CO16 duplicate canonical semantic registry' => [null, $payloadRegistry],
        ];
        foreach ($rejected as $case => [$registryId, $fragment]) {
            $mutated = $registryId === null
                ? $design.$lineEnding.$fragment
                : $this->insertPhaseThreeSemanticAuthorityUnit($design, $registryId, $fragment);
            $this->assertNotSame(
                [],
                $this->phaseThreeExclusiveSemanticContract($mutated)['violations'],
                $case,
            );
        }

        $historical = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-semantic-history-v1 -->'.$lineEnding.
            'An earlier rejected draft considered SHA-1 for the payload receipt digest.';
        $historicalRegistry = $design.$lineEnding.str_replace(
            [
                'classification=CURRENT id=phase-iii-payload-integrity-contract-v1',
                'id=phase-iii-payload-integrity-contract-v1',
            ],
            [
                'classification=HISTORICAL id=phase-iii-payload-integrity-contract-v0',
                'id=phase-iii-payload-integrity-contract-v0',
            ],
            $payloadRegistry,
        );
        $literal = $design.$lineEnding.
            '<!-- phase-iii-architecture-example:start -->'.$lineEnding.
            'Mutation example only: the protected parser may reopen the payload path.'.$lineEnding.
            '<!-- phase-iii-architecture-example:end -->';
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($historical)['violations'], 'CO17 appended historical alternative is outside the canonical inventory');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($historicalRegistry)['violations'], 'CO17B appended historical registry is outside the canonical inventory');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($literal)['violations'], 'CO18 appended literal example is outside the canonical inventory');
        $this->assertStringContainsString(
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-readiness-remediation-v1 -->',
            $design,
            'Canonical historical content remains protected in place.',
        );
        $this->assertStringContainsString('```text', $design, 'Canonical literal content remains protected in place.');
        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($design)['violations'], 'CO19 canonical document');
    }

    public function test_phase_three_semantic_registries_reject_direct_structural_mutations(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $mutations = [
            'SR1 SHA-1 registry value' => ['| `payload_digest_algorithm` | `SHA-256` |', '| `payload_digest_algorithm` | `SHA-1` |'],
            'SR2 pathname reopen allowed' => ['| `payload_path_reopen` | `FORBIDDEN` |', '| `payload_path_reopen` | `ALLOWED` |'],
            'SR3 pre-EOF success allowed' => ['| `parser_success_before_full_eof_verification` | `FORBIDDEN` |', '| `parser_success_before_full_eof_verification` | `ALLOWED` |'],
            'SR4 receipt replacement allowed' => ['| `receipt_mutability` | `APPEND_ONLY_NO_UPDATE_REPLACE_CLEAR_DELETE` |', '| `receipt_mutability` | `REPLACEMENT_ALLOWED` |'],
            'SR5 template selector removed from identity' => [
                '`schema>import_job_id>supplier_id>supplier_feed_id>xml_mapping_template_id>import_type`',
                '`schema>import_job_id>supplier_id>supplier_feed_id>import_type`',
            ],
            'SR6 reversed lock order' => [
                '| `lock_order` | `IMPORT_JOB>SUPPLIER_FEED>XML_MAPPING_TEMPLATE` |',
                '| `lock_order` | `SUPPLIER_FEED>IMPORT_JOB>XML_MAPPING_TEMPLATE` |',
            ],
            'SR7 mutable template reread allowed' => [
                '| `mutable_template_reread_after_commit` | `FORBIDDEN_FOR_HISTORICAL_AUTHORITY` |',
                '| `mutable_template_reread_after_commit` | `ALLOWED` |',
            ],
        ];
        foreach ($mutations as $case => [$search, $replacement]) {
            $mutated = $this->replaceStructuralText($design, $search, $replacement);
            $this->assertNotSame(
                [],
                $this->phaseThreeExclusiveSemanticContract($mutated)['violations'],
                $case,
            );
        }

        $payloadRow = '| `receipt_cardinality` | `EXACTLY_ONE_PER_SOURCE_EXECUTION` |';
        $structuralFailures = [
            'missing required semantic key' => $this->removeStructuralRow($design, $payloadRow),
            'duplicate identical semantic key' => $this->insertStructuralRow($design, $payloadRow, $payloadRow),
            'unknown semantic key' => $this->insertStructuralRow(
                $design,
                $payloadRow,
                '| `unknown_payload_authority` | `FORBIDDEN` |',
            ),
            'malformed semantic registry marker' => $this->replaceStructuralText(
                $design,
                '<!-- phase-iii-semantic-registry:end id=phase-iii-payload-integrity-contract-v1 -->',
                '<!-- phase-iii-semantic-registry:end id=phase-iii-payload-integrity-contract-v1 ->',
            ),
        ];
        foreach ($structuralFailures as $case => $mutated) {
            $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($mutated)['violations'], $case);
        }

        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($design)['violations'], 'SR8 canonical registries');
    }

    public function test_phase_three_semantic_authority_is_closed_world_without_synonym_interpretation(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $canonical = $this->phaseThreeExclusiveSemanticContract($design);
        $normalizedDesign = preg_replace('/\s+/', ' ', $design) ?? $design;

        $this->assertSame([], $canonical['violations'], implode(PHP_EOL, $canonical['violations']));
        $this->assertStringContainsString(
            'only the exact header, separator, ten canonical rows, optional blank lines and closing marker are permitted',
            $normalizedDesign,
        );
        $this->assertStringContainsString(
            'only the exact header, separator, eleven canonical rows, optional blank lines and closing marker are permitted',
            $normalizedDesign,
        );
        $this->assertSame(
            2,
            substr_count($normalizedDesign, 'all text outside the registry is REFERENCE/EXPLANATION only'),
        );
        $this->assertSame([
            'raw_blocks' => 1,
            'current_blocks' => 1,
            'physical_rows' => 10,
            'parsed_rows' => 10,
            'unique_keys' => 10,
            'expected_keys' => 10,
            'unexpected_units' => 0,
        ], $canonical['payload_inventory']);
        $this->assertSame([
            'raw_blocks' => 1,
            'current_blocks' => 1,
            'physical_rows' => 11,
            'parsed_rows' => 11,
            'unique_keys' => 11,
            'expected_keys' => 11,
            'unexpected_units' => 0,
        ], $canonical['selector_inventory']);

        $perKeyContradictions = [
            'receipt_cardinality' => ['phase-iii-payload-integrity-contract-v1', 'More than one completed proof may be attached to a run.'],
            'receipt_execution_binding' => ['phase-iii-payload-integrity-contract-v1', 'A byte proof can follow its payload into a successor run.'],
            'payload_digest_algorithm' => ['phase-iii-payload-integrity-contract-v1', 'The byte proof uses secure hash algorithm 1.'],
            'payload_digest_domain' => ['phase-iii-payload-integrity-contract-v1', 'The checksum may cover normalized parser records instead of original input octets.'],
            'payload_path_reopen' => ['phase-iii-payload-integrity-contract-v1', 'The parser may open the file again by filename.'],
            'parser_success_before_full_eof_verification' => ['phase-iii-payload-integrity-contract-v1', 'Complete stream consumption is optional before success.'],
            'receipt_mutability' => ['phase-iii-payload-integrity-contract-v1', 'A committed receipt can be edited in place.'],
            'receipt_rebinding' => ['phase-iii-payload-integrity-contract-v1', 'Ownership of recorded byte proof may be reassigned.'],
            'parser_receipt_verification' => ['phase-iii-payload-integrity-contract-v1', 'Matching the recorded byte proof is advisory at parser completion.'],
            'authoritative_handle_identity' => ['phase-iii-payload-integrity-contract-v1', 'A newly opened descriptor may replace the verified object.'],
            'identity_ordered_fields' => ['phase-iii-import-job-selector-contract-v1', 'The selector fingerprint field sequence may vary.'],
            'required_template_selector' => ['phase-iii-import-job-selector-contract-v1', 'The template selector is advisory for XML work.'],
            'lock_order' => ['phase-iii-import-job-selector-contract-v1', 'Acquire the template, then the feed, then the job.'],
            'import_job_row_locking' => ['phase-iii-import-job-selector-contract-v1', 'ImportJob locking is advisory.'],
            'supplier_feed_row_locking' => ['phase-iii-import-job-selector-contract-v1', 'The feed may be read without an ownership lock.'],
            'template_row_locking' => ['phase-iii-import-job-selector-contract-v1', 'The mapping template need not be locked.'],
            'selector_verification_boundary' => ['phase-iii-import-job-selector-contract-v1', 'Relationship checks may finish in a later transaction.'],
            'mapping_snapshot_authority' => ['phase-iii-import-job-selector-contract-v1', 'Historical mapping may be rebuilt from the latest template.'],
            'mutable_template_reread_after_commit' => ['phase-iii-import-job-selector-contract-v1', 'Completed executions may consult the newest mapping definition.'],
            'retry_current_selector_reread' => ['phase-iii-import-job-selector-contract-v1', 'A retry may consult whatever selectors the job has now.'],
            'source_execution_identity_binding' => ['phase-iii-import-job-selector-contract-v1', 'A source execution does not need the captured job identity.'],
        ];
        $this->assertCount(21, $perKeyContradictions);
        foreach ($perKeyContradictions as $key => [$registryId, $fragment]) {
            $contract = $this->phaseThreeExclusiveSemanticContract(
                $this->insertPhaseThreeSemanticAuthorityUnit($design, $registryId, $fragment),
            );
            $inventory = str_contains($registryId, 'payload')
                ? $contract['payload_inventory']
                : $contract['selector_inventory'];

            $this->assertNotSame([], $contract['violations'], "Closed-world per-key mutation must fail: {$key}");
            $this->assertSame(1, $inventory['unexpected_units'], "Mutation must fail structurally: {$key}");
        }

        $unseenContradictions = [
            ['phase-iii-payload-integrity-contract-v1', 'A lunar cycle chooses the checksum family for each run.'],
            ['phase-iii-payload-integrity-contract-v1', 'Proof ownership can migrate when the moon is full.'],
            ['phase-iii-payload-integrity-contract-v1', 'The reader may swap to a fresh object after the first byte.'],
            ['phase-iii-payload-integrity-contract-v1', 'A green light may precede the final stream symbol.'],
            ['phase-iii-payload-integrity-contract-v1', 'Recorded evidence can be polished after acceptance.'],
            ['phase-iii-import-job-selector-contract-v1', 'The three guardians may be visited in any sequence.'],
            ['phase-iii-import-job-selector-contract-v1', 'Yesterday may be reconstructed from today\'s mapping.'],
            ['phase-iii-import-job-selector-contract-v1', 'A later database visit may settle selector relationships.'],
            ['phase-iii-import-job-selector-contract-v1', 'The execution may borrow identity from a neighboring job.'],
            ['phase-iii-import-job-selector-contract-v1', 'XML can proceed when its template coordinate is merely suggested.'],
        ];
        foreach ($unseenContradictions as $index => [$registryId, $fragment]) {
            $contract = $this->phaseThreeExclusiveSemanticContract(
                $this->insertPhaseThreeSemanticAuthorityUnit($design, $registryId, $fragment),
            );
            $inventory = str_contains($registryId, 'payload')
                ? $contract['payload_inventory']
                : $contract['selector_inventory'];

            $this->assertNotSame([], $contract['violations'], 'Unseen contradiction must fail: '.($index + 1));
            $this->assertSame(1, $inventory['unexpected_units'], 'Unseen text must fail structurally: '.($index + 1));
        }

        foreach ([
            'payload arbitrary text' => ['phase-iii-payload-integrity-contract-v1', 'The moon determines this policy.'],
            'selector arbitrary text' => ['phase-iii-import-job-selector-contract-v1', 'A blue square is recorded here.'],
            'payload arbitrary structural row' => ['phase-iii-payload-integrity-contract-v1', '| `unknown_payload_rule` | `BLUE_SQUARE` |'],
            'selector arbitrary structural row' => ['phase-iii-import-job-selector-contract-v1', '| `unknown_selector_rule` | `BLUE_SQUARE` |'],
        ] as $case => [$registryId, $fragment]) {
            $contract = $this->phaseThreeExclusiveSemanticContract(
                $this->insertPhaseThreeSemanticAuthorityUnit($design, $registryId, $fragment),
            );
            $inventory = str_contains($registryId, 'payload')
                ? $contract['payload_inventory']
                : $contract['selector_inventory'];

            $this->assertNotSame([], $contract['violations'], $case);
            $this->assertSame(1, $inventory['unexpected_units'], "{$case} must fail structurally");
        }

        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $historical = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-closed-world-history-v1 -->'.$lineEnding.
            'An earlier rejected design used a different checksum family.';
        $literal = $design.$lineEnding.
            '<!-- phase-iii-architecture-example:start -->'.$lineEnding.
            'Literal mutation example: the parser obtains a replacement object.'.$lineEnding.
            '<!-- phase-iii-architecture-example:end -->';
        $reference = $design.$lineEnding.
            'See payload semantic registry key `payload_digest_algorithm` and conform to selector key `lock_order`.';

        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($historical)['violations'], 'Appended historical content is not canonical');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($literal)['violations'], 'Appended literal content is not canonical');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($reference)['violations'], 'Appended reference content is not canonical');
        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($design)['violations'], 'Canonical control');
    }

    public function test_phase_three_current_architecture_authority_is_fully_closed_world(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $canonical = $this->phaseThreeExclusiveSemanticContract($design);
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $authorityMarker = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->';
        $contractStart = '<!-- phase-iii-architecture-contract:start id=phase-iii-architecture-contract-v1 -->';
        $contractEnd = '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v1 -->';
        $selectorStart = '<!-- phase-iii-semantic-registry classification=CURRENT id=phase-iii-import-job-selector-contract-v1 -->';
        $selectorEnd = '<!-- phase-iii-semantic-registry:end id=phase-iii-import-job-selector-contract-v1 -->';
        $payloadStart = '<!-- phase-iii-semantic-registry classification=CURRENT id=phase-iii-payload-integrity-contract-v1 -->';
        $payloadEnd = '<!-- phase-iii-semantic-registry:end id=phase-iii-payload-integrity-contract-v1 -->';

        $this->assertSame([], $canonical['violations'], implode(PHP_EOL, $canonical['violations']));
        $this->assertSame('CANONICAL_CURRENT_ARCHITECTURE', $canonical['current_architecture_inventory']['classification']);
        $this->assertSame(194, $canonical['current_architecture_inventory']['unit_count']);
        $this->assertSame(16, $canonical['candidate_count']);

        $outsideContradictions = [
            'REV007-C01 alternate digest family' => [$selectorStart, false, 'For byte sealing, family one is controlling.'],
            'REV007-C02 mutable historical selector' => [$selectorEnd, true, 'Historical selector truth follows whichever template is newest.'],
            'REV007-C03 reverse acquisition' => [$payloadStart, false, 'It is permissible to acquire the template before the feed and job.'],
            'REV007-C04 transferable proof' => [$payloadEnd, true, 'A completed proof can be inherited by a neighboring run.'],
            'REV007-C05 premature acceptance' => [$contractEnd, false, 'Green approval may be issued before the final symbol is observed.'],
            'REV007-C06 replacement object' => [$selectorStart, false, 'A replacement object may take over after reading begins.'],
            'REV007-C07 latest stencil authority' => [$selectorEnd, true, 'The latest stencil governs yesterday\'s reconstruction.'],
            'REV007-C08 reversed custodians' => [$payloadStart, false, 'Three custodians may be visited in reverse sequence.'],
            'REV007-C09 deferred relationship truth' => [$payloadEnd, true, 'A later visit may settle relational truth outside the first boundary.'],
            'REV007-C10 advisory markup coordinate' => [$contractEnd, false, 'The coordinate for markup work is advisory.'],
        ];
        foreach ($outsideContradictions as $case => [$anchor, $after, $fragment]) {
            $this->assertSame([], $this->phaseThreeSemanticAssertions($fragment), "{$case}: lexical detector must return zero candidates.");
            $mutated = $this->insertPhaseThreeCurrentArchitectureUnit($design, $anchor, $fragment, $after);
            $contract = $this->phaseThreeExclusiveSemanticContract($mutated);

            $this->assertNotSame([], $contract['violations'], "{$case}: outside-registry CURRENT contradiction must fail structurally.");
            $this->assertSame('STRUCTURAL_MISMATCH', $contract['current_architecture_inventory']['classification'], "{$case}: structural classification");
            $this->assertSame($canonical['candidate_count'], $contract['candidate_count'], "{$case}: rejection must not depend on lexical discovery.");
        }

        $arbitraryText = [
            'REV007-A01 before selector' => [$selectorStart, false, 'Blue notebooks are preferred during winter.'],
            'REV007-A02 after selector' => [$selectorEnd, true, 'Copper triangles rest beside quiet windows.'],
            'REV007-A03 before payload' => [$payloadStart, false, 'Seven chairs face the eastern wall.'],
            'REV007-A04 after payload' => [$payloadEnd, true, 'A paper kite crossed the empty courtyard.'],
            'REV007-A05 before authority end' => [$contractEnd, false, 'Violet ribbons remain folded at noon.'],
        ];
        foreach ($arbitraryText as $case => [$anchor, $after, $fragment]) {
            $this->assertSame([], $this->phaseThreeSemanticAssertions($fragment), "{$case}: lexical detector must return zero candidates.");
            $contract = $this->phaseThreeExclusiveSemanticContract(
                $this->insertPhaseThreeCurrentArchitectureUnit($design, $anchor, $fragment, $after),
            );

            $this->assertNotSame([], $contract['violations'], "{$case}: arbitrary CURRENT text must fail structurally.");
            $this->assertSame('STRUCTURAL_MISMATCH', $contract['current_architecture_inventory']['classification'], "{$case}: structural classification");
            $this->assertSame($canonical['candidate_count'], $contract['candidate_count'], "{$case}: rejection must remain wording-independent.");
        }

        $referenceSentence = 'Natural-language'.$lineEnding.'semantic discovery is diagnostic only and is never acceptance authority.';
        $referenceLine = 'semantic discovery is diagnostic only and is never acceptance authority.';
        $referenceTampering = [
            'REV007-R01 append sentence' => $this->replaceStructuralText(
                $design,
                $referenceSentence,
                $referenceSentence.' Blue notebooks remain authoritative.',
            ),
            'REV007-R02 prepend sentence' => $this->replaceStructuralText(
                $design,
                $referenceSentence,
                'Blue notebooks remain authoritative. '.$referenceSentence,
            ),
            'REV007-R03 replace sentence' => $this->replaceStructuralText(
                $design,
                $referenceSentence,
                'Natural-language interpretation controls acceptance.',
            ),
            'REV007-R04 delete sentence' => $this->replaceStructuralText($design, $referenceLine.$lineEnding, ''),
            'REV007-R05 inject contradiction' => $this->replaceStructuralText(
                $design,
                $referenceSentence,
                $referenceSentence.' Unregistered prose may override it.',
            ),
        ];
        $movedReference = $this->replaceStructuralText($design, $referenceLine.$lineEnding, '');
        $referenceTampering['REV007-R06 move sentence between units'] = $this->insertPhaseThreeCurrentArchitectureUnit(
            $movedReference,
            $payloadStart,
            $referenceLine,
        );
        foreach ($referenceTampering as $case => $mutated) {
            $contract = $this->phaseThreeExclusiveSemanticContract($mutated);

            $this->assertNotSame([], $contract['violations'], "{$case}: exact canonical reference/explanation content must be protected.");
            $this->assertSame('STRUCTURAL_MISMATCH', $contract['current_architecture_inventory']['classification'], "{$case}: structural classification");
        }

        $canonicalHeading = '#### Current runtime and lineage inventory';
        $secondHeading = '#### Provenance alternatives and canonical selection';
        $reordered = $this->replaceStructuralText($design, $canonicalHeading, '__PHASE_THREE_CURRENT_HEADING_SWAP__');
        $reordered = $this->replaceStructuralText($reordered, $secondHeading, $canonicalHeading);
        $reordered = $this->replaceStructuralText($reordered, '__PHASE_THREE_CURRENT_HEADING_SWAP__', $secondHeading);
        $currentBlock = $this->phaseThreeCurrentArchitectureBlock($design);
        $movedOutside = $this->replaceStructuralText($design, $canonicalHeading.$lineEnding, '');
        $movedOutside = $this->insertPhaseThreeCurrentArchitectureUnit($movedOutside, $contractEnd, $canonicalHeading, true);
        $structuralMutations = [
            'REV007-S01 unknown block' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractEnd, '<!-- phase-iii-current-unknown id=blue-square -->'),
            'REV007-S02 missing block' => $this->replaceStructuralText($design, $canonicalHeading.$lineEnding, ''),
            'REV007-S03 duplicate block' => $this->replaceStructuralText($design, $canonicalHeading, $canonicalHeading.$lineEnding.$lineEnding.$canonicalHeading),
            'REV007-S04 renamed block' => $this->replaceStructuralText($design, $canonicalHeading, '#### Current runtime and source inventory'),
            'REV007-S05 wrong category' => $this->replaceStructuralText($design, $canonicalHeading, 'Current runtime and lineage inventory'),
            'REV007-S06 malformed start marker' => $this->replaceStructuralText($design, $contractStart, '<!-- phase-iii-architecture-contract:start id=phase-iii-architecture-contract-v1 ->'),
            'REV007-S07 malformed end marker' => $this->replaceStructuralText($design, $contractEnd, '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v1 ->'),
            'REV007-S08 mismatched markers' => $this->replaceStructuralText($design, $contractEnd, '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v2 -->'),
            'REV007-S09 content between blocks' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $secondHeading, 'A quiet sentence occupies an unregistered boundary.'),
            'REV007-S10 content before first unit' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractStart, 'Unregistered content precedes the canonical start.'),
            'REV007-S11 content after last unit' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractEnd, 'Unregistered content follows the final canonical body unit.'),
            'REV007-S12 duplicate CURRENT authority' => $design.$lineEnding.$currentBlock,
            'REV007-S13 reordered units' => $reordered,
            'REV007-S14 unit moved outside boundaries' => $movedOutside,
            'REV007-S15 malformed authority marker' => $this->replaceStructuralText($design, $authorityMarker, '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 ->'),
        ];
        foreach ($structuralMutations as $case => $mutated) {
            $contract = $this->phaseThreeExclusiveSemanticContract($mutated);

            $this->assertNotSame([], $contract['violations'], "{$case}: structural mutation must fail closed.");
            $this->assertNotSame('CANONICAL_CURRENT_ARCHITECTURE', $contract['current_architecture_inventory']['classification'], "{$case}: structural classification");
        }

        $historical = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-rev-007-history-v1 -->'.$lineEnding.
            'A prior design permitted prose outside a closed inventory.';
        $superseded = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=SUPERSEDED id=phase-iii-rev-007-superseded-v1 -->'.$lineEnding.
            'A superseded design used lexical interpretation as authority.';
        $literal = $design.$lineEnding.
            '<!-- phase-iii-architecture-example:start -->'.$lineEnding.
            'Literal example: arbitrary CURRENT prose is accepted.'.$lineEnding.
            '<!-- phase-iii-architecture-example:end -->';
        $reference = $design.$lineEnding.
            'See the canonical CURRENT architecture byte and unit inventory for the governing contract.';

        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($historical)['violations'], 'REV007 appended historical content is not canonical');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($superseded)['violations'], 'REV007 appended superseded content is not canonical');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($literal)['violations'], 'REV007 appended literal content is not canonical');
        $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($reference)['violations'], 'REV007 appended reference content is not canonical');
        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($design)['violations'], 'REV007 untouched canonical control');
    }

    public function test_phase_three_architecture_document_outer_regions_are_fully_closed_world(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $canonical = $this->phaseThreeExclusiveSemanticContract($design);
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $authorityMarker = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->';
        $contractEnd = '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v1 -->';
        $expectedRegions = [
            'pre-current-reference-history-v1',
            'current-architecture-authority-v1',
            'post-current-reference-history-v1',
        ];

        $this->assertSame([], $canonical['violations'], implode(PHP_EOL, $canonical['violations']));
        $this->assertSame('CANONICAL_ARCHITECTURE_DOCUMENT', $canonical['architecture_document_inventory']['classification']);
        $this->assertSame(1038, $canonical['architecture_document_inventory']['unit_count']);
        $this->assertSame($expectedRegions, $canonical['architecture_document_inventory']['region_order']);
        $this->assertSame($expectedRegions, array_keys($canonical['architecture_document_inventory']['regions']));
        foreach ($expectedRegions as $position => $id) {
            $this->assertSame($position + 1, $canonical['architecture_document_inventory']['regions'][$id]['position']);
        }

        $prefixArbitrary = [
            'REV008-PA01 folded map' => 'A folded map rests near the western stair.',
            'REV008-PA02 opaque token' => 'ultramarine-4827',
            'REV008-PA03 neutral count' => 'Seven plain counters occupy this margin.',
            'REV008-PA04 bracket note' => '[detached ceramic note]',
            'REV008-PA05 quiet sentence' => 'Quiet windows face an empty courtyard.',
        ];
        $prefixNormative = [
            'REV008-PN01 saffron appendix' => 'The saffron appendix outranks every later section.',
            'REV008-PN02 hidden charter' => 'A hidden charter may settle all following decisions.',
            'REV008-PN03 ivory memorandum' => 'The ivory memorandum controls the subsequent design.',
            'REV008-PN04 prior ledger' => 'A prior ledger may replace the rules that follow.',
            'REV008-PN05 opening decree' => 'This opening decree governs every later statement.',
        ];
        $suffixArbitrary = [
            'REV008-SA01 copper square' => 'A copper square rests beyond the final margin.',
            'REV008-SA02 opaque token' => 'vermillion-9051',
            'REV008-SA03 neutral count' => 'Nine blank counters follow the final section.',
            'REV008-SA04 bracket note' => '[detached linen note]',
            'REV008-SA05 quiet sentence' => 'A paper lantern hangs beside the closed gate.',
        ];
        $suffixNormative = [
            'REV008-SN01 obsidian appendix' => 'The obsidian appendix outranks every earlier section.',
            'REV008-SN02 closing charter' => 'A closing charter may settle all preceding decisions.',
            'REV008-SN03 silver memorandum' => 'The silver memorandum controls the preceding design.',
            'REV008-SN04 later ledger' => 'A later ledger may replace the rules recorded above.',
            'REV008-SN05 final decree' => 'This final decree governs every earlier statement.',
        ];
        $boundaryMutations = [];
        foreach ([...$prefixArbitrary, ...$prefixNormative] as $case => $fragment) {
            $boundaryMutations[$case] = [
                'position' => 'before-current-authority',
                'fragment' => $fragment,
                'document' => $this->insertPhaseThreeCurrentArchitectureUnit(
                    $design,
                    $authorityMarker,
                    $fragment,
                ),
            ];
        }
        foreach ([...$suffixArbitrary, ...$suffixNormative] as $case => $fragment) {
            $boundaryMutations[$case] = [
                'position' => 'after-current-authority',
                'fragment' => $fragment,
                'document' => $this->insertPhaseThreeCurrentArchitectureUnit(
                    $design,
                    $contractEnd,
                    $fragment,
                    true,
                ),
            ];
        }
        $this->assertCount(20, $boundaryMutations);
        foreach ($boundaryMutations as $case => $mutation) {
            $this->assertSame([], $this->phaseThreeSemanticAssertions($mutation['fragment']), "{$case}: lexical delta must be zero.");
            $contract = $this->phaseThreeExclusiveSemanticContract($mutation['document']);

            $this->assertNotSame([], $contract['violations'], "{$case}: outer content must fail structurally.");
            $this->assertSame('DOCUMENT_STRUCTURAL_MISMATCH', $contract['architecture_document_inventory']['classification'], "{$case}: document classification");
            $this->assertSame('CANONICAL_CURRENT_ARCHITECTURE', $contract['current_architecture_inventory']['classification'], "{$case}: nested CURRENT inventory remains independently canonical");
            $this->assertSame($canonical['candidate_count'], $contract['candidate_count'], "{$case}: rejection must be lexical-independent");
        }

        $currentBlock = $this->phaseThreeCurrentArchitectureBlock($design);
        $renamedMarker = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v2 -->';
        $reordered = $this->replaceStructuralText($design, $authorityMarker, '__REV008_AUTHORITY_SWAP__');
        $reordered = $this->replaceStructuralText($reordered, $contractEnd, $authorityMarker);
        $reordered = $this->replaceStructuralText($reordered, '__REV008_AUTHORITY_SWAP__', $contractEnd);
        $structuralOuterMutations = [
            'REV008-ST01 heading before region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $authorityMarker, '## Detached Prefix Heading'),
            'REV008-ST02 heading after region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractEnd, '## Detached Suffix Heading', true),
            'REV008-ST03 table before region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $authorityMarker, "| quartz | linen |{$lineEnding}| --- | --- |{$lineEnding}| one | two |"),
            'REV008-ST04 table after region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractEnd, "| copper | glass |{$lineEnding}| --- | --- |{$lineEnding}| three | four |", true),
            'REV008-ST05 literal before region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $authorityMarker, "```text{$lineEnding}detached prefix literal{$lineEnding}```"),
            'REV008-ST06 literal after region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractEnd, "```text{$lineEnding}detached suffix literal{$lineEnding}```", true),
            'REV008-ST07 unknown top-level region' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $authorityMarker, '<!-- phase-iii-document-region id=unknown-v1 -->'),
            'REV008-ST08 duplicate canonical region' => $design.$lineEnding.$currentBlock,
            'REV008-ST09 missing canonical region' => $this->replaceStructuralText($design, $currentBlock, ''),
            'REV008-ST10 renamed canonical region' => $this->replaceStructuralText($design, $authorityMarker, $renamedMarker),
            'REV008-ST11 reordered canonical regions' => $reordered,
            'REV008-ST12 content at pre/current boundary' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $authorityMarker, 'boundary-token-prefix-617'),
            'REV008-ST13 content at current/post boundary' => $this->insertPhaseThreeCurrentArchitectureUnit($design, $contractEnd, 'boundary-token-suffix-274', true),
            'REV008-ST14 appended EOF prose' => $design.$lineEnding.'detached-eof-token-334',
            'REV008-ST15 prepended BOF prose' => 'detached-bof-token-771'.$lineEnding.$design,
        ];
        foreach ($structuralOuterMutations as $case => $mutated) {
            $contract = $this->phaseThreeExclusiveSemanticContract($mutated);

            $this->assertNotSame([], $contract['violations'], $case);
            $this->assertNotSame('CANONICAL_ARCHITECTURE_DOCUMENT', $contract['architecture_document_inventory']['classification'], "{$case}: document classification");
        }

        $designOnlyMutation = $this->replaceStructuralText(
            $design,
            '## Generation Header Data Dictionary',
            '## Generation Header Evidence Dictionary',
        );
        $designOnlyContract = $this->phaseThreeExclusiveSemanticContract($designOnlyMutation);
        $this->assertSame('DOCUMENT_STRUCTURAL_MISMATCH', $designOnlyContract['architecture_document_inventory']['classification']);
        $this->assertSame('CANONICAL_CURRENT_ARCHITECTURE', $designOnlyContract['current_architecture_inventory']['classification']);
        $this->assertNotSame([], $designOnlyContract['violations'], 'Changing only the candidate design must not update its expected oracle.');

        $lf = str_replace(["\r\n", "\r"], "\n", $design);
        $crlf = str_replace("\n", "\r\n", $lf);
        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($lf)['violations'], 'LF canonical control');
        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($crlf)['violations'], 'CRLF canonical control');

        $normalizationMutations = [
            'REV008-N01 trailing spaces' => $this->replaceStructuralText($design, '# Immutable Supplier Offer Snapshot Persistence Design', '# Immutable Supplier Offer Snapshot Persistence Design  '),
            'REV008-N02 tab' => $this->replaceStructuralText($design, '## Generation Header Data Dictionary', "## Generation\tHeader Data Dictionary"),
            'REV008-N03 blank line' => $this->replaceStructuralText($design, '## Generation Header Data Dictionary', $lineEnding.'## Generation Header Data Dictionary'),
            'REV008-N04 indentation' => $this->replaceStructuralText($design, 'Deployed inactive table: `supplier_offer_snapshot_generations`.', '  Deployed inactive table: `supplier_offer_snapshot_generations`.'),
            'REV008-N05 NBSP' => $this->replaceStructuralText($design, 'Generation Header', "Generation\u{00A0}Header"),
            'REV008-N06 zero width' => $this->replaceStructuralText($design, 'Generation Header', "Generation\u{200B} Header"),
            'REV008-N07 BOM' => "\xEF\xBB\xBF".$design,
            'REV008-N08 NFC' => $design.$lineEnding."caf\u{00E9}",
            'REV008-N09 NFD' => $design.$lineEnding."cafe\u{0301}",
        ];
        foreach ($normalizationMutations as $case => $mutated) {
            $this->assertNotSame([], $this->phaseThreeExclusiveSemanticContract($mutated)['violations'], $case);
        }

        $source = $this->readDocument('tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php');
        $this->assertStringNotContainsString('UNCLASSIFIED'.'_CURRENT_STRUCTURE', $source);
        $knownTypeProbes = [
            ":::quartz{$lineEnding}opaque{$lineEnding}:::" => ['definition', 'paragraph', 'definition'],
            '- [ ] detached task' => ['paragraph'],
            '[^detached]: footnote' => ['paragraph'],
            '<section>detached</section>' => ['paragraph'],
            '---' => ['paragraph'],
            '~~detached~~' => ['paragraph'],
        ];
        foreach ($knownTypeProbes as $probe => $expectedTypes) {
            $blocks = $this->phaseThreeArchitectureStructuralBlocks($probe);
            $this->assertSame($expectedTypes, array_column($blocks, 'type'), "Known parser classification for {$probe}");
            $mutated = $this->insertPhaseThreeCurrentArchitectureUnit($design, $authorityMarker, $probe);
            $this->assertSame(
                'DOCUMENT_STRUCTURAL_MISMATCH',
                $this->phaseThreeExclusiveSemanticContract($mutated)['architecture_document_inventory']['classification'],
                "Exact inventory rejects unauthorized known-type probe {$probe}",
            );
        }

        $this->assertStringContainsString(
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-readiness-remediation-v1 -->',
            $design,
        );
        $this->assertStringContainsString('REFERENCE/EXPLANATION only and remains exact canonical', $design);
        $this->assertStringContainsString('```text', $design);
        $this->assertSame([], $this->phaseThreeExclusiveSemanticContract($design)['violations'], 'Exact canonical historical/literal/reference/explanation content passes in place.');
    }

    public function test_phase_three_protected_redirect_policy_is_single_and_fail_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $canonical = $this->phaseThreeArchitectureSemanticContract($design);

        $this->assertSame([], $canonical['violations'], 'R0 canonical redirect policy');
        $this->assertSame(1, substr_count($canonical['full_block'], 'The selected protected redirect policy is exactly'));
        $this->assertStringNotContainsString('Redirects are independently SSRF-revalidated', $canonical['full_block']);

        $mutations = [
            'R1 SSRF validation alone authorizes redirects' => [
                'never provenance',
                'sufficient provenance',
            ],
            'R2 final locator may differ without immutable attestation' => [
                'redirect target can become source B implicitly',
                'redirect target may become source B without immutable attestation',
            ],
            'R3 initial fingerprint may attest redirected payload' => [
                'the downloader cannot consume a redirected locator',
                'the downloader may consume a redirected locator',
            ],
            'R4 redirect target becomes source implicitly' => [
                'must create or resolve profile/context/execution',
                'may reuse execution EA without creating or resolving profile/context/execution',
            ],
            'R5 retry follows current redirect target' => [
                'redirect following still disabled',
                'the current redirect target followed as source authority',
            ],
            'R6 cross-scope redirect is accepted' => [
                'same-scope or cross-scope redirect is rejected identically',
                'cross-host or cross-scope redirect is accepted after SSRF validation',
            ],
        ];

        foreach ($mutations as $case => [$search, $replacement]) {
            $this->assertSame(1, substr_count($design, $search), "{$case}: mutation target must be unique.");
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract(str_replace($search, $replacement, $design))['violations'],
                $case,
            );
        }
    }

    public function test_phase_three_architecture_authority_rejects_outside_current_declarations(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'OA9 canonical');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $currentMarker = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-outside-review-v1 -->';
        $outsideHeader = '| Finding | Architecture status | Exact boundary |'.$lineEnding.
            '| :--- | :--- | --- |'.$lineEnding;
        $outsideStatus = static fn (string $id, string $status): string => "| `{$id}` | `{$status}` | Outside current-authority mutation. |";

        $rejected = [
            'OA1 second current PH3-RDY-001 CLOSED' => $design.$lineEnding.$currentMarker.$lineEnding.
                $outsideHeader.$outsideStatus('PH3-RDY-001', 'CLOSED'),
            'OA2 second current PH3-RDY-001 BLOCKED' => $design.$lineEnding.$currentMarker.$lineEnding.
                $outsideHeader.$outsideStatus('PH3-RDY-001', 'BLOCKED'),
            'OA3 second current PH3-RDY-002 BLOCKED' => $design.$lineEnding.$currentMarker.$lineEnding.
                $outsideHeader.$outsideStatus('PH3-RDY-002', 'BLOCKED'),
            'OA4 second current PH3-RDY-003 CLOSED' => $design.$lineEnding.$currentMarker.$lineEnding.
                $outsideHeader.$outsideStatus('PH3-RDY-003', 'CLOSED'),
            'OA5 identical duplicate current status block' => $design.$lineEnding.$currentMarker.$lineEnding.
                $outsideHeader.
                $outsideStatus('PH3-RDY-001', 'CLOSED IN DESIGN').$lineEnding.
                $outsideStatus('PH3-RDY-002', 'CLOSED IN DESIGN').$lineEnding.
                $outsideStatus('PH3-RDY-003', 'BLOCKED').$lineEnding.
                $outsideStatus('PH3-RDY-004', 'CLOSED'),
            'OA6 malformed second current-authority marker' => $design.$lineEnding.
                '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-outside-review-v1 ->',
            'OA7 Markdown-prefixed malformed current-authority marker' => $design.$lineEnding.
                '- <!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-outside-review-v1 -->',
        ];
        foreach ($rejected as $mutation => $mutatedDesign) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($mutatedDesign)['violations'],
                $mutation,
            );
        }

        $historical = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-prior-review-v1 -->'.$lineEnding.
            'Historical/superseded review evidence; this is not current architecture authority.'.$lineEnding.
            $outsideStatus('PH3-RDY-001', 'BLOCKED');
        $this->assertNotSame(
            [],
            $this->phaseThreeArchitectureSemanticContract($historical)['violations'],
            'OA8 appended historical authority is outside the canonical document inventory',
        );
    }

    public function test_phase_three_architecture_authority_discovery_is_global_and_validity_independent(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $canonical = $this->phaseThreeArchitectureAuthorityContract($design);
        $this->assertSame([], $canonical['violations'], 'CP1 canonical');
        $this->assertSame(2, $canonical['marker_candidate_count']);
        $this->assertSame(2, $canonical['valid_marker_count']);
        $this->assertSame(1, $canonical['current_count']);
        $this->assertSame(1, $canonical['historical_count']);
        $this->assertSame(0, $canonical['superseded_count']);
        $this->assertSame(0, $canonical['malformed_marker_count']);
        $this->assertSame(20, $canonical['status_lexical_occurrence_count']);
        $this->assertSame(9, $canonical['status_candidate_count']);
        $this->assertSame(4, $canonical['current_status_declaration_count']);
        $this->assertSame(5, $canonical['historical_status_declaration_count']);
        $this->assertSame(0, $canonical['unclassified_status_declaration_count']);
        $this->assertSame(0, $canonical['malformed_status_candidate_count']);
        $this->assertSame(0, $canonical['heading_candidate_count']);
        $this->assertSame(0, $canonical['unclassified_heading_count']);

        $statusRow = static fn (string $id, string $status): string => "| `{$id}` | `{$status}` | Global authority mutation. |";
        $currentHeading = '### Current Phase III architecture status';
        $currentStatusTable = $currentHeading.$lineEnding.
            '| Finding | Status |'.$lineEnding.
            '| --- | --- |'.$lineEnding.
            $statusRow('PH3-RDY-001', 'BLOCKED').$lineEnding.
            $statusRow('PH3-RDY-002', 'CLOSED IN DESIGN').$lineEnding.
            $statusRow('PH3-RDY-003', 'BLOCKED').$lineEnding.
            $statusRow('PH3-RDY-004', 'CLOSED');

        $mutations = [
            'UA1 unmarked current assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 = BLOCKED (current architecture status)',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA2 unmarked current heading and table' => [
                'document' => $design.$lineEnding.$currentStatusTable,
                'discoveries' => [
                    'status_candidate_count' => 4,
                    'unclassified_status_declaration_count' => 4,
                    'heading_candidate_count' => 1,
                    'unclassified_heading_count' => 1,
                ],
            ],
            'UA3 mixed-case authority marker' => [
                'document' => $design.$lineEnding.'<!-- Phase-III-Architecture-Authority classification=CURRENT id=phase-iii-outside-review-v1 -->',
                'discoveries' => ['marker_candidate_count' => 1, 'malformed_marker_count' => 1],
            ],
            'UA4 Markdown-list-prefixed authority marker' => [
                'document' => $design.$lineEnding.'- <!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-outside-review-v1 -->',
                'discoveries' => ['marker_candidate_count' => 1, 'malformed_marker_count' => 1],
            ],
            'UA5 blockquote-prefixed authority marker' => [
                'document' => $design.$lineEnding.'> <!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-outside-review-v1 -->',
                'discoveries' => ['marker_candidate_count' => 1, 'malformed_marker_count' => 1],
            ],
            'UA6 authority marker with trailing tokens' => [
                'document' => $design.$lineEnding.'<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-outside-review-v1 trailing=true -->',
                'discoveries' => ['marker_candidate_count' => 1, 'malformed_marker_count' => 1],
            ],
            'UA7 authority marker missing classification' => [
                'document' => $design.$lineEnding.'<!-- phase-iii-architecture-authority id=phase-iii-outside-review-v1 -->',
                'discoveries' => ['marker_candidate_count' => 1, 'malformed_marker_count' => 1],
            ],
            'UA8 authority marker with unknown classification' => [
                'document' => $design.$lineEnding.'<!-- phase-iii-architecture-authority classification=REFERENCE id=phase-iii-outside-review-v1 -->',
                'discoveries' => ['marker_candidate_count' => 1, 'malformed_marker_count' => 1],
            ],
            'UA9 duplicate identical valid current marker' => [
                'document' => $design.$lineEnding.'<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->',
                'discoveries' => ['marker_candidate_count' => 1, 'valid_marker_count' => 1, 'current_count' => 1],
            ],
            'UA10 PH3-RDY-001 plain assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA11 PH3-RDY-002 plain assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-002 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA12 PH3-RDY-003 plain assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-003 = CLOSED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA13 PH3-RDY-004 plain assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-004 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA14 unknown status assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 = UNKNOWN',
                'discoveries' => ['status_candidate_count' => 1, 'malformed_status_candidate_count' => 1],
            ],
            'UA15 invalid CLOSEDX status assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 = CLOSEDX',
                'discoveries' => ['status_candidate_count' => 1, 'malformed_status_candidate_count' => 1],
            ],
            'UA16 lowercase pending status assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 = pending',
                'discoveries' => ['status_candidate_count' => 1, 'malformed_status_candidate_count' => 1],
            ],
            'UA17 empty status assignment' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 =',
                'discoveries' => ['status_candidate_count' => 1, 'malformed_status_candidate_count' => 1],
            ],
            'UA18 dash-prefixed status assignment' => [
                'document' => $design.$lineEnding.'- PH3-RDY-001 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA19 star-prefixed status assignment' => [
                'document' => $design.$lineEnding.'* PH3-RDY-001 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA20 blockquote-prefixed status assignment' => [
                'document' => $design.$lineEnding.'> PH3-RDY-001 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA21 ordered-list-prefixed status assignment' => [
                'document' => $design.$lineEnding.'1. PH3-RDY-001 = BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA22 table-style declaration' => [
                'document' => $design.$lineEnding.'| PH3-RDY-001 | BLOCKED |',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA23 pipe-free table-style declaration' => [
                'document' => $design.$lineEnding.'PH3-RDY-001 | BLOCKED',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA24 extended table-style declaration' => [
                'document' => $design.$lineEnding.'| PH3-RDY-001 | CLOSED IN DESIGN | current |',
                'discoveries' => ['status_candidate_count' => 1, 'unclassified_status_declaration_count' => 1],
            ],
            'UA25 empty unmarked current authority section' => [
                'document' => $design.$lineEnding.$currentHeading,
                'discoveries' => ['heading_candidate_count' => 1, 'unclassified_heading_count' => 1],
            ],
            'UA26 mixed-case unmarked current authority section' => [
                'document' => $design.$lineEnding.'### cUrReNt PhAsE III ArChItEcTuRe StAtUs',
                'discoveries' => ['heading_candidate_count' => 1, 'unclassified_heading_count' => 1],
            ],
        ];

        foreach ($mutations as $mutation => $case) {
            $authority = $this->phaseThreeArchitectureAuthorityContract($case['document']);
            foreach ($case['discoveries'] as $field => $increment) {
                $this->assertSame($canonical[$field] + $increment, $authority[$field], "{$mutation}: {$field}");
            }
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($case['document'])['violations'],
                $mutation,
            );
        }

        $mixedCaseStatusId = $design.$lineEnding.'| `ph3-rdy-001` | `BLOCKED` | Case mutation. |';
        $mixedCaseStatusAuthority = $this->phaseThreeArchitectureAuthorityContract($mixedCaseStatusId);
        $this->assertSame($canonical['status_candidate_count'] + 1, $mixedCaseStatusAuthority['status_candidate_count']);
        $this->assertSame($canonical['malformed_status_candidate_count'] + 1, $mixedCaseStatusAuthority['malformed_status_candidate_count']);
        $this->assertNotSame(
            [],
            $this->phaseThreeArchitectureSemanticContract($mixedCaseStatusId)['violations'],
            'Case-insensitive status-ID discovery must feed case-sensitive exact validation.',
        );
        $combinedInvalidLine = $design.$lineEnding.
            '<!-- Phase-III-Architecture-Authority classification=CURRENT --> PH3-RDY-001 = BLOCKED';
        $combinedInvalidAuthority = $this->phaseThreeArchitectureAuthorityContract($combinedInvalidLine);
        $this->assertSame($canonical['marker_candidate_count'] + 1, $combinedInvalidAuthority['marker_candidate_count']);
        $this->assertSame($canonical['status_candidate_count'] + 1, $combinedInvalidAuthority['status_candidate_count']);
        $this->assertNotSame(
            [],
            $this->phaseThreeArchitectureSemanticContract($combinedInvalidLine)['violations'],
            'Marker and status discovery channels must remain independent on the same physical line.',
        );

        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'HP1 existing historical block');
        $historical = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=SUPERSEDED id=phase-iii-prior-status-v1 -->'.$lineEnding.
            '### Historical Phase III architecture readiness status'.$lineEnding.
            $statusRow('PH3-RDY-001', 'BLOCKED');
        $historicalAuthority = $this->phaseThreeArchitectureAuthorityContract($historical);
        $this->assertNotSame([], $this->phaseThreeArchitectureSemanticContract($historical)['violations'], 'HP2 appended historical status is outside the canonical document inventory');
        $this->assertSame(1, $historicalAuthority['superseded_count']);
        $this->assertSame(6, $historicalAuthority['historical_status_declaration_count']);
        $historicalMention = $design.$lineEnding.'PH3-RDY-001 was evaluated during readiness review.';
        $historicalMentionAuthority = $this->phaseThreeArchitectureAuthorityContract($historicalMention);
        $this->assertNotSame([], $this->phaseThreeArchitectureSemanticContract($historicalMention)['violations'], 'HP3 appended identifier-only prose is outside the canonical document inventory');
        $this->assertSame($canonical['status_candidate_count'], $historicalMentionAuthority['status_candidate_count']);
        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'CP1 canonical semantic contract');
    }

    public function test_phase_three_architecture_authority_direct_root_cause_reproductions_are_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $statusRow = static fn (string $id, string $status): string => "| `{$id}` | `{$status}` | Direct reproduction. |";
        $rc1 = $design.$lineEnding.'PH3-RDY-001 = BLOCKED (current architecture status)';
        $rc2 = $design.$lineEnding.
            '### Current Phase III architecture status'.$lineEnding.
            '| Finding | Status |'.$lineEnding.
            '| --- | --- |'.$lineEnding.
            $statusRow('PH3-RDY-001', 'BLOCKED').$lineEnding.
            $statusRow('PH3-RDY-002', 'CLOSED IN DESIGN').$lineEnding.
            $statusRow('PH3-RDY-003', 'BLOCKED').$lineEnding.
            $statusRow('PH3-RDY-004', 'CLOSED');
        $rc3 = $design.$lineEnding.
            '<!-- Phase-III-Architecture-Authority classification=CURRENT id=phase-iii-outside-review-v1 -->';
        $rc5 = $design.$lineEnding.
            '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-prior-review-v2 -->'.$lineEnding.
            $statusRow('PH3-RDY-001', 'BLOCKED');

        foreach (['RC1' => $rc1, 'RC2' => $rc2, 'RC3' => $rc3] as $reproduction => $mutatedDesign) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($mutatedDesign)['violations'],
                $reproduction,
            );
        }
        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'RC4');
        $this->assertNotSame([], $this->phaseThreeArchitectureSemanticContract($rc5)['violations'], 'RC5 appended historical content is outside the canonical document inventory');
    }

    public function test_phase_three_architecture_authority_rejects_structural_evasions_without_false_positives(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";

        $rejected = [
            'N01 split colon assignment' => "PH3-RDY-001\n:\nBLOCKED",
            'N02 split equals assignment' => "PH3-RDY-001\n=\nBLOCKED",
            'N03 one-line HTML status row' => '<table><tr><td>PH3-RDY-001</td><td>BLOCKED</td></tr></table>',
            'N04 multiline HTML status row' => "<table>\n<tr>\n<td>PH3-RDY-001</td>\n<td>CLOSED</td>\n</tr>\n</table>",
            'N05 spaced authority near-match' => '<!-- Phase III Architecture Authority classification=CURRENT id=shadow -->',
            'N06 Arabic phase-number authority near-match' => '<!-- phase-3-architecture-authority classification=CURRENT id=shadow -->',
            'N07 architectural authority near-match' => '<!-- phase-iii-architectural-authority classification=CURRENT id=shadow -->',
            'N08 split status assignment' => "PH3-RDY-001\nstatus =\nBLOCKED",
            'N09 active authority heading' => '### Active Phase III architecture status',
            'N10 governing authority heading' => '### Governing current Phase III architecture',
            'N11 authoritative authority heading' => '### Authoritative Phase III architecture status',
            'N12 direct current prose' => 'Current PH3-RDY-001 status is BLOCKED.',
            'N13 remains-current prose' => 'PH3-RDY-001 remains BLOCKED in the current architecture.',
            'N14 JSON status object' => '{"PH3-RDY-001":"BLOCKED"}',
            'N15 YAML status property' => 'PH3-RDY-001: BLOCKED',
            'N16 split closed-in-design assignment' => "PH3-RDY-001\n:\nCLOSED IN DESIGN",
            'N17 second split status' => "PH3-RDY-002\n=\nBLOCKED",
            'N18 second multiline HTML status' => "<tr>\n<td>PH3-RDY-003</td>\n<td>CLOSED</td>\n</tr>",
            'N19 status-authority marker near-match' => '<!-- phase-iii-architecture-status-authority classification=CURRENT id=shadow -->',
            'N20 mixed-separator authority near-match' => '<!-- PHASE_III_ARCHITECTURE_AUTHORITY classification=CURRENT id=shadow -->',
            'N21 records-as-current prose' => 'The current architecture records PH3-RDY-003 as CLOSED.',
            'N22 architecture-status prose' => 'Architecture status: PH3-RDY-004 is BLOCKED.',
            'N23 current-phase prose' => 'For current Phase III, PH3-RDY-002 is BLOCKED.',
        ];
        foreach ($rejected as $case => $fragment) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($design.$lineEnding.$fragment)['violations'],
                $case,
            );
        }

        $statusRow = '| `PH3-RDY-001` | `BLOCKED` | Historical evidence only. |';
        $accepted = [
            'P1 classified historical status' => $design.$lineEnding.
                '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-history-positive-v1 -->'.$lineEnding.
                $statusRow,
            'P2 classified superseded status' => $design.$lineEnding.
                '<!-- phase-iii-architecture-authority classification=SUPERSEDED id=phase-iii-superseded-positive-v1 -->'.$lineEnding.
                $statusRow,
            'P3 identifier-only prose' => $design.$lineEnding.'PH3-RDY-001 was reviewed without declaring a status.',
            'P4 fenced mutation example' => $design.$lineEnding."```text\nPH3-RDY-001 = BLOCKED\n```",
            'P5 unchanged canonical document' => $design,
            'P6 non-status architecture cross-reference' => $design.$lineEnding.
                'See the canonical Phase III architecture contract for the current map.',
        ];
        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($accepted['P5 unchanged canonical document'])['violations'], 'P5 unchanged canonical document');
        unset($accepted['P5 unchanged canonical document']);
        foreach ($accepted as $case => $document) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($document)['violations'],
                "{$case}: appended content is outside the canonical document inventory",
            );
        }
    }

    public function test_phase_three_authority_normalization_closes_final_structural_escapes_and_false_positives(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";

        $rejected = [
            'S1 heading status declaration' => '### Governing PH3-RDY-001 = BLOCKED',
            'S2 status-first heading' => "## BLOCKED \u{2014} PH3-RDY-002",
            'S3 HTML entity hyphens' => 'Current PH3&#45;RDY&#45;003 = CLOSED.',
            'S4 Unicode hyphens' => "Current PH3\u{2011}RDY\u{2011}004 = BLOCKED.",
            'S5 comment inside identifier' => 'Current PH3-<!-- split -->RDY-001 = BLOCKED.',
            'S6 split definition list' => "PH3-RDY-001\n: BLOCKED",
            'S7 status before identifier split blocks' => "BLOCKED\n\nPH3-RDY-002",
            'S8 split details block' => "<details>\n<summary>Current architecture</summary>\nPH3-RDY-003\nBLOCKED\n</details>",
            'S9 split Markdown link' => "[PH3-RDY-004](#phase-status)\n\nBLOCKED",
            'S10 inline HTML wrappers' => '<span>PH3-RDY-001</span> = <strong>BLOCKED</strong>',
        ];
        foreach ($rejected as $case => $fragment) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($design.$lineEnding.$fragment)['violations'],
                $case,
            );
        }

        $accepted = [
            'F1 inline-code example in explicit example region' => $design.$lineEnding.
                '<!-- phase-iii-architecture-example:start -->'.$lineEnding.
                'Example only: `PH3-RDY-001 = BLOCKED`.'.$lineEnding.
                '<!-- phase-iii-architecture-example:end -->',
            'F2 historical status prose in historical region' => $design.$lineEnding.
                '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-history-prose-v1 -->'.$lineEnding.
                'At the earlier readiness checkpoint PH3-RDY-001 was BLOCKED.',
            'F3 blockquote mutation in explicit example region' => $design.$lineEnding.
                '<!-- phase-iii-architecture-example:start -->'.$lineEnding.
                '> Mutation fixture: PH3-RDY-002 = BLOCKED.'.$lineEnding.
                '<!-- phase-iii-architecture-example:end -->',
            'F4 identifier-only reference' => $design.$lineEnding.
                'PH3-RDY-003 is discussed by the canonical architecture owner.',
            'F5 canonical current authority' => $design,
        ];
        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($accepted['F5 canonical current authority'])['violations'], 'F5 canonical current authority');
        unset($accepted['F5 canonical current authority']);
        foreach ($accepted as $case => $document) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($document)['violations'],
                "{$case}: appended content is outside the canonical document inventory",
            );
        }
    }

    public function test_phase_three_final_authority_root_causes_and_novel_fragmentation_are_closed(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $rejected = [
            'M08 emphasis-fragmented identifier' => '***PH3***-**RDY**-001 = BLOCKED',
            'M09 comment-fragmented identifier' => 'Current PH3&#45;<!-- phase architecture -->RDY&#45;002 = BLOCKED',
            'M12 long current heading' => '### Current PH3-RDY-001 remains after one two three four five six seven eight nine ten eleven words BLOCKED',
            'NF01 mixed emphasis' => '**PH3**-*RDY*-001 = BLOCKED',
            'NF02 nested emphasis' => '___PH3___-***RDY***-002 = BLOCKED',
            'NF03 comments at both boundaries' => 'Current PH3<!-- first -->-RDY<!-- second -->-003 = CLOSED',
            'NF04 entity and emphasis' => 'Current **PH3**&#45;__RDY__&#45;004 = BLOCKED',
            'NF05 Unicode hyphen and emphasis' => "Current *PH3*\u{2011}**RDY**\u{2011}001 = BLOCKED",
            'NF06 twenty-word heading' => '#### Current PH3-RDY-002 has one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty status BLOCKED',
            'NF07 long definition entry' => 'PH3-RDY-003: the current architecture after one two three four five six seven eight nine ten remains CLOSED',
            'NF08 long table cell' => '| PH3-RDY-004 | one two three four five six seven eight nine ten eleven | BLOCKED |',
            'NF09 status before fragmented identifier' => 'BLOCKED is the current value of ***PH3***-**RDY**-001',
            'NF10 fragmented link label' => '[***PH3***-**RDY**-002](#status) = BLOCKED',
            'NF11 fragmented HTML cells' => '<table><tr><td>***PH3***-**RDY**-003</td><td>CLOSED</td></tr></table>',
            'NF12 NBSP and emphasis' => "Current **PH3**\u{00A0}-\u{00A0}**RDY**-004 = BLOCKED",
            'NF13 comments and Unicode' => "Current PH3\u{2011}<!-- hidden -->RDY\u{2011}001 = BLOCKED",
            'NF14 blockquote current fragmented identifier' => '> Current ***PH3***-**RDY**-002 = BLOCKED',
            'NF15 inline wrappers and comment' => '<span>PH3</span>-<!-- join --><strong>RDY</strong>-003 = CLOSED',
        ];
        foreach ($rejected as $case => $fragment) {
            $violations = $this->phaseThreeArchitectureSemanticContract(
                $design.$lineEnding.$fragment,
            )['violations'];
            $this->assertNotSame([], $violations, $case);
        }

        $accepted = [
            'FC1 fragmented literal example' => $design.$lineEnding.
                '<!-- phase-iii-architecture-example:start -->'.$lineEnding.
                'Example only: ***PH3***-**RDY**-001 = BLOCKED.'.$lineEnding.
                '<!-- phase-iii-architecture-example:end -->',
            'FC2 fragmented historical authority' => $design.$lineEnding.
                '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-fragment-history-v1 -->'.$lineEnding.
                'At the earlier checkpoint ***PH3***-**RDY**-002 was BLOCKED.',
            'FC3 emphasized identifier-only reference' => $design.$lineEnding.
                'The **PH3**-**RDY**-003 finding is referenced without a status declaration.',
            'FC4 long identifier-only prose' => $design.$lineEnding.
                'PH3-RDY-004 is discussed across one two three four five six seven eight nine ten words without declaring a result.',
            'FC5 comment-adjacent identifier-only reference' => $design.$lineEnding.
                'PH3-<!-- presentation -->RDY-001 is referenced without a result declaration.',
            'FC6 fragmented historical blockquote' => $design.$lineEnding.
                '<!-- phase-iii-architecture-authority classification=HISTORICAL id=phase-iii-fragment-blockquote-v1 -->'.$lineEnding.
                '> At the earlier checkpoint ***PH3***-**RDY**-004 was BLOCKED.',
        ];
        foreach ($accepted as $case => $document) {
            $this->assertNotSame(
                [],
                $this->phaseThreeArchitectureSemanticContract($document)['violations'],
                "{$case}: appended content is outside the canonical document inventory",
            );
        }
        $this->assertSame([], $this->phaseThreeArchitectureSemanticContract($design)['violations'], 'FC7 unchanged canonical document');

        $documents = [
            'design' => $design,
            'plan' => $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md'),
            'phases' => $this->readDocument('docs/PHASES.md'),
            'roadmap' => $this->readDocument('docs/ROADMAP.md'),
            'onboarding' => $this->readDocument('docs/SUPPLIER_ONBOARDING_FRAMEWORK.md'),
            'apcom' => $this->readDocument('docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md'),
        ];
        foreach (array_diff(array_keys($documents), ['design']) as $documentKey) {
            foreach ([
                'C11 emphasis-fragmented contradiction' => '***PH3***-**RDY**-001 = BLOCKED',
                'C12 comment-fragmented contradiction' => 'Current PH3&#45;<!-- phase architecture -->RDY&#45;003 = CLOSED',
            ] as $case => $fragment) {
                $mutated = $documents;
                $mutated[$documentKey] .= $lineEnding.$fragment;
                $this->assertNotSame(
                    [],
                    $this->phaseThreeRepositoryStatusAuthorityContract($mutated)['violations'],
                    "{$documentKey}: {$case}",
                );
            }
        }
    }

    public function test_phase_three_status_has_one_repository_authority_and_exact_references(): void
    {
        $documents = [
            'design' => $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md'),
            'plan' => $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md'),
            'phases' => $this->readDocument('docs/PHASES.md'),
            'roadmap' => $this->readDocument('docs/ROADMAP.md'),
            'onboarding' => $this->readDocument('docs/SUPPLIER_ONBOARDING_FRAMEWORK.md'),
            'apcom' => $this->readDocument('docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md'),
        ];
        $canonical = $this->phaseThreeRepositoryStatusAuthorityContract($documents);
        $this->assertSame([], $canonical['violations'], implode(PHP_EOL, $canonical['violations']));
        $this->assertSame([
            'PH3-RDY-001' => 'CLOSED IN DESIGN',
            'PH3-RDY-002' => 'CLOSED IN DESIGN',
            'PH3-RDY-003' => 'BLOCKED',
            'PH3-RDY-004' => 'CLOSED',
        ], $canonical['statuses']);
        $this->assertSame(5, $canonical['reference_count']);

        foreach (array_keys($documents) as $documentKey) {
            $contradiction = $documents;
            $contradiction[$documentKey] .= "\nPH3-RDY-003 = CLOSED";
            $this->assertNotSame(
                [],
                $this->phaseThreeRepositoryStatusAuthorityContract($contradiction)['violations'],
                "{$documentKey} contradictory status declaration",
            );

            if ($documentKey === 'design') {
                $duplicate = $documents;
                $duplicate['design'] .= "\n<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->";
            } else {
                $duplicate = $documents;
                $duplicate[$documentKey] .= "\n<!-- phase-iii-architecture-status-reference authority=phase-iii-architecture-contract-v1 -->";
            }
            $this->assertNotSame(
                [],
                $this->phaseThreeRepositoryStatusAuthorityContract($duplicate)['violations'],
                "{$documentKey} duplicate authority/reference declaration",
            );
        }
    }

    public function test_phase_three_non_owner_documents_reject_normalized_and_split_status_authority(): void
    {
        $documents = [
            'design' => $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md'),
            'plan' => $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md'),
            'phases' => $this->readDocument('docs/PHASES.md'),
            'roadmap' => $this->readDocument('docs/ROADMAP.md'),
            'onboarding' => $this->readDocument('docs/SUPPLIER_ONBOARDING_FRAMEWORK.md'),
            'apcom' => $this->readDocument('docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md'),
        ];
        $this->assertSame([], $this->phaseThreeRepositoryStatusAuthorityContract($documents)['violations']);

        $mutations = [
            'entity identifier' => 'Current PH3&#45;RDY&#45;003 = CLOSED.',
            'Unicode-hyphen identifier' => "Current PH3\u{2011}RDY\u{2011}003 = CLOSED.",
            'X1 identifier before split status' => "PH3-RDY-001\n\nBLOCKED",
            'X2 status before split identifier' => "BLOCKED\n\nPH3-RDY-001",
            'X3 HTML split cells' => "<table>\n<tr>\n<td>PH3-RDY-001</td>\n<td>BLOCKED</td>\n</tr>\n</table>",
            'X4 definition list' => "PH3-RDY-001\n: BLOCKED",
            'X5 heading status' => '### Current PH3-RDY-001 status: BLOCKED',
            'X6 inline wrapped identifier' => '<span>PH3&#45;RDY&#45;001</span> = <strong>BLOCKED</strong>',
            'X7 duplicate identical current map' => "| PH3-RDY-001 | CLOSED IN DESIGN |\n| PH3-RDY-002 | CLOSED IN DESIGN |\n| PH3-RDY-003 | BLOCKED |\n| PH3-RDY-004 | CLOSED |",
            'X8 closed contradiction' => 'PH3-RDY-003 = CLOSED',
        ];

        foreach (['plan', 'phases', 'roadmap', 'onboarding', 'apcom'] as $documentKey) {
            foreach ($mutations as $case => $fragment) {
                $mutated = $documents;
                $mutated[$documentKey] .= "\n{$fragment}";
                $this->assertNotSame(
                    [],
                    $this->phaseThreeRepositoryStatusAuthorityContract($mutated)['violations'],
                    "{$documentKey}: {$case}",
                );
            }
        }
    }

    public function test_phase_three_readiness_structural_collections_reject_duplicate_shadowing(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $readiness = $this->markdownSection(
            $design,
            '### Historical Phase III readiness findings (superseded)',
            '### Canonical source scope',
        );
        $runtimeInventory = $this->markdownSection(
            $plan,
            '### Current deployed artifact inventory',
            '### Remaining runtime implementation gaps',
        );

        $statusRows = [
            'PH3-RDY-001' => $this->structuralMarkdownRow($readiness, 'PH3-RDY-001'),
            'PH3-RDY-002' => $this->structuralMarkdownRow($readiness, 'PH3-RDY-002'),
            'PH3-RDY-003' => $this->structuralMarkdownRow($readiness, 'PH3-RDY-003'),
            'PH3-RDY-004' => $this->structuralMarkdownRow($readiness, 'PH3-RDY-004'),
        ];
        $closedTwo = '| `PH3-RDY-002` | `CLOSED` | Contradictory mutation row. |';
        $readinessMutations = [
            'R1 conflicting duplicate before canonical' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-002'],
                $closedTwo,
                before: true,
            ),
            'R2 conflicting duplicate after canonical' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-002'],
                $closedTwo,
            ),
            'R3 identical PH3-RDY-002 duplicate before canonical' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-002'],
                $statusRows['PH3-RDY-002'],
                before: true,
            ),
            'R4 identical PH3-RDY-002 duplicate after canonical' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-002'],
                $statusRows['PH3-RDY-002'],
            ),
            'R5 identical PH3-RDY-001 duplicate' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-001'],
                $statusRows['PH3-RDY-001'],
            ),
            'R6 identical PH3-RDY-004 duplicate' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-004'],
                $statusRows['PH3-RDY-004'],
            ),
            'R7 unknown readiness ID' => $this->insertStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-004'],
                '| `PH3-RDY-005` | `BLOCKED` | Unknown mutation row. |',
            ),
            'R8 missing readiness ID' => $this->removeStructuralRow(
                $readiness,
                $statusRows['PH3-RDY-003'],
            ),
        ];

        foreach ($readinessMutations as $mutation => $mutatedReadiness) {
            $this->assertNotSame(
                [],
                $this->readinessStatusContract($mutatedReadiness)['violations'],
                "Mutation must fail closed: {$mutation}",
            );
        }
        $this->assertContains(
            'Duplicate readiness status key: PH3-RDY-002 (2 occurrences).',
            $this->readinessStatusContract(
                $readinessMutations['R1 conflicting duplicate before canonical'],
            )['violations'],
        );

        $canonicalStatusBlock = implode("\n", array_values($statusRows));
        $reorderedStatusBlock = implode("\n", [
            $statusRows['PH3-RDY-004'],
            $statusRows['PH3-RDY-002'],
            $statusRows['PH3-RDY-001'],
            $statusRows['PH3-RDY-003'],
        ]);
        $this->assertSame(1, substr_count($readiness, $canonicalStatusBlock));
        $reorderedReadiness = str_replace($canonicalStatusBlock, $reorderedStatusBlock, $readiness);
        $this->assertSame(
            [],
            $this->readinessStatusContract($reorderedReadiness)['violations'],
            'R9 readiness row order is non-normative; exact IDs, uniqueness, and values remain normative.',
        );

        $outboxRow = $this->structuralMarkdownRow($runtimeInventory, 'supplier_import_dispatch_outbox');
        $claimModelRow = $this->structuralMarkdownRow($runtimeInventory, 'SupplierImportExecutionClaim');
        $sourceIdentityRow = $this->structuralMarkdownRow($runtimeInventory, 'SnapshotSourceIdentity');
        $conflictingOutbox = '| `supplier_import_dispatch_outbox` | `NOT IMPLEMENTED / FUTURE` | `INACTIVE` | Contradictory mutation row. |';
        $inventoryMutations = [
            'I1 conflicting outbox duplicate before canonical' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                $conflictingOutbox,
                before: true,
            ),
            'I2 conflicting outbox duplicate after canonical' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                $conflictingOutbox,
            ),
            'I3 identical outbox duplicate before canonical' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                $outboxRow,
                before: true,
            ),
            'I4 identical outbox duplicate after canonical' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                $outboxRow,
            ),
            'I5 conflicting Phase II model duplicate' => $this->insertStructuralRow(
                $runtimeInventory,
                $claimModelRow,
                '| `SupplierImportExecutionClaim` | `MISSING` | `INACTIVE` | Contradictory mutation row. |',
            ),
            'I6 identical Phase II model duplicate' => $this->insertStructuralRow(
                $runtimeInventory,
                $claimModelRow,
                $claimModelRow,
            ),
            'I7 unknown artifact' => $this->insertStructuralRow(
                $runtimeInventory,
                $sourceIdentityRow,
                '| `UnknownPhaseThreeArtifact` | `PRESENT / DEPLOYED` | `UNCALLED` | Unknown mutation row. |',
            ),
            'I8 missing required artifact' => $this->removeStructuralRow(
                $runtimeInventory,
                $sourceIdentityRow,
            ),
            'I9 whitespace-variant artifact identity' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox ` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Non-canonical mutation row. |',
            ),
            'I9 case-variant artifact identity' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                '| `Supplier_Import_Dispatch_Outbox` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Non-canonical mutation row. |',
            ),
        ];

        foreach ($inventoryMutations as $mutation => $mutatedInventory) {
            $this->assertNotSame(
                [],
                $this->runtimeInventoryContract($mutatedInventory)['violations'],
                "Mutation must fail closed: {$mutation}",
            );
        }
        $this->assertContains(
            'Duplicate runtime inventory artifact key: supplier_import_dispatch_outbox (2 occurrences).',
            $this->runtimeInventoryContract(
                $inventoryMutations['I1 conflicting outbox duplicate before canonical'],
            )['violations'],
        );

        $documents = $this->watchdogDocumentation();
        $watchdogPath = 'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md';
        $this->assertSame(
            1,
            preg_match(
                $this->watchdogStateContractPattern(),
                $documents[$watchdogPath],
                $watchdogContractMatch,
            ),
        );
        $documents[$watchdogPath] .= PHP_EOL.$watchdogContractMatch[0];
        $this->assertNotSame(
            [],
            $this->watchdogDocumentationContract(
                $documents,
                $this->readDocument(
                    'database/migrations/2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php',
                ),
            )['violations'],
            'D1 duplicate watchdog contract ID must fail closed.',
        );

        $this->assertNotSame(
            [],
            $this->authorizationProcedureContract(
                $this->registerAuthorizationProcedure($design, 'authorization-member-persistence'),
                $this->expectedAuthorizationTuple(),
            )['violations'],
            'D2 duplicate procedure registry ID must fail closed.',
        );
        $this->assertNotSame(
            [],
            $this->authorizationProcedureContract(
                $design.PHP_EOL.'<!-- normative-authorization-procedure:start id=authorization-member-persistence -->',
                $this->expectedAuthorizationTuple(),
            )['violations'],
            'D3 duplicate procedure start-marker ID must fail closed.',
        );

        $rolloutRows = $this->rolloutCheckpointContract(
            $this->markdownSection(
                $design,
                '### Fine-grained rollout checkpoints',
                '### Forward-only operational rollback and bounded schema downgrade',
            ),
        )['rows'];
        $duplicatedRolloutRows = [...$rolloutRows, $rolloutRows[0]];
        $duplicatedRolloutKeys = array_map(
            static function (string $row): array {
                $cells = array_map('trim', explode('|', trim($row, '|')));

                return ['id' => (int) $cells[0]];
            },
            $duplicatedRolloutRows,
        );
        $this->assertNotSame(
            [],
            $this->duplicateStructuralKeyViolations($duplicatedRolloutKeys, 'id', 'rollout checkpoint'),
            'D4 duplicate rollout checkpoint ID must fail before keyed conversion.',
        );
    }

    public function test_readiness_tables_discover_malformed_physical_rows_before_validation(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $readiness = $this->markdownSection(
            $design,
            '### Historical Phase III readiness findings (superseded)',
            '### Canonical source scope',
        );
        $runtimeInventory = $this->markdownSection(
            $plan,
            '### Current deployed artifact inventory',
            '### Remaining runtime implementation gaps',
        );

        $statusTwo = $this->structuralMarkdownRow($readiness, 'PH3-RDY-002');
        $statusThree = $this->structuralMarkdownRow($readiness, 'PH3-RDY-003');
        $unknownTwo = '| `PH3-RDY-002` | `UNKNOWN` | Invalid status mutation. |';
        $readinessMutations = [
            'S1 malformed duplicate before canonical' => $this->insertStructuralRow(
                $readiness,
                $statusTwo,
                $unknownTwo,
                before: true,
            ),
            'S2 malformed duplicate after canonical' => $this->insertStructuralRow(
                $readiness,
                $statusTwo,
                $unknownTwo,
            ),
            'S3 malformed replacement' => $this->replaceStructuralText($readiness, $statusTwo, $unknownTwo),
            'S4 pending status' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-002` | `PENDING` | Invalid status mutation. |',
            ),
            'S5 lowercase status' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-002` | `blocked` | Invalid status mutation. |',
            ),
            'S6 suffixed status' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-002` | `CLOSEDX` | Invalid status mutation. |',
            ),
            'S7 blank status' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-002` | `` | Invalid status mutation. |',
            ),
            'S8 malformed physical row' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| PH3-RDY-002 | `UNKNOWN` | Invalid row mutation. |',
            ),
            'S9 unknown readiness ID' => $this->insertStructuralRow(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-005` | `BLOCKED` | Unknown ID mutation. |',
            ),
            'S10 missing canonical row' => $this->removeStructuralRow($readiness, $statusThree),
        ];

        foreach ($readinessMutations as $mutation => $mutatedReadiness) {
            $this->assertNotSame(
                [],
                $this->readinessStatusContract($mutatedReadiness)['violations'],
                "Malformed readiness mutation must fail closed: {$mutation}",
            );
        }
        foreach (['S1 malformed duplicate before canonical', 'S2 malformed duplicate after canonical'] as $mutation) {
            $contract = $this->readinessStatusContract($readinessMutations[$mutation]);
            $this->assertSame(5, $contract['raw_count']);
            $this->assertSame(5, $contract['parsed_count']);
            $this->assertContains(['id' => 'PH3-RDY-002', 'status' => 'UNKNOWN'], $contract['rows']);
        }

        $outboxRow = $this->structuralMarkdownRow($runtimeInventory, 'supplier_import_dispatch_outbox');
        $claimModelRow = $this->structuralMarkdownRow($runtimeInventory, 'SupplierImportExecutionClaim');
        $sourceIdentityRow = $this->structuralMarkdownRow($runtimeInventory, 'SnapshotSourceIdentity');
        $unquotedOutbox = '| supplier_import_dispatch_outbox | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Invalid syntax mutation. |';
        $inventoryHeader = '| Artifact | Artifact status | Supplier-runtime status | Repository evidence |';
        $inventoryMutations = [
            'I-M1 malformed duplicate before canonical' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                $unquotedOutbox,
                before: true,
            ),
            'I-M2 malformed duplicate after canonical' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                $unquotedOutbox,
            ),
            'I-M3 missing column' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox` | `PRESENT / DEPLOYED` | Missing column mutation. |',
            ),
            'I-M4 extra column' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Evidence. | Extra. |',
            ),
            'I-M5 malformed status' => $this->replaceStructuralText(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox` | `PRESENT / UNKNOWN` | `INACTIVE / UNWIRED` | Invalid status mutation. |',
            ),
            'I-M6 blank status' => $this->replaceStructuralText(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox` | `` | `INACTIVE / UNWIRED` | Invalid status mutation. |',
            ),
            'I-M7 malformed Phase II model row' => $this->replaceStructuralText(
                $runtimeInventory,
                $claimModelRow,
                '| SupplierImportExecutionClaim | `PRESENT / DEPLOYED` | `UNCALLED` | Invalid syntax mutation. |',
            ),
            'I-M8 unknown artifact' => $this->insertStructuralRow(
                $runtimeInventory,
                $sourceIdentityRow,
                '| `UnknownPhaseThreeArtifact` | `PRESENT / DEPLOYED` | `UNCALLED` | Unknown mutation. |',
            ),
            'I-M9 missing required artifact' => $this->removeStructuralRow(
                $runtimeInventory,
                $sourceIdentityRow,
            ),
            'I-M10 malformed table header' => $this->replaceStructuralText(
                $runtimeInventory,
                $inventoryHeader,
                '| Artifact | Wrong status header | Supplier-runtime status | Repository evidence |',
            ),
        ];

        foreach ($inventoryMutations as $mutation => $mutatedInventory) {
            $this->assertNotSame(
                [],
                $this->runtimeInventoryContract($mutatedInventory)['violations'],
                "Malformed runtime inventory mutation must fail closed: {$mutation}",
            );
        }
        foreach (['I-M1 malformed duplicate before canonical', 'I-M2 malformed duplicate after canonical'] as $mutation) {
            $contract = $this->runtimeInventoryContract($inventoryMutations[$mutation]);
            $this->assertSame(24, $contract['raw_count']);
            $this->assertSame(23, $contract['parsed_count']);
        }

        $canonicalInventory = $this->runtimeInventoryContract($runtimeInventory);
        $this->assertSame(23, $canonicalInventory['raw_count']);
        $this->assertSame(23, $canonicalInventory['parsed_count']);
        $this->assertSame(23, $canonicalInventory['unique_count']);
        $this->assertSame(23, $canonicalInventory['expected_count']);
    }

    public function test_bounded_contract_regions_reject_terminal_and_malformed_structural_omissions(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $readiness = $this->markdownSection(
            $design,
            '### Historical Phase III readiness findings (superseded)',
            '### Canonical source scope',
        );
        $runtimeInventory = $this->markdownSection(
            $plan,
            '### Current deployed artifact inventory',
            '### Remaining runtime implementation gaps',
        );
        $rollout = $this->markdownSection(
            $design,
            '### Fine-grained rollout checkpoints',
            '### Forward-only operational rollback and bounded schema downgrade',
        );

        $statusOne = $this->structuralMarkdownRow($readiness, 'PH3-RDY-001');
        $statusTwo = $this->structuralMarkdownRow($readiness, 'PH3-RDY-002');
        $statusThree = $this->structuralMarkdownRow($readiness, 'PH3-RDY-003');
        $statusFour = $this->structuralMarkdownRow($readiness, 'PH3-RDY-004');
        $readinessLineEnding = str_contains($readiness, "\r\n") ? "\r\n" : "\n";
        $terminalReadiness = '`PH3-RDY-002` | `UNKNOWN` | Terminal malformed declaration. |';
        $readinessMutations = [
            'RT1 terminal missing opening pipe' => $this->insertStructuralRow(
                $readiness,
                $statusFour,
                $terminalReadiness,
            ),
            'RT2 middle missing opening pipe' => $this->insertStructuralRow(
                $readiness,
                $statusTwo,
                $terminalReadiness,
            ),
            'RT3 leading missing opening pipe' => $this->insertStructuralRow(
                $readiness,
                $statusOne,
                $terminalReadiness,
                before: true,
            ),
            'RT4 missing closing pipe' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-002` | `BLOCKED` | Missing closing delimiter.',
            ),
            'RT5 wrong cell count' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                '| `PH3-RDY-002` | `BLOCKED` |',
            ),
            'RT6 plain text declaration' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                'PH3-RDY-002 = UNKNOWN',
            ),
            'RT7 malformed delimiters' => $this->replaceStructuralText(
                $readiness,
                $statusTwo,
                'PH3-RDY-002 | UNKNOWN',
            ),
            'RT8 duplicate followed by malformed variant' => $this->insertStructuralRow(
                $readiness,
                $statusTwo,
                $statusTwo.$readinessLineEnding.$terminalReadiness,
            ),
            'RT9 malformed unknown readiness ID' => $this->insertStructuralRow(
                $readiness,
                $statusFour,
                '`PH3-RDY-005` | `BLOCKED` | Unknown malformed declaration. |',
            ),
        ];

        foreach ($readinessMutations as $mutation => $mutatedReadiness) {
            $this->assertNotSame(
                [],
                $this->readinessStatusContract($mutatedReadiness)['violations'],
                "Terminal readiness mutation must fail closed: {$mutation}",
            );
        }
        $terminalReadinessContract = $this->readinessStatusContract(
            $readinessMutations['RT1 terminal missing opening pipe'],
        );
        $this->assertSame(5, $terminalReadinessContract['raw_count']);
        $this->assertSame(4, $terminalReadinessContract['parsed_count']);
        $this->assertSame([], $this->readinessStatusContract($readiness)['violations'], 'RT10 canonical');

        $firstInventoryRow = $this->structuralMarkdownRow(
            $runtimeInventory,
            'supplier_import_execution_claims',
        );
        $outboxRow = $this->structuralMarkdownRow(
            $runtimeInventory,
            'supplier_import_dispatch_outbox',
        );
        $monitorRow = $this->structuralMarkdownRow(
            $runtimeInventory,
            'supplier_import_dispatch_monitor_health',
        );
        $claimModelRow = $this->structuralMarkdownRow(
            $runtimeInventory,
            'SupplierImportExecutionClaim',
        );
        $finalInventoryRow = $this->structuralMarkdownRow($runtimeInventory, 'SnapshotSourceIdentity');
        $terminalUnquotedOutbox = 'supplier_import_dispatch_outbox | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Terminal malformed declaration. |';
        $inventoryMutations = [
            'IT1 terminal unquoted outbox' => $this->insertStructuralRow(
                $runtimeInventory,
                $finalInventoryRow,
                $terminalUnquotedOutbox,
            ),
            'IT2 leading unquoted outbox' => $this->insertStructuralRow(
                $runtimeInventory,
                $firstInventoryRow,
                $terminalUnquotedOutbox,
                before: true,
            ),
            'IT3 middle unquoted outbox' => $this->insertStructuralRow(
                $runtimeInventory,
                $monitorRow,
                $terminalUnquotedOutbox,
                before: true,
            ),
            'IT4 missing opening pipe' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                '`supplier_import_dispatch_outbox` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Missing opening delimiter. |',
            ),
            'IT5 missing closing pipe' => $this->replaceStructuralText(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Missing closing delimiter.',
            ),
            'IT6 wrong cell count' => $this->replaceStructuralText(
                $runtimeInventory,
                $outboxRow,
                '| `supplier_import_dispatch_outbox` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` |',
            ),
            'IT7 plain text known artifact' => $this->replaceStructuralText(
                $runtimeInventory,
                $outboxRow,
                'supplier_import_dispatch_outbox = PRESENT / DEPLOYED',
            ),
            'IT8 malformed unknown artifact' => $this->insertStructuralRow(
                $runtimeInventory,
                $outboxRow,
                'UnknownRuntimeArtifact | `PRESENT / DEPLOYED` | `UNCALLED` | Unknown malformed declaration. |',
            ),
            'IT9 terminal malformed Phase II model' => $this->insertStructuralRow(
                $runtimeInventory,
                $finalInventoryRow,
                'SupplierImportExecutionClaim = PRESENT / DEPLOYED',
            ),
        ];

        foreach ($inventoryMutations as $mutation => $mutatedInventory) {
            $this->assertNotSame(
                [],
                $this->runtimeInventoryContract($mutatedInventory)['violations'],
                "Terminal inventory mutation must fail closed: {$mutation}",
            );
        }
        $terminalInventoryContract = $this->runtimeInventoryContract(
            $inventoryMutations['IT1 terminal unquoted outbox'],
        );
        $this->assertSame(24, $terminalInventoryContract['raw_count']);
        $this->assertSame(23, $terminalInventoryContract['parsed_count']);
        $this->assertSame([], $this->runtimeInventoryContract($runtimeInventory)['violations'], 'IT10 canonical');

        $canonicalRollout = $this->rolloutCheckpointContract($rollout);
        $this->assertSame([], $canonicalRollout['violations'], 'RO7 canonical');
        $row102 = collect($canonicalRollout['rows'])->first(
            static fn (string $row): bool => str_starts_with($row, '| 102 |'),
        );
        $row103 = collect($canonicalRollout['rows'])->first(
            static fn (string $row): bool => str_starts_with($row, '| 103 |'),
        );
        $this->assertIsString($row102);
        $this->assertIsString($row103);
        $malformedCheckpoint = '104 | malformed | none | none | none | none | fail | stop |';
        $rolloutMutations = [
            'RO1 terminal missing opening pipe' => $this->insertStructuralRow(
                $rollout,
                $row103,
                $malformedCheckpoint,
            ),
            'RO2 terminal missing closing pipe' => $this->insertStructuralRow(
                $rollout,
                $row103,
                '| 104 | malformed | none | none | none | none | fail | stop',
            ),
            'RO3 syntactically valid extra checkpoint' => $this->insertStructuralRow(
                $rollout,
                $row103,
                '| 104 | Extra checkpoint | checkpoint 103 | none | none | none | fail | stop |',
            ),
            'RO4 malformed duplicate checkpoint 103' => $this->insertStructuralRow(
                $rollout,
                $row103,
                '103 | duplicate | none | none | none | none | fail | stop |',
            ),
            'RO5 malformed checkpoint between 102 and 103' => $this->insertStructuralRow(
                $rollout,
                $row102,
                $malformedCheckpoint,
            ),
            'RO6 malformed unknown checkpoint ID' => $this->insertStructuralRow(
                $rollout,
                $row103,
                'checkpoint-x | malformed | none | none | none | none | fail | stop |',
            ),
        ];

        foreach ($rolloutMutations as $mutation => $mutatedRollout) {
            $this->assertNotSame(
                [],
                $this->rolloutCheckpointContract($mutatedRollout)['violations'],
                "Terminal rollout mutation must fail closed: {$mutation}",
            );
        }
        $terminalRolloutContract = $this->rolloutCheckpointContract(
            $rolloutMutations['RO1 terminal missing opening pipe'],
        );
        $this->assertSame(104, $terminalRolloutContract['raw_count']);
        $this->assertSame(103, $terminalRolloutContract['parsed_count']);

        $expectedTuple = $this->expectedAuthorizationTuple();
        $memberStart = '<!-- normative-authorization-procedure:start id=authorization-member-persistence -->';
        $memberEnd = '<!-- normative-authorization-procedure:end id=authorization-member-persistence -->';
        $memberDeclaration = 'Normative authorization procedure `authorization-member-persistence`';
        $malformedMarker = '<!-- normative-authorization-procedure start id=authorization-member-persistence -->';
        $procedureMutations = [
            'PM1 malformed start delimiter' => $this->replaceStructuralText(
                $design,
                $memberStart,
                $malformedMarker,
            ),
            'PM2 malformed end delimiter' => $this->replaceStructuralText(
                $design,
                $memberEnd,
                '<!-- normative-authorization-procedure end id=authorization-member-persistence -->',
            ),
            'PM3 malformed declaration delimiter' => $this->replaceStructuralText(
                $design,
                $memberDeclaration,
                'Normative authorization procedure: authorization-member-persistence',
            ),
            'PM4 missing procedure ID' => $this->replaceStructuralText(
                $design,
                $memberStart,
                '<!-- normative-authorization-procedure:start -->',
            ),
            'PM5 unknown marker type' => $this->replaceStructuralText(
                $design,
                $memberStart,
                '<!-- normative-authorization-procedure:middle id=authorization-member-persistence -->',
            ),
            'PM6 invalid procedure ID syntax' => $this->replaceStructuralText(
                $design,
                $memberStart,
                '<!-- normative-authorization-procedure:start id=Authorization_Member -->',
            ),
            'PM7 unexpected marker tokens' => $this->replaceStructuralText(
                $design,
                $memberStart,
                '<!-- normative-authorization-procedure:start id=authorization-member-persistence unexpected=true -->',
            ),
            'PM8 terminal malformed marker' => $design.PHP_EOL.$malformedMarker,
            'PM9 malformed marker between valid blocks' => $this->replaceStructuralText(
                $design,
                $memberEnd,
                $memberEnd.PHP_EOL.'<!-- normative-authorization-procedure start id=between-blocks -->',
            ),
        ];

        foreach ($procedureMutations as $mutation => $mutatedDesign) {
            $this->assertNotSame(
                [],
                $this->authorizationProcedureContract($mutatedDesign, $expectedTuple)['violations'],
                "Malformed procedure marker mutation must fail closed: {$mutation}",
            );
        }
        $terminalProcedureContract = $this->authorizationProcedureContract(
            $procedureMutations['PM8 terminal malformed marker'],
            $expectedTuple,
        );
        $this->assertSame(7, $terminalProcedureContract['marker_candidate_count']);
        $this->assertSame(3, $terminalProcedureContract['declaration_candidate_count']);
        $this->assertSame(
            [],
            $this->authorizationProcedureContract($design, $expectedTuple)['violations'],
            'PM10 canonical',
        );
    }

    public function test_other_high_value_bounded_tables_reject_terminal_omissions(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $tables = [
            'recovery protocol outcome' => [
                'section' => $this->markdownSection(
                    $design,
                    '### Operationally governed recovery protocol outcomes',
                    '## Cohort Enrollment Contract',
                ),
                'header' => '| Ownership and payload observation | Transport/response boundary | Permitted protocol outcome |',
                'separator' => '| --- | --- | --- |',
                'columns' => 3,
                'rows' => 19,
                'end' => 'This table contains exactly 19 data rows and 3 columns. Merely reaching',
            ],
            'crash and recovery' => [
                'section' => $this->markdownSection(
                    $design,
                    '### Crash and recovery matrix',
                    'Rows 52 through 66 are coordination-only crash domains.',
                ),
                'header' => '| Boundary | Path | SupplierImportRun | ImportJob | ImportHistory | Claim | Outbox | Evidence | Allowed recovery | Prohibited actions | Required operator action |',
                'separator' => '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |',
                'columns' => 11,
                'rows' => 66,
                'end' => null,
            ],
            'expected-state field inventory' => [
                'section' => $this->markdownSection(
                    $design,
                    'The canonical object contains exactly these 20 keys in this exact order;',
                    'The state machine still enforces every cross-field path',
                ),
                'header' => '| Position | Key | Exact JSON type and value contract | Nullable |',
                'separator' => '| --- | --- | --- | --- |',
                'columns' => 4,
                'rows' => 20,
                'end' => null,
            ],
            'cryptographic identity inventory' => [
                'section' => $this->markdownSection(
                    $design,
                    '### Authoritative cryptographic and digest identity inventory',
                    '### Exact hexadecimal storage contract',
                ),
                'header' => '| # | Identity | Purpose | Producer | Canonical bytes and domain | Algorithm | Persistence location | Immutability | Comparison point |',
                'separator' => '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
                'columns' => 9,
                'rows' => 22,
                'end' => 'The inventory count is contractual. Repeated persistence of one identity, such',
            ],
            'hexadecimal storage' => [
                'section' => $this->markdownSection(
                    $design,
                    '### Exact hexadecimal storage contract',
                    '## Append-only Enforcement',
                ),
                'header' => '| Table | Non-null lowercase hexadecimal columns | Nullable lowercase hexadecimal columns |',
                'separator' => '| --- | --- | --- |',
                'columns' => 3,
                'rows' => 10,
                'end' => 'No listed field uses `BINARY(32)` in this design. A later implementation may',
            ],
        ];

        foreach ($tables as $context => $definition) {
            $canonical = $this->structuralMarkdownTable(
                $definition['section'],
                $definition['header'],
                $definition['separator'],
                $context,
                $definition['columns'],
                $definition['end'],
            );
            $this->assertSame([], $canonical['violations'], "Canonical table must pass: {$context}");
            $this->assertSame($definition['rows'], $canonical['physical_count']);

            $lastRow = $canonical['rows'][array_key_last($canonical['rows'])];
            $terminalMalformed = "{$context} malformed terminal declaration";
            $mutated = $this->insertStructuralRow($definition['section'], $lastRow, $terminalMalformed);
            $mutatedContract = $this->structuralMarkdownTable(
                $mutated,
                $definition['header'],
                $definition['separator'],
                $context,
                $definition['columns'],
                $definition['end'],
            );

            $this->assertSame($definition['rows'] + 1, $mutatedContract['physical_count']);
            $this->assertNotSame(
                [],
                $mutatedContract['violations'],
                "Terminal malformed declaration must be discovered and rejected: {$context}",
            );
        }
    }

    public function test_strict_bounded_tables_reject_markdown_prefixed_and_pipe_free_declarations(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $readiness = $this->markdownSection(
            $design,
            '### Historical Phase III readiness findings (superseded)',
            '### Canonical source scope',
        );
        $runtimeInventory = $this->markdownSection(
            $plan,
            '### Current deployed artifact inventory',
            '### Remaining runtime implementation gaps',
        );
        $rollout = $this->markdownSection(
            $design,
            '### Fine-grained rollout checkpoints',
            '### Forward-only operational rollback and bounded schema downgrade',
        );

        $statusOne = $this->structuralMarkdownRow($readiness, 'PH3-RDY-001');
        $statusTwo = $this->structuralMarkdownRow($readiness, 'PH3-RDY-002');
        $statusFour = $this->structuralMarkdownRow($readiness, 'PH3-RDY-004');
        $readinessMutations = [
            'RD-P1 list dash' => $this->insertStructuralRow($readiness, $statusTwo, '- PH3-RDY-002 = UNKNOWN'),
            'RD-P2 list star' => $this->insertStructuralRow($readiness, $statusTwo, '* PH3-RDY-002 = UNKNOWN'),
            'RD-P3 blockquote' => $this->insertStructuralRow($readiness, $statusTwo, '> PH3-RDY-002 = UNKNOWN'),
            'RD-P4 ordered list' => $this->insertStructuralRow($readiness, $statusTwo, '1. PH3-RDY-002 = UNKNOWN'),
            'RD-P5 plain' => $this->insertStructuralRow($readiness, $statusTwo, 'PH3-RDY-002 = UNKNOWN'),
            'RD-P6 malformed pipe row' => $this->insertStructuralRow($readiness, $statusTwo, '| PH3-RDY-002 | UNKNOWN |'),
            'RD-P7 terminal malformed declaration' => $this->insertStructuralRow($readiness, $statusFour, 'PH3-RDY-002 = UNKNOWN'),
            'RD-P8 leading malformed declaration' => $this->insertStructuralRow(
                $readiness,
                $statusOne,
                'PH3-RDY-002 = UNKNOWN',
                before: true,
            ),
        ];
        foreach ($readinessMutations as $mutation => $mutatedReadiness) {
            $contract = $this->readinessStatusContract($mutatedReadiness);
            $this->assertSame(5, $contract['raw_count'], $mutation);
            $this->assertNotSame([], $contract['violations'], $mutation);
        }
        $lineEnding = str_contains($readiness, "\r\n") ? "\r\n" : "\n";
        $blankSeparatedDeclaration = $this->readinessStatusContract(
            $this->insertStructuralRow(
                $readiness,
                $statusFour,
                $lineEnding.'PH3-RDY-002 = UNKNOWN',
            ),
        );
        $this->assertSame(5, $blankSeparatedDeclaration['raw_count']);
        $this->assertNotSame([], $blankSeparatedDeclaration['violations']);
        $this->assertSame([], $this->readinessStatusContract($readiness)['violations'], 'RD-P9 canonical');

        $firstArtifact = $this->structuralMarkdownRow($runtimeInventory, 'supplier_import_execution_claims');
        $outbox = $this->structuralMarkdownRow($runtimeInventory, 'supplier_import_dispatch_outbox');
        $lastArtifact = $this->structuralMarkdownRow($runtimeInventory, 'SnapshotSourceIdentity');
        $inventoryMutations = [
            'RI-P1 list dash' => $this->insertStructuralRow($runtimeInventory, $outbox, '- supplier_import_dispatch_outbox = MISSING'),
            'RI-P2 list star' => $this->insertStructuralRow($runtimeInventory, $outbox, '* supplier_import_dispatch_outbox = MISSING'),
            'RI-P3 blockquote' => $this->insertStructuralRow($runtimeInventory, $outbox, '> supplier_import_dispatch_outbox = MISSING'),
            'RI-P4 ordered list' => $this->insertStructuralRow($runtimeInventory, $outbox, '1. supplier_import_dispatch_outbox = MISSING'),
            'RI-P5 plain' => $this->insertStructuralRow($runtimeInventory, $outbox, 'supplier_import_dispatch_outbox = MISSING'),
            'RI-P6 malformed pipe row' => $this->insertStructuralRow($runtimeInventory, $outbox, '| supplier_import_dispatch_outbox | MISSING |'),
            'RI-P7 terminal known artifact' => $this->insertStructuralRow($runtimeInventory, $lastArtifact, 'supplier_import_dispatch_outbox = MISSING'),
            'RI-P8 leading unknown artifact' => $this->insertStructuralRow(
                $runtimeInventory,
                $firstArtifact,
                'unknown_runtime_artifact = MISSING',
                before: true,
            ),
        ];
        foreach ($inventoryMutations as $mutation => $mutatedInventory) {
            $contract = $this->runtimeInventoryContract($mutatedInventory);
            $this->assertSame(24, $contract['raw_count'], $mutation);
            $this->assertNotSame([], $contract['violations'], $mutation);
        }
        $this->assertSame([], $this->runtimeInventoryContract($runtimeInventory)['violations'], 'RI-P9 canonical');

        $canonicalRollout = $this->rolloutCheckpointContract($rollout);
        $row103 = $canonicalRollout['rows'][array_key_last($canonicalRollout['rows'])];
        $rolloutMutations = [
            'RO-P1 list dash' => '- Checkpoint 104 = malformed declaration',
            'RO-P2 list star' => '* Checkpoint 104 = malformed declaration',
            'RO-P3 blockquote' => '> Checkpoint 104 = malformed declaration',
            'RO-P4 ordered list' => '1. Checkpoint 104 = malformed declaration',
            'RO-P5 plain' => 'Checkpoint 104 = malformed declaration',
            'RO-P6 malformed pipe row' => '| Checkpoint 104 | malformed |',
            'RO-P7 valid extra checkpoint' => '| 104 | Extra checkpoint | checkpoint 103 | none | none | none | fail | stop |',
        ];
        foreach ($rolloutMutations as $mutation => $declaration) {
            $contract = $this->rolloutCheckpointContract(
                $this->insertStructuralRow($rollout, $row103, $declaration),
            );
            $this->assertSame(104, $contract['raw_count'], $mutation);
            $this->assertNotSame([], $contract['violations'], $mutation);
        }
        $this->assertSame([], $canonicalRollout['violations'], 'RO-P8 canonical');
    }

    public function test_singleton_authority_blocks_and_lexical_markers_reject_shadowing(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $expectedTuple = $this->expectedAuthorizationTuple();
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $registryBlock = implode($lineEnding, [
            'Normative authorization procedure registry (ordered):',
            '',
            '```text',
            'authorization-member-persistence',
            'capture-start-coordinator',
            'bounded-capture-collector',
            '```',
        ]);
        $registryMutations = [
            'PR-B1 second identical registry block' => $design.$lineEnding.$lineEnding.$registryBlock,
            'PR-B2 second conflicting registry block' => $design.$lineEnding.$lineEnding.str_replace(
                'bounded-capture-collector',
                'conflicting-procedure',
                $registryBlock,
            ),
            'PR-B3 empty second registry block' => $design.$lineEnding.$lineEnding.implode($lineEnding, [
                'Normative authorization procedure registry (ordered):',
                '',
                '```text',
                '```',
            ]),
            'PR-B4 malformed second registry block' => $design.$lineEnding.$lineEnding.implode($lineEnding, [
                'Normative authorization procedure registry ordered:',
                '',
                '```text',
                'malformed-procedure',
                '```',
            ]),
            'PR-B5 registry absent' => $this->replaceStructuralText($design, $registryBlock, ''),
        ];
        foreach ($registryMutations as $mutation => $mutatedDesign) {
            $this->assertNotSame(
                [],
                $this->authorizationProcedureContract($mutatedDesign, $expectedTuple)['violations'],
                $mutation,
            );
        }
        $canonicalRegistry = $this->authorizationProcedureContract($design, $expectedTuple);
        $this->assertSame([], $canonicalRegistry['violations'], 'PR-B6 canonical');
        $this->assertSame(1, $canonicalRegistry['registry_declaration_count']);
        $this->assertSame(1, $canonicalRegistry['registry_valid_block_count']);

        $sourceBinding = $this->markdownSection(
            $design,
            '### PH3-RDY-002 authorization binding and PH3-RDY-001 candidate provenance',
            '### PH3-RDY-003 authoritative-limit inventory and unresolved gate',
        );
        $tupleBlock = implode($lineEnding, [
            'Canonical proposed future authorization completeness tuple (ordered):',
            '',
            '```text',
            ...$expectedTuple,
            '```',
        ]);
        $tupleMutations = [
            'CT-B1 second identical tuple block' => $sourceBinding.$lineEnding.$lineEnding.$tupleBlock,
            'CT-B2 second tuple with wrong fifth field' => $sourceBinding.$lineEnding.$lineEnding.str_replace(
                'cohort_source_identity',
                'wrong_source_identity',
                $tupleBlock,
            ),
            'CT-B3 second four-field tuple' => $sourceBinding.$lineEnding.$lineEnding.implode($lineEnding, [
                'Canonical proposed future authorization completeness tuple (ordered):',
                '',
                '```text',
                ...array_slice($expectedTuple, 0, 4),
                '```',
            ]),
            'CT-B4 second six-field tuple' => $sourceBinding.$lineEnding.$lineEnding.implode($lineEnding, [
                'Canonical proposed future authorization completeness tuple (ordered):',
                '',
                '```text',
                ...$expectedTuple,
                'unexpected_sixth_field',
                '```',
            ]),
            'CT-B5 malformed second tuple declaration' => $sourceBinding.$lineEnding.$lineEnding.implode($lineEnding, [
                'Canonical proposed future authorization completeness tuple ordered:',
                '',
                '```text',
                ...$expectedTuple,
                '```',
            ]),
            'CT-B6 tuple absent' => $this->replaceStructuralText($sourceBinding, $tupleBlock, ''),
        ];
        foreach ($tupleMutations as $mutation => $mutatedSourceBinding) {
            $this->assertNotSame(
                [],
                $this->canonicalAuthorizationCompletenessTupleContract($mutatedSourceBinding)['violations'],
                $mutation,
            );
        }
        $canonicalTuple = $this->canonicalAuthorizationCompletenessTupleContract($sourceBinding);
        $this->assertSame([], $canonicalTuple['violations'], 'CT-B7 canonical');
        $this->assertSame(1, $canonicalTuple['declaration_count']);
        $this->assertSame(1, $canonicalTuple['valid_block_count']);

        $memberStart = '<!-- normative-authorization-procedure:start id=authorization-member-persistence -->';
        $procedureMarkerMutations = [
            'PM-X1 malformed delimiter' => '<!-- normative-authorization-procedure start id=authorization-member-persistence -->',
            'PM-X2 list-prefixed malformed marker' => '- '.$memberStart,
            'PM-X3 blockquote-prefixed malformed marker' => '> '.$memberStart,
            'PM-X4 missing marker type' => '<!-- normative-authorization-procedure id=authorization-member-persistence -->',
            'PM-X5 invalid type' => '<!-- normative-authorization-procedure:middle id=authorization-member-persistence -->',
            'PM-X6 missing procedure ID' => '<!-- normative-authorization-procedure:start -->',
            'PM-X7 invalid procedure ID' => '<!-- normative-authorization-procedure:start id=Authorization_Member -->',
            'PM-X8 extra trailing tokens' => '<!-- normative-authorization-procedure:start id=authorization-member-persistence extra=true -->',
        ];
        foreach ($procedureMarkerMutations as $mutation => $marker) {
            $this->assertNotSame(
                [],
                $this->authorizationProcedureContract(
                    $this->replaceStructuralText($design, $memberStart, $marker),
                    $expectedTuple,
                )['violations'],
                $mutation,
            );
        }
        $this->assertNotSame(
            [],
            $this->authorizationProcedureContract(
                $design.$lineEnding.'<!-- normative-authorization-procedure start id=terminal-malformed -->',
                $expectedTuple,
            )['violations'],
            'PM-X9 malformed marker after final valid block',
        );
        $this->assertSame([], $canonicalRegistry['violations'], 'PM-X10 canonical');
    }

    public function test_watchdog_context_and_current_state_contracts_reject_remaining_contradictions(): void
    {
        $documents = $this->watchdogDocumentation();
        $path = 'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md';
        $context = '<!-- watchdog-document-context classification=SCHEMA_DEFINITION_REFERENCE column_occurrences=40 index_occurrences=4 contract=watchdog-current-state-v1 -->';
        $lineEnding = str_contains($documents[$path], "\r\n") ? "\r\n" : "\n";
        $contextMutations = [
            'WC1 identical duplicate' => $context.$lineEnding.$context,
            'WC2 conflicting duplicate' => $context.$lineEnding.'<!-- watchdog-document-context classification=HISTORICAL column_occurrences=40 index_occurrences=4 contract=watchdog-current-state-v1 -->',
            'WC3 malformed delimiter' => '<!-- watchdog-document-context: classification=SCHEMA_DEFINITION_REFERENCE column_occurrences=40 index_occurrences=4 contract=watchdog-current-state-v1 -->',
            'WC4 malformed ID' => '<!-- watchdog-document-context classification=SCHEMA_DEFINITION_REFERENCE column_occurrences=40 index_occurrences=4 contract=Watchdog_Invalid -->',
            'WC5 list-prefixed marker' => '- '.$context,
            'WC6 blockquote-prefixed marker' => '> '.$context,
            'WC7 extra trailing tokens' => '<!-- watchdog-document-context classification=SCHEMA_DEFINITION_REFERENCE column_occurrences=40 index_occurrences=4 contract=watchdog-current-state-v1 extra=true -->',
            'WC9 missing marker' => '',
        ];
        $migration = $this->readDocument(
            'database/migrations/2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php',
        );
        foreach ($contextMutations as $mutation => $replacement) {
            $mutated = $this->mutateDocument($documents, $path, $context, $replacement);
            $this->assertNotSame(
                [],
                $this->watchdogDocumentationContract($mutated, $migration)['violations'],
                $mutation,
            );
        }
        $wrongDocument = [
            ...$documents,
            'docs/WATCHDOG_WRONG_CONTEXT_EXAMPLE.md' => $context,
        ];
        $this->assertNotSame(
            [],
            $this->watchdogDocumentationContract($wrongDocument, $migration)['violations'],
            'WC8 context marker in wrong document',
        );
        $this->assertSame(
            [],
            $this->watchdogDocumentationContract($documents, $migration)['violations'],
            'WC10 canonical',
        );

        $design = $documents[$path];
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $this->assertSame([], $this->phaseThreeCurrentStateViolations($design, $plan));
        $contradictions = [
            'RC-H current authorization contradiction' => [
                str_replace(
                    'remains historical/staging evidence only and is not current canonical Phase III'.
                    $lineEnding.'authorization',
                    'is therefore authorized as current canonical Phase III authorization',
                    $design,
                ),
                $plan,
            ],
            'RC-I proposed deployed hexadecimal columns' => [
                str_replace(
                    'The exact affected deployed Phase I hexadecimal columns are:',
                    'The exact affected proposed columns are:',
                    $design,
                ),
                $plan,
            ],
            'RC-J future Phase I deployment' => [
                $design,
                str_replace(
                    'The completed Phase I staging deployment added schema'.$lineEnding.'only.',
                    'Future deployment adds schema only.',
                    $plan,
                ),
            ],
        ];
        foreach ($contradictions as $mutation => [$mutatedDesign, $mutatedPlan]) {
            $this->assertNotSame(
                [],
                $this->phaseThreeCurrentStateViolations($mutatedDesign, $mutatedPlan),
                $mutation,
            );
        }
    }

    public function test_watchdog_contract_boundaries_are_discovered_before_pairing_and_body_validation(): void
    {
        $documents = $this->watchdogDocumentation();
        $path = 'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md';
        $start = '<!-- watchdog-current-state-contract:start id=watchdog-current-state-v1 -->';
        $end = '<!-- watchdog-current-state-contract:end id=watchdog-current-state-v1 -->';
        $this->assertSame(
            1,
            preg_match($this->watchdogStateContractPattern(), $documents[$path], $contractMatch),
        );
        $fullContract = $contractMatch[0];
        $malformedBody = str_replace('```text', '```json', $fullContract);

        $mutations = [
            'W-M1 mismatched IDs' => $this->mutateDocument(
                $documents,
                $path,
                $end,
                '<!-- watchdog-current-state-contract:end id=watchdog-current-state-v2 -->',
            ),
            'W-M2 orphan start' => $this->mutateDocument($documents, $path, $end, ''),
            'W-M3 orphan end' => $this->mutateDocument($documents, $path, $start, ''),
            'W-M4 duplicate start' => $this->mutateDocument(
                $documents,
                $path,
                $start,
                $start.PHP_EOL.$start,
            ),
            'W-M5 duplicate end' => $this->mutateDocument(
                $documents,
                $path,
                $end,
                $end.PHP_EOL.$end,
            ),
            'W-M6 duplicate contract' => [
                ...$documents,
                $path => $documents[$path].PHP_EOL.$fullContract,
            ],
            'W-M7 malformed start syntax' => $this->mutateDocument(
                $documents,
                $path,
                $start,
                '<!-- watchdog-current-state-contract:start id=watchdog-current-state-v1 ->',
            ),
            'W-M8 malformed end syntax' => $this->mutateDocument(
                $documents,
                $path,
                $end,
                '<!-- watchdog-current-state-contract:end id=watchdog-current-state-v1 ->',
            ),
            'W-M9 malformed body' => $this->mutateDocument(
                $documents,
                $path,
                $fullContract,
                $malformedBody,
            ),
        ];

        $migration = $this->readDocument(
            'database/migrations/2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php',
        );
        foreach ($mutations as $mutation => $mutatedDocuments) {
            $this->assertNotSame(
                [],
                $this->watchdogDocumentationContract($mutatedDocuments, $migration)['violations'],
                "Malformed watchdog mutation must fail closed: {$mutation}",
            );
        }

        $mismatchedBoundaries = $this->watchdogBoundedDeclarations(
            $mutations['W-M1 mismatched IDs'],
            'watchdog-current-state-contract',
            'id',
            'watchdog current-state contract',
        );
        $this->assertSame(1, $mismatchedBoundaries['start_count']);
        $this->assertSame(1, $mismatchedBoundaries['end_count']);
        $this->assertNotSame([], $mismatchedBoundaries['violations']);

        $canonicalBoundaries = $this->watchdogBoundedDeclarations(
            $documents,
            'watchdog-current-state-contract',
            'id',
            'watchdog current-state contract',
        );
        $this->assertSame([], $canonicalBoundaries['violations']);
        $this->assertSame(1, $canonicalBoundaries['start_count']);
        $this->assertSame(1, $canonicalBoundaries['end_count']);
        $this->assertCount(1, $canonicalBoundaries['declarations']);
        $this->assertSame([], $this->watchdogDocumentationContract($documents, $migration)['violations']);
    }

    public function test_readiness_status_and_procedure_enumeration_reject_adversarial_mutations(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $expectedTuple = $this->expectedAuthorizationTuple();
        $validBlock = $this->markedAuthorizationProcedureBlock('future-procedure', $expectedTuple);

        $procedureMutations = [
            'marked four-field procedure' => $this->appendRegisteredProcedure(
                $design,
                'future-four-fields',
                $this->markedAuthorizationProcedureBlock(
                    'future-four-fields',
                    array_slice($expectedTuple, 0, 4),
                ),
            ),
            'marked procedure with wrong fifth field' => $this->appendRegisteredProcedure(
                $design,
                'future-wrong-field',
                $this->markedAuthorizationProcedureBlock(
                    'future-wrong-field',
                    [...array_slice($expectedTuple, 0, 4), 'wrong_source_identity'],
                ),
            ),
            'marked procedure with sixth field' => $this->appendRegisteredProcedure(
                $design,
                'future-six-fields',
                $this->markedAuthorizationProcedureBlock(
                    'future-six-fields',
                    [...$expectedTuple, 'unexpected_sixth_field'],
                ),
            ),
            'marked procedure missing atomic source binding' => $this->appendRegisteredProcedure(
                $design,
                'future-no-atomic-source',
                $this->markedAuthorizationProcedureBlock(
                    'future-no-atomic-source',
                    $expectedTuple,
                    includeAtomicSourceBinding: false,
                ),
            ),
            'marked procedure omitted from registry' => $design.PHP_EOL.PHP_EOL.$validBlock,
            'phantom registry procedure' => $this->registerAuthorizationProcedure($design, 'phantom-procedure'),
            'duplicate procedure ID' => $design.PHP_EOL.PHP_EOL.$this->markedAuthorizationProcedureBlock(
                'authorization-member-persistence',
                $expectedTuple,
            ),
            'source token outside a four-field tuple' => $this->appendRegisteredProcedure(
                $design,
                'future-outside-source',
                $this->markedAuthorizationProcedureBlock(
                    'future-outside-source',
                    array_slice($expectedTuple, 0, 4),
                ),
            ),
            'existing procedure loses marker' => str_replace(
                '<!-- normative-authorization-procedure:start id=authorization-member-persistence -->',
                '<!-- authorization procedure start marker removed -->',
                $design,
            ),
            'registry and section rename mismatch' => $this->renameRegisteredAuthorizationProcedure(
                $design,
                'authorization-member-persistence',
                'renamed-member-persistence',
            ),
        ];

        foreach ($procedureMutations as $mutation => $mutatedDesign) {
            $this->assertNotSame(
                [],
                $this->authorizationProcedureContract($mutatedDesign, $expectedTuple)['violations'],
                "Mutation must fail closed: {$mutation}",
            );
        }

    }

    public function test_watchdog_current_state_contract_is_structural_exhaustive_and_repository_grounded(): void
    {
        $documents = $this->watchdogDocumentation();
        $migration = $this->readDocument(
            'database/migrations/2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php',
        );
        $contract = $this->watchdogDocumentationContract($documents, $migration);

        $this->assertSame([], $contract['violations'], implode(PHP_EOL, $contract['violations']));
        $this->assertSame([
            'docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md',
            'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md',
            'docs/ROADMAP.md',
            'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md',
        ], $contract['relevant_documents']);
        $this->assertSame([
            'docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md' => [
                'classification' => 'CURRENT_SCHEMA_STATUS',
                'column_occurrences' => 0,
                'index_occurrences' => 0,
                'contract' => 'watchdog-current-state-v1',
            ],
            'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md' => [
                'classification' => 'SCHEMA_DEFINITION_REFERENCE',
                'column_occurrences' => 40,
                'index_occurrences' => 4,
                'contract' => 'watchdog-current-state-v1',
            ],
            'docs/ROADMAP.md' => [
                'classification' => 'SCHEMA_DEFINITION_REFERENCE',
                'column_occurrences' => 2,
                'index_occurrences' => 1,
                'contract' => 'watchdog-current-state-v1',
            ],
            'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md' => [
                'classification' => 'CURRENT_SCHEMA_STATUS',
                'column_occurrences' => 0,
                'index_occurrences' => 0,
                'contract' => 'watchdog-current-state-v1',
            ],
        ], $contract['contexts']);
        $this->assertSame([
            'schema_table' => 'supplier_import_dispatch_outbox',
            'column_name' => 'delivery_watchdog_at',
            'column_state' => 'PRESENT / DEPLOYED',
            'column_type' => 'TIMESTAMP',
            'column_precision' => '6',
            'column_nullable' => 'YES',
            'index_name' => 'ix_import_dispatch_outbox_state_watchdog_id',
            'index_state' => 'PRESENT / DEPLOYED',
            'index_ordered_columns' => 'state,delivery_watchdog_at,id',
            'runtime_state' => 'INACTIVE / UNWIRED',
            'future_work' => 'RUNTIME ENABLEMENT ONLY; NO SCHEMA ADDITION',
        ], $contract['state']);

        $withoutStatus = $documents;
        $withoutStatus['docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md'] = preg_replace(
            $this->watchdogStateContractPattern(),
            '',
            $withoutStatus['docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md'],
            1,
            $removedStatusCount,
        ) ?? '';
        $this->assertSame(1, $removedStatusCount);

        $futureColumn = $documents;
        $futureColumnPath = 'docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md';
        $futureColumn[$futureColumnPath] = preg_replace(
            $this->watchdogStateReferencePattern(),
            '`delivery_watchdog_at` remains a future schema addition.',
            $futureColumn[$futureColumnPath],
            1,
            $replacedColumnStatusCount,
        ) ?? '';
        $this->assertSame(1, $replacedColumnStatusCount);

        $futureIndex = $documents;
        $futureIndexPath = 'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md';
        $futureIndex[$futureIndexPath] = preg_replace(
            $this->watchdogStateReferencePattern(),
            '`ix_import_dispatch_outbox_state_watchdog_id` will be added later.',
            $futureIndex[$futureIndexPath],
            1,
            $replacedIndexStatusCount,
        ) ?? '';
        $this->assertSame(1, $replacedIndexStatusCount);

        $mutations = [
            'W1 required status omitted' => $withoutStatus,
            'W2 column described as future' => $futureColumn,
            'W3 index described as future' => $futureIndex,
            'W4 wrong column state' => $this->replaceWatchdogDocumentation(
                $documents,
                'column_state=PRESENT / DEPLOYED',
                'column_state=MISSING',
            ),
            'W5 wrong runtime state' => $this->replaceWatchdogDocumentation(
                $documents,
                'runtime_state=INACTIVE / UNWIRED',
                'runtime_state=ACTIVE',
            ),
            'W6 reordered index' => $this->replaceWatchdogDocumentation(
                $documents,
                'index_ordered_columns=state,delivery_watchdog_at,id',
                'index_ordered_columns=state,id,delivery_watchdog_at',
            ),
            'W7 truncated index' => $this->replaceWatchdogDocumentation(
                $documents,
                'index_ordered_columns=state,delivery_watchdog_at,id',
                'index_ordered_columns=state,delivery_watchdog_at',
            ),
            'W8 unclassified new document' => [
                ...$documents,
                'docs/UNCLASSIFIED_WATCHDOG_NOTE.md' => 'Unclassified `delivery_watchdog_at` note.',
            ],
        ];

        foreach ($mutations as $mutation => $mutatedDocuments) {
            $violations = $this->watchdogDocumentationContract($mutatedDocuments, $migration)['violations'];

            $this->assertNotSame([], $violations, "Mutation must fail closed: {$mutation}");
        }

        $markedHistorical = [
            ...$documents,
            'docs/WATCHDOG_HISTORY_EXAMPLE.md' => implode(PHP_EOL, [
                '<!-- watchdog-document-context classification=HISTORICAL column_occurrences=1 index_occurrences=0 contract=watchdog-current-state-v1 -->',
                'Historical design evidence mentions `delivery_watchdog_at` before Phase I deployment.',
            ]),
        ];
        $this->assertSame(
            [],
            $this->watchdogDocumentationContract($markedHistorical, $migration)['violations'],
            'W9 explicitly marked historical context must remain valid.',
        );

        $futureRuntime = [
            ...$documents,
            'docs/WATCHDOG_RUNTIME_EXAMPLE.md' => implode(PHP_EOL, [
                '<!-- watchdog-document-context classification=FUTURE_RUNTIME_BEHAVIOR column_occurrences=1 index_occurrences=0 contract=watchdog-current-state-v1 -->',
                'A separately authorized runtime may populate `delivery_watchdog_at` using the already deployed schema.',
            ]),
        ];
        $this->assertSame(
            [],
            $this->watchdogDocumentationContract($futureRuntime, $migration)['violations'],
            'W10 future runtime behavior over deployed schema must remain valid.',
        );
    }

    /** @return array<int, string> */
    private function expectedPhaseThreeP0Tables(): array
    {
        return [
            'supplier_import_source_profiles',
            'supplier_import_source_executions',
            'supplier_import_source_payload_receipts',
            'supplier_product_identity_heads',
            'supplier_product_source_revisions',
        ];
    }

    /**
     * @return array{allocation_set: array<int, string>, guard_set: array<int, string>, violations: array<int, string>}
     */
    private function phaseThreeP0RollbackSetContract(string $plan): array
    {
        $expected = $this->expectedPhaseThreeP0Tables();
        $violations = [];
        $markerIntentCount = 0;
        $validMarkers = [];

        foreach (preg_split('/\R/', $plan) ?: [] as $lineNumber => $line) {
            if (! str_contains(strtolower($line), 'phase-iii-p0-table-set-registry')) {
                continue;
            }

            $markerIntentCount++;
            if (preg_match(
                '/^<!-- phase-iii-p0-table-set-registry classification=CURRENT id=(?<id>phase-iii-p0-(?:new-table-allocation|destructive-downgrade-table-set)-v1) -->$/',
                $line,
                $start,
            ) === 1) {
                $validMarkers[] = ['type' => 'start', 'id' => $start['id']];

                continue;
            }
            if (preg_match(
                '/^<!-- phase-iii-p0-table-set-registry:end id=(?<id>phase-iii-p0-(?:new-table-allocation|destructive-downgrade-table-set)-v1) -->$/',
                $line,
                $end,
            ) === 1) {
                $validMarkers[] = ['type' => 'end', 'id' => $end['id']];

                continue;
            }

            $violations[] = 'Malformed Phase III-P0 table-set registry marker at line '.($lineNumber + 1).'.';
        }
        if ($markerIntentCount !== 4 || count($validMarkers) !== 4) {
            $violations[] = 'Exactly two complete CURRENT Phase III-P0 table-set registries are required.';
        }
        foreach (['phase-iii-p0-new-table-allocation-v1', 'phase-iii-p0-destructive-downgrade-table-set-v1'] as $id) {
            $starts = array_filter($validMarkers, static fn (array $marker): bool => $marker['type'] === 'start' && $marker['id'] === $id);
            $ends = array_filter($validMarkers, static fn (array $marker): bool => $marker['type'] === 'end' && $marker['id'] === $id);
            if (count($starts) !== 1 || count($ends) !== 1) {
                $violations[] = "Phase III-P0 registry {$id} must have exactly one CURRENT start and one end marker.";
            }
        }

        $allocation = $this->structuralMarkdownTable(
            $plan,
            '| Allocation position | Phase III-P0 new table |',
            '| :---: | :--- |',
            'Phase III-P0 new-table allocation registry',
            2,
            '<!-- phase-iii-p0-table-set-registry:end id=phase-iii-p0-new-table-allocation-v1 -->',
        );
        $guard = $this->structuralMarkdownTable(
            $plan,
            '| Guard position | Phase III-P0 guarded table | Required empty/pristine evidence |',
            '| :---: | :--- | :--- |',
            'Phase III-P0 destructive-downgrade registry',
            3,
            '<!-- phase-iii-p0-table-set-registry:end id=phase-iii-p0-destructive-downgrade-table-set-v1 -->',
        );
        $violations = [...$violations, ...$allocation['violations'], ...$guard['violations']];
        $allocationRows = $this->phaseThreeP0TableSetRows($allocation['rows'], 2, 'allocation');
        $guardRows = $this->phaseThreeP0TableSetRows($guard['rows'], 3, 'destructive guard');
        $violations = [...$violations, ...$allocationRows['violations'], ...$guardRows['violations']];
        $allocationSet = $allocationRows['tables'];
        $guardSet = $guardRows['tables'];

        if (count($allocationSet) !== 5 || count(array_unique($allocationSet)) !== 5) {
            $violations[] = 'Phase III-P0 new-table allocation must contain exactly five unique tables.';
        }
        if (count($guardSet) !== 5 || count(array_unique($guardSet)) !== 5) {
            $violations[] = 'Phase III-P0 destructive guard must contain exactly five unique tables.';
        }
        $sortedAllocation = $allocationSet;
        $sortedGuard = $guardSet;
        $sortedExpected = $expected;
        sort($sortedAllocation);
        sort($sortedGuard);
        sort($sortedExpected);
        if ($sortedAllocation !== $sortedExpected) {
            $violations[] = 'Phase III-P0 allocation set does not match the canonical five-table registry.';
        }
        if ($sortedGuard !== $sortedExpected) {
            $violations[] = 'Phase III-P0 destructive guard set does not match the canonical five-table registry.';
        }
        if (array_diff($sortedAllocation, $sortedGuard) !== [] || array_diff($sortedGuard, $sortedAllocation) !== []) {
            $violations[] = 'Phase III-P0 allocation and destructive-guard table sets are not exactly equal.';
        }
        foreach ($guardRows['evidence'] as $table => $evidence) {
            if (! str_contains($evidence, 'table exists and contains zero rows')) {
                $violations[] = "Phase III-P0 destructive guard lacks exact empty/pristine evidence for {$table}.";
            }
        }
        if (preg_match('/\bfour new tables\b/i', $plan) === 1) {
            $violations[] = 'Stale four-table Phase III-P0 rollback declaration remains current.';
        }
        $normalizedPlan = preg_replace('/\s+/', ' ', $plan) ?? $plan;
        foreach ([
            'Every row must prove its predicate before the first destructive DDL statement',
            'The two structural table sets must be exactly equal as unordered five-member',
            'does not by itself authorize removal of evidence-bearing columns on existing',
        ] as $authority) {
            if (! str_contains($normalizedPlan, $authority)) {
                $violations[] = "Missing Phase III-P0 rollback authority: {$authority}.";
            }
        }

        return [
            'allocation_set' => $allocationSet,
            'guard_set' => $guardSet,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @param  array<int, string>  $rows
     * @return array{tables: array<int, string>, evidence: array<string, string>, violations: array<int, string>}
     */
    private function phaseThreeP0TableSetRows(array $rows, int $columns, string $context): array
    {
        $tables = [];
        $evidence = [];
        $violations = [];
        foreach ($rows as $position => $row) {
            $parsed = $this->structuralMarkdownRowCells($row, $columns, "Phase III-P0 {$context}", $position + 1);
            $violations = [...$violations, ...$parsed['violations']];
            if ($parsed['cells'] === null) {
                continue;
            }

            if ($parsed['cells'][0] !== (string) ($position + 1)
                || preg_match('/^`(?<table>[a-z0-9_]+)`$/', $parsed['cells'][1], $table) !== 1) {
                $violations[] = "Malformed Phase III-P0 {$context} row ".($position + 1).'.';

                continue;
            }
            $tables[] = $table['table'];
            if ($columns === 3) {
                $evidence[$table['table']] = $parsed['cells'][2];
            }
        }

        return ['tables' => $tables, 'evidence' => $evidence, 'violations' => $violations];
    }

    /**
     * @return array{
     *     payload: array<string, string>,
     *     selector: array<string, string>,
     *     payload_inventory: array<string, int>,
     *     selector_inventory: array<string, int>,
     *     architecture_document_inventory: array<string, mixed>,
     *     current_architecture_inventory: array<string, mixed>,
     *     candidate_count: int,
     *     violations: array<int, string>
     * }
     */
    private function phaseThreeExclusiveSemanticContract(string $design): array
    {
        $architecture = $this->phaseThreeArchitectureContract($design);
        $violations = $architecture['violations'];
        $markerIntentCount = 0;
        $markers = [];
        foreach (preg_split('/\R/', $design) ?: [] as $lineNumber => $line) {
            if (! str_contains(strtolower($line), 'phase-iii-semantic-registry')) {
                continue;
            }

            $markerIntentCount++;
            if (preg_match(
                '/^<!-- phase-iii-semantic-registry classification=(?<classification>CURRENT|HISTORICAL|SUPERSEDED) id=(?<id>phase-iii-[a-z0-9-]+) -->$/',
                $line,
                $start,
            ) === 1) {
                $markers[] = ['type' => 'start', 'classification' => $start['classification'], 'id' => $start['id']];

                continue;
            }
            if (preg_match(
                '/^<!-- phase-iii-semantic-registry:end id=(?<id>phase-iii-[a-z0-9-]+) -->$/',
                $line,
                $end,
            ) === 1) {
                $markers[] = ['type' => 'end', 'classification' => null, 'id' => $end['id']];

                continue;
            }

            $violations[] = 'Malformed Phase III semantic-registry marker at line '.($lineNumber + 1).'.';
        }
        if ($markerIntentCount !== count($markers) || count($markers) < 4) {
            $violations[] = 'Every Phase III semantic-registry marker candidate must be structurally valid.';
        }
        foreach (['phase-iii-payload-integrity-contract-v1', 'phase-iii-import-job-selector-contract-v1'] as $id) {
            $starts = array_filter($markers, static fn (array $marker): bool => $marker['type'] === 'start' && $marker['classification'] === 'CURRENT' && $marker['id'] === $id);
            $ends = array_filter($markers, static fn (array $marker): bool => $marker['type'] === 'end' && $marker['id'] === $id);
            if (count($starts) !== 1 || count($ends) !== 1) {
                $violations[] = "Phase III semantic registry {$id} must have exactly one CURRENT start and one end marker.";
            }
        }
        $startIds = array_column(array_filter($markers, static fn (array $marker): bool => $marker['type'] === 'start'), 'id');
        $endIds = array_column(array_filter($markers, static fn (array $marker): bool => $marker['type'] === 'end'), 'id');
        foreach (array_unique([...$startIds, ...$endIds]) as $id) {
            if (count(array_filter($startIds, static fn (string $candidate): bool => $candidate === $id)) !== 1
                || count(array_filter($endIds, static fn (string $candidate): bool => $candidate === $id)) !== 1) {
                $violations[] = "Phase III semantic registry {$id} must contain exactly one paired start/end marker.";
            }
        }
        foreach (array_filter($markers, static fn (array $marker): bool => $marker['type'] === 'start' && $marker['classification'] === 'CURRENT') as $marker) {
            if (! in_array($marker['id'], ['phase-iii-payload-integrity-contract-v1', 'phase-iii-import-job-selector-contract-v1'], true)) {
                $violations[] = "Unexpected CURRENT Phase III semantic registry {$marker['id']}.";
            }
        }

        $payloadExpected = $this->expectedPhaseThreePayloadSemantics();
        $selectorExpected = $this->expectedPhaseThreeSelectorSemantics();
        $payload = $this->phaseThreeClosedSemanticRegistry(
            $design,
            'phase-iii-payload-integrity-contract-v1',
            '| Payload semantic key | Canonical value |',
            '| ---: | :--- |',
            $payloadExpected,
            'payload-integrity',
        );
        $selector = $this->phaseThreeClosedSemanticRegistry(
            $design,
            'phase-iii-import-job-selector-contract-v1',
            '| ImportJob selector semantic key | Canonical value |',
            '| :--- | ---: |',
            $selectorExpected,
            'ImportJob-selector',
        );
        $violations = [...$violations, ...$payload['violations'], ...$selector['violations']];
        if ($payload['values'] !== $payloadExpected) {
            $violations[] = 'Payload-integrity semantic registry does not exactly match its canonical key/value authority.';
        }
        if ($selector['values'] !== $selectorExpected) {
            $violations[] = 'ImportJob-selector semantic registry does not exactly match its canonical key/value authority.';
        }

        $architectureDocument = $this->phaseThreeClosedArchitectureDocumentContract($design);
        $currentArchitecture = $this->phaseThreeClosedCurrentArchitectureContract($design);
        $violations = [
            ...$violations,
            ...$architectureDocument['violations'],
            ...$currentArchitecture['violations'],
        ];
        $diagnostics = $this->phaseThreeGlobalSemanticDiagnostics($design);

        return [
            'payload' => $payload['values'],
            'selector' => $selector['values'],
            'payload_inventory' => $payload['inventory'],
            'selector_inventory' => $selector['inventory'],
            'architecture_document_inventory' => $architectureDocument['inventory'],
            'current_architecture_inventory' => $currentArchitecture['inventory'],
            'candidate_count' => $diagnostics['candidate_count'],
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{
     *     inventory: array<string, mixed>,
     *     violations: array<int, string>
     * }
     */
    private function phaseThreeClosedArchitectureDocumentContract(string $design): array
    {
        $expected = $this->expectedPhaseThreeArchitectureDocumentInventory();
        $normalizedDesign = str_replace(["\r\n", "\r"], "\n", $design);
        $lines = explode("\n", $normalizedDesign);
        $startMarker = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->';
        $endMarker = '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v1 -->';
        $startPositions = array_keys($lines, $startMarker, true);
        $endPositions = array_keys($lines, $endMarker, true);
        $violations = [];
        $inventory = [
            'version' => $expected['version'],
            'classification' => 'UNRESOLVED_ARCHITECTURE_DOCUMENT',
            'normalized_bytes' => 0,
            'line_count' => 0,
            'unit_count' => 0,
            'unit_categories' => [],
            'byte_fingerprint' => '',
            'unit_fingerprint' => '',
            'region_order' => [],
            'regions' => [],
        ];

        if (count($startPositions) !== 1) {
            $violations[] = 'Closed architecture document requires exactly one exact CURRENT authority marker.';
        }
        if (count($endPositions) !== 1) {
            $violations[] = 'Closed architecture document requires exactly one exact CURRENT contract end marker.';
        }
        if (count($startPositions) !== 1 || count($endPositions) !== 1) {
            return ['inventory' => $inventory, 'violations' => $violations];
        }

        $startOffset = strpos($normalizedDesign, $startMarker);
        $endMarkerOffset = strpos($normalizedDesign, $endMarker, $startOffset);
        if ($startOffset === false || $endMarkerOffset === false || $endMarkerOffset <= $startOffset) {
            $violations[] = 'Closed architecture document regions must be ordered pre-CURRENT, CURRENT, post-CURRENT.';

            return ['inventory' => $inventory, 'violations' => $violations];
        }

        $endOffset = $endMarkerOffset + strlen($endMarker);
        $regionContents = [
            'pre-current-reference-history-v1' => substr($normalizedDesign, 0, $startOffset),
            'current-architecture-authority-v1' => substr($normalizedDesign, $startOffset, $endOffset - $startOffset),
            'post-current-reference-history-v1' => substr($normalizedDesign, $endOffset),
        ];
        $whole = $this->phaseThreeCanonicalArchitectureInventory(
            $normalizedDesign,
            'phase-iii-architecture-document-unit',
            'phase-iii-architecture-document-normalized-bytes-v1',
            'phase-iii-architecture-document-unit-inventory-v1',
            4,
        );
        $regions = [];
        foreach ($regionContents as $position => $content) {
            $region = $this->phaseThreeCanonicalArchitectureInventory(
                $content,
                "phase-iii-architecture-document-{$position}-unit",
                "phase-iii-architecture-document-{$position}-normalized-bytes-v1",
                "phase-iii-architecture-document-{$position}-unit-inventory-v1",
                4,
            );
            $regions[$position] = [
                'id' => $position,
                'position' => count($regions) + 1,
                ...$region,
            ];
        }
        $inventory = [
            'version' => $expected['version'],
            'classification' => 'CANONICAL_ARCHITECTURE_DOCUMENT',
            ...$whole,
            'region_order' => array_keys($regions),
            'regions' => $regions,
        ];

        foreach (['normalized_bytes', 'line_count', 'unit_count', 'unit_categories', 'byte_fingerprint', 'unit_fingerprint', 'region_order'] as $field) {
            if ($inventory[$field] !== $expected[$field]) {
                $violations[] = "Closed architecture document {$field} does not match the canonical inventory.";
            }
        }
        foreach ($expected['regions'] as $id => $expectedRegion) {
            if (! array_key_exists($id, $regions)) {
                $violations[] = "Closed architecture document is missing canonical region {$id}.";

                continue;
            }
            foreach (['id', 'position', 'normalized_bytes', 'line_count', 'unit_count', 'unit_categories', 'byte_fingerprint', 'unit_fingerprint'] as $field) {
                if ($regions[$id][$field] !== $expectedRegion[$field]) {
                    $violations[] = "Closed architecture region {$id} {$field} does not match the canonical inventory.";
                }
            }
        }
        if ($violations !== []) {
            $inventory['classification'] = 'DOCUMENT_STRUCTURAL_MISMATCH';
        }

        return ['inventory' => $inventory, 'violations' => array_values(array_unique($violations))];
    }

    /**
     * @return array{
     *     inventory: array<string, mixed>,
     *     violations: array<int, string>
     * }
     */
    private function phaseThreeClosedCurrentArchitectureContract(string $design): array
    {
        $expected = $this->expectedPhaseThreeCurrentArchitectureInventory();
        $normalizedDesign = str_replace(["\r\n", "\r"], "\n", $design);
        $lines = explode("\n", $normalizedDesign);
        $startMarker = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->';
        $endMarker = '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v1 -->';
        $startPositions = array_keys($lines, $startMarker, true);
        $endPositions = array_keys($lines, $endMarker, true);
        $violations = [];
        $inventory = [
            'version' => $expected['version'],
            'classification' => 'UNRESOLVED_CURRENT_ARCHITECTURE',
            'normalized_bytes' => 0,
            'line_count' => 0,
            'unit_count' => 0,
            'unit_categories' => [],
            'byte_fingerprint' => '',
            'unit_fingerprint' => '',
        ];

        if (count($startPositions) !== 1) {
            $violations[] = 'Closed CURRENT architecture inventory requires exactly one exact authority start marker.';
        }
        if (count($endPositions) !== 1) {
            $violations[] = 'Closed CURRENT architecture inventory requires exactly one exact contract end marker.';
        }
        if (count($startPositions) !== 1 || count($endPositions) !== 1) {
            return ['inventory' => $inventory, 'violations' => $violations];
        }

        $start = $startPositions[0];
        $end = $endPositions[0];
        if ($end <= $start) {
            $violations[] = 'Closed CURRENT architecture inventory end marker must follow its authority marker.';

            return ['inventory' => $inventory, 'violations' => $violations];
        }

        $currentAuthority = implode("\n", array_slice($lines, $start, $end - $start + 1));
        $computed = $this->phaseThreeCanonicalArchitectureInventory(
            $currentAuthority,
            'phase-iii-current-architecture-unit',
            'phase-iii-current-architecture-normalized-bytes-v1',
            'phase-iii-current-architecture-unit-inventory-v1',
            3,
        );
        $inventory = [
            'version' => $expected['version'],
            'classification' => 'CANONICAL_CURRENT_ARCHITECTURE',
            ...$computed,
        ];

        foreach (['normalized_bytes', 'line_count', 'unit_count', 'unit_categories', 'byte_fingerprint', 'unit_fingerprint'] as $field) {
            if ($inventory[$field] !== $expected[$field]) {
                $violations[] = "Closed CURRENT architecture {$field} does not match the canonical inventory.";
            }
        }
        if ($violations !== []) {
            $inventory['classification'] = 'STRUCTURAL_MISMATCH';
        }

        return ['inventory' => $inventory, 'violations' => array_values(array_unique($violations))];
    }

    /** @return array<string, mixed> */
    private function phaseThreeCanonicalArchitectureInventory(
        string $content,
        string $unitIdPrefix,
        string $byteFingerprintDomain,
        string $unitFingerprintDomain,
        int $unitIdWidth,
    ): array {
        $blocks = $this->phaseThreeArchitectureStructuralBlocks($content);
        $units = [];
        $categories = [];
        foreach ($blocks as $position => $block) {
            $raw = str_replace(["\r\n", "\r"], "\n", $block['raw']);
            $category = $block['literal']
                ? 'CANONICAL_LITERAL_EXACT'
                : match ($block['type']) {
                    'marker' => 'CANONICAL_MARKER_EXACT',
                    'heading' => 'CANONICAL_HEADING_EXACT',
                    'table' => 'CANONICAL_TABLE_EXACT',
                    'paragraph' => 'CANONICAL_PARAGRAPH_EXACT',
                    'blockquote' => 'CANONICAL_BLOCKQUOTE_EXACT',
                    'definition' => 'CANONICAL_DEFINITION_EXACT',
                    'html' => 'CANONICAL_HTML_EXACT',
                };
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $units[] = [
                'id' => $unitIdPrefix.'-'.str_pad((string) ($position + 1), $unitIdWidth, '0', STR_PAD_LEFT),
                'category' => $category,
                'type' => $block['type'],
                'literal' => $block['literal'],
                'normalized_bytes' => strlen($raw),
                'sha256' => hash('sha256', $raw),
            ];
        }
        ksort($categories);

        $unitBytes = json_encode($units, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'normalized_bytes' => strlen($content),
            'line_count' => count(explode("\n", $content)),
            'unit_count' => count($units),
            'unit_categories' => $categories,
            'byte_fingerprint' => hash('sha256', $byteFingerprintDomain."\0".$content),
            'unit_fingerprint' => hash('sha256', $unitFingerprintDomain."\0".$unitBytes),
        ];
    }

    /** @return array<string, mixed> */
    private function expectedPhaseThreeArchitectureDocumentInventory(): array
    {
        return [
            'version' => 'phase-iii-architecture-document-closed-world-v1',
            'normalized_bytes' => 489576,
            'line_count' => 6849,
            'unit_count' => 1038,
            'unit_categories' => [
                'CANONICAL_HEADING_EXACT' => 84,
                'CANONICAL_LITERAL_EXACT' => 69,
                'CANONICAL_MARKER_EXACT' => 18,
                'CANONICAL_PARAGRAPH_EXACT' => 810,
                'CANONICAL_TABLE_EXACT' => 57,
            ],
            'byte_fingerprint' => '54eddaf3e5ff1574ed53237157ec6d3792802fa5c55e8e5501d29a1ca3bbdf5f',
            'unit_fingerprint' => '5809da8985e5cc595727c5ac8088f79d012b90ff201e3808ad245a1e307c31b9',
            'region_order' => [
                'pre-current-reference-history-v1',
                'current-architecture-authority-v1',
                'post-current-reference-history-v1',
            ],
            'regions' => [
                'pre-current-reference-history-v1' => [
                    'id' => 'pre-current-reference-history-v1',
                    'position' => 1,
                    'normalized_bytes' => 243798,
                    'line_count' => 3748,
                    'unit_count' => 565,
                    'unit_categories' => [
                        'CANONICAL_HEADING_EXACT' => 33,
                        'CANONICAL_LITERAL_EXACT' => 37,
                        'CANONICAL_MARKER_EXACT' => 8,
                        'CANONICAL_PARAGRAPH_EXACT' => 462,
                        'CANONICAL_TABLE_EXACT' => 25,
                    ],
                    'byte_fingerprint' => 'd72c5a8db42843073b55fb5c4117c9301e0f34bcbadd3789f70996b962cfa900',
                    'unit_fingerprint' => '5b4cbaef1e6138c3b27fb6712363259ba54f7f7f932d7eacc5dd7d73292c4af1',
                ],
                'current-architecture-authority-v1' => [
                    'id' => 'current-architecture-authority-v1',
                    'position' => 2,
                    'normalized_bytes' => 82274,
                    'line_count' => 1359,
                    'unit_count' => 194,
                    'unit_categories' => [
                        'CANONICAL_HEADING_EXACT' => 14,
                        'CANONICAL_LITERAL_EXACT' => 15,
                        'CANONICAL_MARKER_EXACT' => 8,
                        'CANONICAL_PARAGRAPH_EXACT' => 145,
                        'CANONICAL_TABLE_EXACT' => 12,
                    ],
                    'byte_fingerprint' => '26f226784b943bfb09cf74825f3002028429bc7cd96d0aef0dcb34af58bce16b',
                    'unit_fingerprint' => '371e630360c7309f929803181c12d18f1dfdcab645201a800e96fe57261471b5',
                ],
                'post-current-reference-history-v1' => [
                    'id' => 'post-current-reference-history-v1',
                    'position' => 3,
                    'normalized_bytes' => 163504,
                    'line_count' => 1744,
                    'unit_count' => 279,
                    'unit_categories' => [
                        'CANONICAL_HEADING_EXACT' => 37,
                        'CANONICAL_LITERAL_EXACT' => 17,
                        'CANONICAL_MARKER_EXACT' => 2,
                        'CANONICAL_PARAGRAPH_EXACT' => 203,
                        'CANONICAL_TABLE_EXACT' => 20,
                    ],
                    'byte_fingerprint' => '3f3b83b47bd0c2e8070950f75cf9f1c020817ecbb6a2c7b3e44bd7cce0b3ba18',
                    'unit_fingerprint' => '9bb15a2f5f2d3d16fb83528c265e48c180aa4d91870991aafa0a70e8f1dde992',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function expectedPhaseThreeCurrentArchitectureInventory(): array
    {
        return [
            'version' => 'phase-iii-current-architecture-closed-world-v1',
            'normalized_bytes' => 82274,
            'line_count' => 1359,
            'unit_count' => 194,
            'unit_categories' => [
                'CANONICAL_HEADING_EXACT' => 14,
                'CANONICAL_LITERAL_EXACT' => 15,
                'CANONICAL_MARKER_EXACT' => 8,
                'CANONICAL_PARAGRAPH_EXACT' => 145,
                'CANONICAL_TABLE_EXACT' => 12,
            ],
            'byte_fingerprint' => 'b7321947f4429df9554bd4ecfc0fa1d68fea6b3acc8edb9f45a99411a87d4239',
            'unit_fingerprint' => '2f2e1be2dad168eb9c380bb8070a364df125eb86de2c31e6308ad7c5100eeb08',
        ];
    }

    /** @return array<string, string> */
    private function expectedPhaseThreePayloadSemantics(): array
    {
        return [
            'receipt_cardinality' => 'EXACTLY_ONE_PER_SOURCE_EXECUTION',
            'receipt_execution_binding' => 'REQUIRED_IMMUTABLE',
            'payload_digest_algorithm' => 'SHA-256',
            'payload_digest_domain' => 'EXACT_ACCEPTED_DECODED_PARSER_INPUT_BYTES',
            'payload_path_reopen' => 'FORBIDDEN',
            'parser_success_before_full_eof_verification' => 'FORBIDDEN',
            'receipt_mutability' => 'APPEND_ONLY_NO_UPDATE_REPLACE_CLEAR_DELETE',
            'receipt_rebinding' => 'FORBIDDEN',
            'parser_receipt_verification' => 'REQUIRED',
            'authoritative_handle_identity' => 'SAME_VERIFIED_FILE_OBJECT',
        ];
    }

    /** @return array<string, string> */
    private function expectedPhaseThreeSelectorSemantics(): array
    {
        return [
            'identity_ordered_fields' => 'schema>import_job_id>supplier_id>supplier_feed_id>xml_mapping_template_id>import_type',
            'required_template_selector' => 'XML_REQUIRED_CSV_NULL',
            'lock_order' => 'IMPORT_JOB>SUPPLIER_FEED>XML_MAPPING_TEMPLATE',
            'import_job_row_locking' => 'FOR_UPDATE_REQUIRED',
            'supplier_feed_row_locking' => 'FOR_UPDATE_REQUIRED',
            'template_row_locking' => 'XML_FOR_UPDATE_REQUIRED',
            'selector_verification_boundary' => 'SAME_SOURCE_RESOLUTION_TRANSACTION',
            'mapping_snapshot_authority' => 'IMMUTABLE_CANONICAL_BYTES_AND_FINGERPRINT',
            'mutable_template_reread_after_commit' => 'FORBIDDEN_FOR_HISTORICAL_AUTHORITY',
            'retry_current_selector_reread' => 'FORBIDDEN_FOR_HISTORICAL_AUTHORITY',
            'source_execution_identity_binding' => 'REQUIRED_IMMUTABLE',
        ];
    }

    /**
     * @param  array<string, string>  $expected
     * @return array{
     *     values: array<string, string>,
     *     inventory: array{
     *         raw_blocks: int,
     *         current_blocks: int,
     *         physical_rows: int,
     *         parsed_rows: int,
     *         unique_keys: int,
     *         expected_keys: int,
     *         unexpected_units: int
     *     },
     *     violations: array<int, string>
     * }
     */
    private function phaseThreeClosedSemanticRegistry(
        string $design,
        string $id,
        string $header,
        string $separator,
        array $expected,
        string $context,
    ): array {
        $lines = preg_split('/\R/', $design) ?: [];
        $startMarker = "<!-- phase-iii-semantic-registry classification=CURRENT id={$id} -->";
        $endMarker = "<!-- phase-iii-semantic-registry:end id={$id} -->";
        $rawBlocks = 0;
        foreach ($lines as $line) {
            $normalized = strtolower($line);
            if (str_contains($normalized, 'phase-iii-semantic-registry')
                && str_contains($normalized, strtolower($id))
                && ! str_contains($normalized, 'semantic-registry:end')) {
                $rawBlocks++;
            }
        }

        $startPositions = array_keys($lines, $startMarker, true);
        $endPositions = array_keys($lines, $endMarker, true);
        $violations = [];
        $values = [];
        $physicalRows = 0;
        $parsedRows = 0;
        $unexpectedUnits = 0;

        if ($rawBlocks !== 1 || count($startPositions) !== 1) {
            $violations[] = "Phase III {$context} semantic authority must contain exactly one valid CURRENT block.";
        }
        if (count($endPositions) !== 1) {
            $violations[] = "Phase III {$context} semantic authority must contain exactly one valid closing marker.";
        }

        if (count($startPositions) === 1 && count($endPositions) === 1) {
            $start = $startPositions[0];
            $end = $endPositions[0];
            if ($end <= $start) {
                $violations[] = "Phase III {$context} semantic authority closing marker must follow its CURRENT marker.";
            } else {
                $units = array_values(array_filter(
                    array_slice($lines, $start + 1, $end - $start - 1),
                    static fn (string $line): bool => trim($line) !== '',
                ));
                if (($units[0] ?? null) !== $header) {
                    $violations[] = "Phase III {$context} closed authority must begin with its exact header.";
                    $unexpectedUnits++;
                }
                if (($units[1] ?? null) !== $separator) {
                    $violations[] = "Phase III {$context} closed authority separator must immediately follow its header.";
                    $unexpectedUnits++;
                }

                foreach (array_slice($units, 2) as $position => $row) {
                    $physicalRows++;
                    $parsed = $this->structuralMarkdownRowCells(
                        $row,
                        2,
                        "Phase III {$context} closed semantic authority",
                        $position + 1,
                    );
                    if ($parsed['cells'] === null
                        || preg_match('/^`(?<key>[a-z0-9_]+)`$/', $parsed['cells'][0], $key) !== 1
                        || preg_match('/^`(?<value>[A-Za-z0-9_>\-]+)`$/', $parsed['cells'][1], $value) !== 1) {
                        $unexpectedUnits++;
                        $violations[] = "Unexpected nonblank unit in Phase III {$context} closed semantic authority at position ".($position + 1).'.';

                        continue;
                    }

                    $parsedRows++;
                    $duplicate = array_key_exists($key['key'], $values);
                    if ($duplicate) {
                        $violations[] = "Duplicate Phase III {$context} semantic key {$key['key']}.";
                    }
                    if ($duplicate
                        || ! array_key_exists($key['key'], $expected)
                        || $expected[$key['key']] !== $value['value']) {
                        $unexpectedUnits++;
                    }
                    $values[$key['key']] = $value['value'];
                }
            }
        }

        if ($physicalRows !== count($expected)) {
            $violations[] = "Phase III {$context} semantic authority must contain exactly ".count($expected).' physical rows.';
        }
        if ($parsedRows !== count($expected)) {
            $violations[] = "Phase III {$context} semantic authority must contain exactly ".count($expected).' parsed rows.';
        }
        if (count($values) !== count($expected)) {
            $violations[] = "Phase III {$context} semantic authority must contain exactly ".count($expected).' unique keys.';
        }
        if ($unexpectedUnits !== 0) {
            $violations[] = "Phase III {$context} closed semantic authority contains unexpected structural content.";
        }

        return [
            'values' => $values,
            'inventory' => [
                'raw_blocks' => $rawBlocks,
                'current_blocks' => count($startPositions),
                'physical_rows' => $physicalRows,
                'parsed_rows' => $parsedRows,
                'unique_keys' => count($values),
                'expected_keys' => count($expected),
                'unexpected_units' => $unexpectedUnits,
            ],
            'violations' => array_values(array_unique($violations)),
        ];
    }

    private function insertPhaseThreeSemanticAuthorityUnit(string $design, string $id, string $unit): string
    {
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $endMarker = "<!-- phase-iii-semantic-registry:end id={$id} -->";
        $this->assertSame(1, substr_count($design, $endMarker), "Missing unique semantic authority end marker {$id}.");

        return str_replace($endMarker, $unit.$lineEnding.$endMarker, $design);
    }

    private function insertPhaseThreeCurrentArchitectureUnit(
        string $design,
        string $anchor,
        string $unit,
        bool $after = false,
    ): string {
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $this->assertSame(1, substr_count($design, $anchor), "Missing unique CURRENT architecture anchor {$anchor}.");
        $replacement = $after
            ? $anchor.$lineEnding.$lineEnding.$unit
            : $unit.$lineEnding.$lineEnding.$anchor;

        return str_replace($anchor, $replacement, $design);
    }

    private function phaseThreeCurrentArchitectureBlock(string $design): string
    {
        $start = '<!-- phase-iii-architecture-authority classification=CURRENT id=phase-iii-architecture-contract-v1 -->';
        $end = '<!-- phase-iii-architecture-contract:end id=phase-iii-architecture-contract-v1 -->';
        $pattern = '/^'.preg_quote($start, '/').'$.*?^'.preg_quote($end, '/').'$/ms';
        $this->assertSame(1, preg_match($pattern, $design, $match), 'Missing exact CURRENT architecture block.');

        return $match[0];
    }

    private function phaseThreeSemanticRegistryBlock(string $design, string $id): string
    {
        $pattern = '/^<!-- phase-iii-semantic-registry classification=CURRENT id='.preg_quote($id, '/').' -->$.*?^<!-- phase-iii-semantic-registry:end id='.preg_quote($id, '/').' -->$/ms';
        $this->assertSame(1, preg_match($pattern, $design, $match), "Missing semantic registry block {$id}.");

        return $match[0];
    }

    /** @return array{candidate_count: int} */
    private function phaseThreeGlobalSemanticDiagnostics(string $design): array
    {
        $candidateCount = 0;
        foreach ($this->phaseThreeArchitectureStructuralBlocks($design) as $block) {
            if ($block['literal']) {
                continue;
            }
            if ($block['type'] === 'table' && str_contains($block['raw'], 'semantic key')) {
                continue;
            }

            $candidateCount += count($this->phaseThreeSemanticAssertions($block['raw']));
        }

        return ['candidate_count' => $candidateCount];
    }

    /** @return array<string, string> */
    private function phaseThreeSemanticAssertions(string $raw): array
    {
        $text = strtolower($this->phaseThreeArchitectureDiscoveryText($raw));
        $compact = preg_replace('/[^a-z0-9]+/', '', $text) ?? $text;
        $assertions = [];

        if ((str_contains($text, 'payload') || str_contains($text, 'receipt'))
            && (str_contains($text, 'digest') || str_contains($text, 'sha-') || str_contains($text, 'sha ') || str_contains($text, 'md5'))) {
            if (preg_match('/\bsha[ -]?1\b/', $text) === 1) {
                $assertions['payload_digest_algorithm'] = 'SHA-1';
            } elseif (preg_match('/\bmd5\b/', $text) === 1) {
                $assertions['payload_digest_algorithm'] = 'MD5';
            } elseif (preg_match('/\bsha[ -]?256\b/', $text) === 1) {
                $assertions['payload_digest_algorithm'] = 'SHA-256';
            }
        }

        if (str_contains($text, 'parser') && str_contains($text, 'reopen') && str_contains($text, 'path')) {
            $forbidden = preg_match('/\b(?:no|never)\b.{0,120}\b(?:may |can )?reopen\b|\b(?:must not|forbidden)\b.{0,120}\breopen\b/', $text) === 1;
            $assertions['payload_path_reopen'] = ! $forbidden
                && preg_match('/\b(?:may|can)\b.*\breopen\b|\breopen\b.*\ballowed\b/', $text) === 1
                    ? 'ALLOWED'
                    : 'FORBIDDEN';
        }
        if (str_contains($text, 'parser') && str_contains($text, 'eof')
            && (str_contains($text, 'success') || str_contains($text, 'successful'))) {
            $forbidden = preg_match('/\b(?:early )?parser success\b.{0,120}\bforbidden\b|\bno\b.{0,160}\bsuccessful\b.{0,160}\buntil eof\b/', $text) === 1;
            $assertions['parser_success_before_full_eof_verification'] = ! $forbidden
                && preg_match('/\bmay\b.*\b(?:return )?(?:protected )?success\b.*\bbefore\b.*\beof\b|\bsuccess\b.*\bbefore\b.*\beof\b.*\ballowed\b/', $text) === 1
                    ? 'ALLOWED'
                    : 'FORBIDDEN';
        }
        if ((str_contains($text, 'payload receipt') || str_contains($text, 'committed receipt'))
            && (str_contains($text, 'replace') || str_contains($text, 'replacement'))) {
            $forbidden = preg_match('/\breplacement\b.{0,160}\b(?:forbidden|reject|fails?)\b|\b(?:update and delete|update or delete)\b.{0,120}\bforbidden\b/', $text) === 1;
            $assertions['receipt_mutability'] = ! $forbidden
                && preg_match('/\b(?:may|can)\b.*\breplac|\breplacement\b.*\ballowed\b/', $text) === 1
                    ? 'REPLACEMENT_ALLOWED'
                    : 'APPEND_ONLY_NO_UPDATE_REPLACE_CLEAR_DELETE';
        }
        if (preg_match('/payload receipt.*(?:different|another).*source execution|receipt execution binding.*optional/', $text) === 1) {
            $assertions['receipt_execution_binding'] = 'OPTIONAL_OR_REBINDABLE';
        } elseif (preg_match('/binds one complete accepted parser input payload to exactly one immutable source execution/', $text) === 1) {
            $assertions['receipt_execution_binding'] = 'REQUIRED_IMMUTABLE';
        }
        if (str_contains($compact, 'xmlmappingtemplateid') && preg_match('/\b(?:optional|omit|omitted)\b/', $text) === 1) {
            $assertions['required_template_selector'] = 'OPTIONAL';
        }
        if (str_contains($compact, 'importjob') && str_contains($compact, 'supplierfeed')
            && str_contains($compact, 'xmlmappingtemplate')
            && (str_contains($text, 'lock order') || str_contains($text, 'lock authority'))) {
            $job = strpos($compact, 'importjob');
            $feed = strpos($compact, 'supplierfeed');
            $template = strpos($compact, 'xmlmappingtemplate');
            $assertions['lock_order'] = is_int($job) && is_int($feed) && is_int($template) && $job < $feed && $feed < $template
                ? 'IMPORT_JOB>SUPPLIER_FEED>XML_MAPPING_TEMPLATE'
                : 'NON_CANONICAL_ORDER';
        }
        if (str_contains($text, 'importjob row lock') && str_contains($text, 'optional')) {
            $assertions['import_job_row_locking'] = 'OPTIONAL';
        }
        if (str_contains($text, 'selector') && str_contains($text, 'outside') && str_contains($text, 'transaction')) {
            $assertions['selector_verification_boundary'] = 'OUTSIDE_TRANSACTION';
        }
        if ((str_contains($text, 'may reread') || str_contains($text, 'by rereading'))
            && (str_contains($text, 'mapping template') || str_contains($text, 'current importjob'))) {
            $key = str_contains($text, 'retry')
                ? 'retry_current_selector_reread'
                : 'mutable_template_reread_after_commit';
            $assertions[$key] = 'ALLOWED';
        }
        if (str_contains($text, 'eof') && str_contains($text, 'verification')
            && (str_contains($text, 'advisory') || str_contains($text, 'optional'))) {
            $assertions['parser_receipt_verification'] = 'ADVISORY';
        }

        return $assertions;
    }

    /** @return array<int, string> */
    private function phaseThreeOrderedFields(string $architecture, string $declaration, string $context): array
    {
        $contract = $this->phaseThreeOrderedFieldContract($architecture, $declaration, $context);

        $this->assertSame([], $contract['violations'], implode(PHP_EOL, $contract['violations']));

        return $contract['fields'];
    }

    /**
     * @param  array<string, string>  $documents
     * @return array{statuses: array<string, string>, reference_count: int, violations: array<int, string>}
     */
    private function phaseThreeRepositoryStatusAuthorityContract(array $documents): array
    {
        $expectedKeys = ['design', 'plan', 'phases', 'roadmap', 'onboarding', 'apcom'];
        $violations = [];
        if (array_keys($documents) !== $expectedKeys) {
            $violations[] = 'Phase III repository status documents do not match the canonical ordered registry.';
        }

        $design = $documents['design'] ?? '';
        $semantic = $this->phaseThreeArchitectureSemanticContract($design);
        $violations = [...$violations, ...$semantic['violations']];
        $architecture = $this->phaseThreeArchitectureContract($design);
        $status = $this->phaseThreeArchitectureStatusContract($architecture['body']);
        $violations = [...$violations, ...$status['violations']];

        $exactReference = '<!-- phase-iii-architecture-status-reference authority=phase-iii-architecture-contract-v1 -->';
        $exactTarget = 'IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#phase-iii-provenance-and-bounds-architecture-decision';
        $referenceCount = 0;
        foreach (array_diff($expectedKeys, ['design']) as $key) {
            $document = $documents[$key] ?? '';
            $candidateCount = 0;
            $exactCount = 0;
            $targetCount = substr_count($document, $exactTarget);
            foreach ($this->phaseThreeArchitectureStructuralBlocks($document) as $block) {
                if ($block['literal']) {
                    continue;
                }

                $normalized = $this->phaseThreeArchitectureNormalizedTokens($block['raw']);
                $isExactReference = trim($block['raw']) === $exactReference;
                if ($isExactReference || preg_match('/\bphase iii architecture(?: status)? reference\b/', $normalized) === 1) {
                    $candidateCount++;
                    if ($isExactReference) {
                        $exactCount++;
                    }
                }

                if ($this->phaseThreeArchitectureIdentifiers($block['raw']) !== []) {
                    $violations[] = "{$key} mirrors a Phase III status identifier instead of using the canonical reference.";
                }
            }

            if ($candidateCount !== 1 || $exactCount !== 1) {
                $violations[] = "{$key} must contain exactly one exact Phase III architecture status reference marker.";
            }
            if ($targetCount !== 1) {
                $violations[] = "{$key} must link exactly once to the canonical Phase III architecture contract.";
            }
            $referenceCount += $exactCount;
        }

        return [
            'statuses' => $status['statuses'],
            'reference_count' => $referenceCount,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{
     *     marker_candidate_count: int,
     *     valid_marker_count: int,
     *     current_count: int,
     *     historical_count: int,
     *     superseded_count: int,
     *     malformed_marker_count: int,
     *     status_lexical_occurrence_count: int,
     *     status_candidate_count: int,
     *     current_status_declaration_count: int,
     *     historical_status_declaration_count: int,
     *     unclassified_status_declaration_count: int,
     *     malformed_status_candidate_count: int,
     *     heading_candidate_count: int,
     *     unclassified_heading_count: int,
     *     violations: array<int, string>
     * }
     */
    private function phaseThreeArchitectureAuthorityContract(string $design): array
    {
        $expectedCurrentId = 'phase-iii-architecture-contract-v1';
        $blocks = $this->phaseThreeArchitectureStructuralBlocks($design);
        $markerCandidates = [];
        $markers = [];
        $statusCandidates = [];
        $statusLexicalOccurrenceCount = 0;
        $headingCandidates = [];
        $violations = [];

        foreach ($blocks as $blockIndex => $block) {
            if ($block['literal']) {
                continue;
            }

            $normalized = $this->phaseThreeArchitectureNormalizedTokens($block['raw']);
            $isExactMarker = preg_match(
                '/^<!-- phase-iii-architecture-authority classification=(?<classification>CURRENT|HISTORICAL|SUPERSEDED) id=(?<id>[a-z0-9-]+) -->$/',
                trim($block['raw']),
                $match,
            ) === 1;
            $hasMarkerIntent = preg_match(
                '/(?<![a-z0-9])phase[\s_-]*(?:iii|3)[\s_-]*architectur(?:e|al)(?:[\s_-]+status)?[\s_-]+authority(?![a-z0-9])/i',
                $block['raw'],
            ) === 1;
            if ($isExactMarker || $hasMarkerIntent || preg_match('/\bphase iii architecture(?: \w+){0,2} authority\b/', $normalized) === 1) {
                $markerCandidates[] = ['block' => $blockIndex, 'raw' => $block['raw']];
                if (! $isExactMarker) {
                    $violations[] = 'Malformed Phase III architecture authority marker candidate in structural block '.($blockIndex + 1).'.';
                } else {
                    $markers[] = [
                        'classification' => $match['classification'],
                        'id' => $match['id'],
                        'block' => $blockIndex,
                    ];
                }
            }

            $statusLexicalOccurrenceCount += count($this->phaseThreeArchitectureIdentifiers($block['raw']));

            foreach ($this->phaseThreeArchitectureStatusUnits($blocks, $blockIndex) as $unit) {
                $statusIds = $this->phaseThreeArchitectureIdentifiers($unit);
                if ($statusIds === []) {
                    continue;
                }

                $unitTokens = $this->phaseThreeArchitectureNormalizedTokens($unit);
                $unitDiscovery = $this->phaseThreeArchitectureDiscoveryText($unit);
                $status = '(?:closed in design|closed|blocked|unknown|pending)';
                $hasStructuralStatusAssociation = preg_match('/\b'.$status.'\b/', $unitTokens) === 1;
                $hasDeclarationSyntax = preg_match('/\bPH3-RDY-00[1-4]\b\s*(?:=|:|\|)/i', $unitDiscovery) === 1
                    || (stripos($unit, '<td') !== false && $statusIds !== []);

                if ($hasStructuralStatusAssociation || $hasDeclarationSyntax) {
                    $statusCandidates[] = [
                        'block' => $blockIndex,
                        'raw' => $unit,
                        'lexical_ids' => $statusIds,
                    ];
                }
            }

            if ($block['type'] === 'heading'
                && str_contains($normalized, 'phase iii')
                && str_contains($normalized, 'architecture')
                && preg_match('/\b(?:active|current|governing|historical|superseded|status|readiness|authority|authoritative)\b/', $normalized) === 1) {
                $hasCurrentIntent = preg_match('/\b(?:active|current|governing|authoritative)\b/', $normalized) === 1;
                $hasHistoricalIntent = preg_match('/\b(?:historical|superseded)\b/', $normalized) === 1;
                $headingCandidates[] = [
                    'block' => $blockIndex,
                    'intent' => $hasCurrentIntent && $hasHistoricalIntent
                        ? 'CONFLICTING'
                        : ($hasCurrentIntent ? 'CURRENT' : ($hasHistoricalIntent ? 'HISTORICAL' : 'GENERIC')),
                ];
            }
        }

        $ids = array_column($markers, 'id');
        foreach (array_count_values($ids) as $id => $count) {
            if ($count > 1) {
                $violations[] = "Duplicate Phase III architecture authority ID {$id}.";
            }
        }

        $current = array_values(array_filter(
            $markers,
            static fn (array $marker): bool => $marker['classification'] === 'CURRENT',
        ));
        $historical = array_values(array_filter(
            $markers,
            static fn (array $marker): bool => $marker['classification'] === 'HISTORICAL',
        ));
        $superseded = array_values(array_filter(
            $markers,
            static fn (array $marker): bool => $marker['classification'] === 'SUPERSEDED',
        ));
        if (count($current) !== 1) {
            $violations[] = 'Exactly one current Phase III architecture authority marker is required.';
        }

        foreach ($current as $marker) {
            if ($marker['id'] !== $expectedCurrentId) {
                $violations[] = "Unexpected current Phase III architecture authority ID {$marker['id']}.";
            }
        }

        $regions = [];
        foreach ($markers as $marker) {
            $nextCandidateBlock = count($blocks);
            foreach ($markerCandidates as $candidate) {
                if ($candidate['block'] > $marker['block']) {
                    $nextCandidateBlock = $candidate['block'];

                    break;
                }
            }

            $regionEnd = $nextCandidateBlock - 1;
            if ($marker['classification'] === 'CURRENT') {
                $expectedStart = "<!-- phase-iii-architecture-contract:start id={$marker['id']} -->";
                if (trim($blocks[$marker['block'] + 1]['raw'] ?? '') !== $expectedStart) {
                    $violations[] = 'The current Phase III architecture authority must directly own the canonical contract block with the same ID.';

                    continue;
                }

                $expectedEnd = "<!-- phase-iii-architecture-contract:end id={$marker['id']} -->";
                $matchingEndBlocks = [];
                for ($candidateBlock = $marker['block'] + 2; $candidateBlock < $nextCandidateBlock; $candidateBlock++) {
                    if (trim($blocks[$candidateBlock]['raw'] ?? '') === $expectedEnd) {
                        $matchingEndBlocks[] = $candidateBlock;
                    }
                }
                if (count($matchingEndBlocks) !== 1) {
                    $violations[] = 'The current Phase III architecture authority must own exactly one matching contract end marker.';

                    continue;
                }
                $regionEnd = $matchingEndBlocks[0];
            }

            $regions[] = [
                'classification' => $marker['classification'],
                'id' => $marker['id'],
                'start' => $marker['block'],
                'end' => $regionEnd,
            ];
        }

        $regionForBlock = static function (int $blockIndex) use ($regions): array {
            return array_values(array_filter(
                $regions,
                static fn (array $region): bool => $blockIndex >= $region['start'] && $blockIndex <= $region['end'],
            ));
        };

        $unclassifiedHeadingCount = 0;
        foreach ($headingCandidates as $heading) {
            $matchingRegions = $regionForBlock($heading['block']);
            if (count($matchingRegions) !== 1) {
                $unclassifiedHeadingCount++;
                $violations[] = 'Unclassified Phase III architecture authority heading in structural block '.($heading['block'] + 1).'.';

                continue;
            }

            $classification = $matchingRegions[0]['classification'];
            if ($heading['intent'] === 'CONFLICTING'
                || ($heading['intent'] === 'CURRENT' && $classification !== 'CURRENT')
                || ($heading['intent'] === 'HISTORICAL' && ! in_array($classification, ['HISTORICAL', 'SUPERSEDED'], true))) {
                $violations[] = 'Phase III architecture heading intent conflicts with its classified authority region in structural block '.($heading['block'] + 1).'.';
            }
        }

        $currentStatusDeclarationCount = 0;
        $historicalStatusDeclarationCount = 0;
        $unclassifiedStatusDeclarationCount = 0;
        $malformedStatusCandidateCount = 0;
        $regionRows = [];
        foreach ($statusCandidates as $candidate) {
            $matchingRegions = $regionForBlock($candidate['block']);
            if (count($matchingRegions) !== 1) {
                $unclassifiedStatusDeclarationCount++;
                $violations[] = 'Unclassified Phase III architecture status declaration in structural block '.($candidate['block'] + 1).'.';
            } else {
                $classification = $matchingRegions[0]['classification'];
                if ($classification === 'CURRENT') {
                    $currentStatusDeclarationCount++;
                } else {
                    $historicalStatusDeclarationCount++;
                }
            }

            $canonicalIds = array_values(array_filter(
                $candidate['lexical_ids'],
                static fn (string $id): bool => preg_match('/^PH3-RDY-00[1-4]$/', $id) === 1,
            ));
            $isCanonicalRow = preg_match(
                '/^\| `(?<id>PH3-RDY-00[1-4])` \| `(?<status>CLOSED IN DESIGN|CLOSED|BLOCKED)` \| (?<boundary>.+) \|$/',
                trim($candidate['raw']),
                $status,
            ) === 1;
            $isHistorical = count($matchingRegions) === 1
                && in_array($matchingRegions[0]['classification'], ['HISTORICAL', 'SUPERSEDED'], true);
            if (! $isHistorical && (count($canonicalIds) !== count($candidate['lexical_ids']) || ! $isCanonicalRow)) {
                $malformedStatusCandidateCount++;
                $violations[] = 'Malformed Phase III architecture status declaration candidate in structural block '.($candidate['block'] + 1).'.';

                continue;
            }

            if (count($matchingRegions) === 1 && $isCanonicalRow) {
                $regionKey = $matchingRegions[0]['classification'].':'.$matchingRegions[0]['id'];
                $regionRows[$regionKey][] = [
                    'id' => $status['id'],
                    'status' => $status['status'],
                ];
            }
        }

        foreach ($regionRows as $regionKey => $rows) {
            $duplicates = $this->duplicateStructuralKeyViolations(
                $rows,
                'id',
                "Phase III architecture authority region {$regionKey}",
            );
            $violations = [...$violations, ...$duplicates];
        }

        if (count($current) === 1) {
            $currentRegionKey = 'CURRENT:'.$current[0]['id'];
            $currentRows = $regionRows[$currentRegionKey] ?? [];
            $expectedCurrentStatuses = [
                'PH3-RDY-001' => 'CLOSED IN DESIGN',
                'PH3-RDY-002' => 'CLOSED IN DESIGN',
                'PH3-RDY-003' => 'BLOCKED',
                'PH3-RDY-004' => 'CLOSED',
            ];
            $currentStatuses = [];
            foreach ($currentRows as $row) {
                $currentStatuses[$row['id']] = $row['status'];
            }
            ksort($currentStatuses);
            if ($currentStatusDeclarationCount !== count($expectedCurrentStatuses)
                || $currentStatuses !== $expectedCurrentStatuses) {
                $violations[] = 'Current Phase III architecture status declarations do not exactly match the canonical registry.';
            }
        }

        return [
            'marker_candidate_count' => count($markerCandidates),
            'valid_marker_count' => count($markers),
            'current_count' => count($current),
            'historical_count' => count($historical),
            'superseded_count' => count($superseded),
            'malformed_marker_count' => count($markerCandidates) - count($markers),
            'status_lexical_occurrence_count' => $statusLexicalOccurrenceCount,
            'status_candidate_count' => count($statusCandidates),
            'current_status_declaration_count' => $currentStatusDeclarationCount,
            'historical_status_declaration_count' => $historicalStatusDeclarationCount,
            'unclassified_status_declaration_count' => $unclassifiedStatusDeclarationCount,
            'malformed_status_candidate_count' => $malformedStatusCandidateCount,
            'heading_candidate_count' => count($headingCandidates),
            'unclassified_heading_count' => $unclassifiedHeadingCount,
            'status_candidates' => $statusCandidates,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /** @return array<int, array{type: string, raw: string, literal: bool}> */
    private function phaseThreeArchitectureStructuralBlocks(string $document): array
    {
        $lines = preg_split('/\R/', $document) ?: [];
        $blocks = [];
        $buffer = [];
        $bufferType = 'paragraph';
        $inFence = false;
        $fence = '';
        $inExample = false;
        $htmlContainer = null;

        $flush = static function () use (&$blocks, &$buffer, &$bufferType): void {
            if ($buffer === []) {
                return;
            }
            $blocks[] = [
                'type' => $bufferType,
                'raw' => implode("\n", $buffer),
                'literal' => $bufferType === 'literal',
            ];
            $buffer = [];
            $bufferType = 'paragraph';
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($htmlContainer !== null) {
                $buffer[] = $line;
                if (preg_match('/<\/'.preg_quote($htmlContainer, '/').'>/i', $trimmed) === 1) {
                    $htmlContainer = null;
                    $flush();
                }

                continue;
            }

            if ($inFence) {
                $buffer[] = $line;
                if (preg_match('/^'.preg_quote($fence, '/').'\s*$/', $trimmed) === 1) {
                    $inFence = false;
                    $flush();
                }

                continue;
            }

            if ($trimmed === '<!-- phase-iii-architecture-example:start -->') {
                $flush();
                $inExample = true;
                $blocks[] = ['type' => 'marker', 'raw' => $trimmed, 'literal' => true];

                continue;
            }

            if ($trimmed === '<!-- phase-iii-architecture-example:end -->') {
                $flush();
                $blocks[] = ['type' => 'marker', 'raw' => $trimmed, 'literal' => true];
                $inExample = false;

                continue;
            }

            if ($inExample) {
                if ($trimmed === '') {
                    $flush();

                    continue;
                }
                if ($buffer !== [] && $bufferType !== 'literal') {
                    $flush();
                }
                $bufferType = 'literal';
                $buffer[] = $line;

                continue;
            }

            if (preg_match('/^(?<fence>`{3,}|~{3,}).*$/', $trimmed, $match) === 1) {
                $flush();
                $inFence = true;
                $fence = $match['fence'];
                $bufferType = 'literal';
                $buffer[] = $line;

                continue;
            }

            if ($trimmed === '') {
                $flush();

                continue;
            }

            if (preg_match('/^<!--.*-->$/', $trimmed) === 1) {
                $flush();
                $blocks[] = ['type' => 'marker', 'raw' => $trimmed, 'literal' => false];

                continue;
            }

            if (preg_match('/^<(details|table|tr)\b/i', $trimmed, $htmlMatch) === 1) {
                $flush();
                $bufferType = 'html';
                $buffer[] = $line;
                $htmlContainer = strtolower($htmlMatch[1]);
                if (preg_match('/<\/'.preg_quote($htmlContainer, '/').'>/i', $trimmed) === 1) {
                    $htmlContainer = null;
                    $flush();
                }

                continue;
            }

            if (preg_match('/^#{1,6}\s+/', $trimmed) === 1) {
                $flush();
                $blocks[] = ['type' => 'heading', 'raw' => $trimmed, 'literal' => false];

                continue;
            }

            $type = match (true) {
                preg_match('/^\|.*\|$/', $trimmed) === 1 => 'table',
                str_starts_with($trimmed, '>') => 'blockquote',
                preg_match('/^:\s*/', $trimmed) === 1 => 'definition',
                default => 'paragraph',
            };
            $startsListItem = preg_match('/^(?:[-*+] |\d+[.)] )/', $trimmed) === 1;
            if ($buffer !== [] && ($type !== $bufferType || ($startsListItem && $bufferType === 'paragraph'))) {
                $flush();
            }
            if ($buffer === []) {
                $bufferType = $type;
            }
            $buffer[] = $line;
        }

        $flush();

        return $blocks;
    }

    private function phaseThreeArchitectureDiscoveryText(string $value): string
    {
        if (str_contains($value, '<!--') && ! str_contains($value, '-->')) {
            $value = str_replace('<!--', ' ', $value);
        } else {
            $value = preg_replace('/<!--.*?-->/s', '', $value) ?? $value;
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace([
            "\u{00AD}", "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2043}", "\u{2212}", "\u{FE63}", "\u{FF0D}",
        ], '-', $value);
        $value = str_replace(["\u{00A0}", "\u{202F}", "\t"], ' ', $value);
        $value = preg_replace('/\[([^\]]+)\]\([^)]+\)/s', '$1', $value) ?? $value;
        $value = preg_replace('/<[^>]+>/', ' ', $value) ?? strip_tags($value);
        $value = preg_replace('/(?<!\\\\)[*_~`]+/u', '', $value) ?? $value;
        $value = preg_replace('/\bPH3\s*-\s*RDY\s*-\s*00([1-4])\b/iu', 'PH3-RDY-00$1', $value) ?? $value;

        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value);
    }

    /** @return array<int, string> */
    private function phaseThreeArchitectureIdentifiers(string $value): array
    {
        $discovery = $this->phaseThreeArchitectureDiscoveryText($value);
        $count = preg_match_all('/\bPH3-RDY-00[1-4]\b/i', $discovery, $matches);

        return is_int($count) && $count > 0 ? $matches[0] : [];
    }

    private function phaseThreeArchitectureNormalizedTokens(string $value): string
    {
        $value = $this->phaseThreeArchitectureDiscoveryText($value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\bphase\s+3\b/', 'phase iii', $value) ?? $value;
        $value = preg_replace('/\barchitectural\b/', 'architecture', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @param  array<int, array{type: string, raw: string, literal: bool}>  $blocks
     * @return array<int, string>
     */
    private function phaseThreeArchitectureStatusUnits(array $blocks, int $blockIndex): array
    {
        $block = $blocks[$blockIndex];
        if ($block['literal'] || in_array($block['type'], ['heading', 'marker'], true)) {
            if ($block['type'] !== 'heading' || $block['literal']) {
                return [];
            }
        }

        if ($block['type'] === 'table') {
            return array_values(array_filter(
                preg_split('/\R/', $block['raw']) ?: [],
                fn (string $line): bool => $this->phaseThreeArchitectureIdentifiers($line) !== [],
            ));
        }

        $units = [$block['raw']];
        $next = $blocks[$blockIndex + 1] ?? null;
        if ($next !== null && ! $next['literal'] && $next['type'] !== 'marker') {
            $blockHasIdentifier = $this->phaseThreeArchitectureIdentifiers($block['raw']) !== [];
            $nextHasIdentifier = $this->phaseThreeArchitectureIdentifiers($next['raw']) !== [];
            $blockIsStatus = $this->phaseThreeArchitectureIsStatusFragment($block['raw']);
            $nextIsStatus = $this->phaseThreeArchitectureIsStatusFragment($next['raw']);
            if (($blockHasIdentifier && $nextIsStatus) || ($blockIsStatus && $nextHasIdentifier)) {
                $units[] = $block['raw']."\n".$next['raw'];
            }
        }

        $afterNext = $blocks[$blockIndex + 2] ?? null;
        if ($next !== null
            && $afterNext !== null
            && ! $next['literal']
            && ! $afterNext['literal']
            && $next['type'] !== 'marker'
            && $afterNext['type'] !== 'marker'
            && $this->phaseThreeArchitectureIsDeclarationBridge($next['raw'])) {
            $blockHasIdentifier = $this->phaseThreeArchitectureIdentifiers($block['raw']) !== [];
            $afterNextHasIdentifier = $this->phaseThreeArchitectureIdentifiers($afterNext['raw']) !== [];
            $blockIsStatus = $this->phaseThreeArchitectureIsStatusFragment($block['raw']);
            $afterNextIsStatus = $this->phaseThreeArchitectureIsStatusFragment($afterNext['raw']);
            if (($blockHasIdentifier && $afterNextIsStatus) || ($blockIsStatus && $afterNextHasIdentifier)) {
                $units[] = $block['raw']."\n".$next['raw']."\n".$afterNext['raw'];
            }
        }

        return array_values(array_unique($units));
    }

    private function phaseThreeArchitectureIsDeclarationBridge(string $value): bool
    {
        return in_array(
            $this->phaseThreeArchitectureNormalizedTokens($value),
            ['', 'status'],
            true,
        ) && preg_match('/(?:^|\s)(?:=|:)(?:\s|$)/', $this->phaseThreeArchitectureDiscoveryText($value)) === 1;
    }

    private function phaseThreeArchitectureIsStatusFragment(string $value): bool
    {
        return in_array(
            $this->phaseThreeArchitectureNormalizedTokens($value),
            ['closed in design', 'closed', 'blocked', 'unknown', 'pending'],
            true,
        );
    }

    /**
     * @return array{
     *     body: string,
     *     full_block: string,
     *     marker_candidate_count: int,
     *     valid_block_count: int,
     *     violations: array<int, string>
     * }
     */
    private function phaseThreeArchitectureContract(string $design): array
    {
        $expectedId = 'phase-iii-architecture-contract-v1';
        $lexicalIntent = 'phase-iii-architecture-contract:';
        $heading = '### Phase III provenance and bounds architecture decision';
        $headingIntent = 'Phase III provenance and bounds architecture decision';
        $lines = preg_split('/\R/', $design) ?: [];
        $lineEnding = str_contains($design, "\r\n") ? "\r\n" : "\n";
        $markerCandidates = 0;
        $markers = [];
        $headingCandidates = [];
        $violations = [];

        foreach ($lines as $lineNumber => $line) {
            if (str_contains($line, $headingIntent)) {
                $headingCandidates[] = $lineNumber;
                if ($line !== $heading) {
                    $violations[] = 'Malformed Phase III architecture heading candidate at line '.($lineNumber + 1).'.';
                }
            }
            if (! str_contains($line, $lexicalIntent)) {
                continue;
            }

            $markerCandidates++;
            if (preg_match(
                '/^<!-- phase-iii-architecture-contract:(?<type>start|status|end) id=(?<id>[a-z0-9-]+) -->$/',
                $line,
                $match,
            ) !== 1) {
                $violations[] = 'Malformed Phase III architecture marker candidate at line '.($lineNumber + 1).'.';

                continue;
            }

            $markers[] = [
                'type' => $match['type'],
                'id' => $match['id'],
                'line' => $lineNumber,
            ];
        }

        if ($markerCandidates !== 3) {
            $violations[] = 'Exactly three lexical Phase III architecture marker candidates are required.';
        }
        if (count($headingCandidates) !== 1) {
            $violations[] = 'Exactly one lexical Phase III architecture heading candidate is required.';
        }

        $byType = ['start' => [], 'status' => [], 'end' => []];
        foreach ($markers as $marker) {
            $byType[$marker['type']][] = $marker;
            if ($marker['id'] !== $expectedId) {
                $violations[] = "Unexpected Phase III architecture contract ID {$marker['id']}.";
            }
        }
        foreach ($byType as $type => $typedMarkers) {
            if (count($typedMarkers) !== 1) {
                $violations[] = "Exactly one valid Phase III architecture {$type} marker is required.";
            }
        }

        $body = '';
        $fullBlock = '';
        $validBlockCount = 0;
        if (count($byType['start']) === 1 && count($byType['status']) === 1 && count($byType['end']) === 1) {
            $start = $byType['start'][0]['line'];
            $status = $byType['status'][0]['line'];
            $end = $byType['end'][0]['line'];
            if (! ($start < $status && $status < $end)) {
                $violations[] = 'Phase III architecture markers must be ordered start, status, end.';
            } elseif (count($headingCandidates) !== 1 || $headingCandidates[0] !== $start + 1) {
                $violations[] = 'The sole Phase III architecture heading must immediately follow its start marker.';
            } else {
                $body = implode($lineEnding, array_slice($lines, $start + 1, $end - $start - 1));
                $fullBlock = implode($lineEnding, array_slice($lines, $start, $end - $start + 1));
                $validBlockCount = 1;
            }
        }

        return [
            'body' => $body,
            'full_block' => $fullBlock,
            'marker_candidate_count' => $markerCandidates,
            'valid_block_count' => $validBlockCount,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{fields: array<int, string>, declaration_count: int, valid_block_count: int, violations: array<int, string>}
     */
    private function phaseThreeOrderedFieldContract(
        string $architecture,
        string $declaration,
        string $context,
    ): array {
        $lexicalIntent = rtrim($declaration, ':');
        $declarationCount = 0;
        $blocks = [];
        $violations = [];
        $lines = preg_split('/\R/', $architecture) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (! str_contains($line, $lexicalIntent)) {
                continue;
            }

            $declarationCount++;
            if ($line !== $declaration) {
                $violations[] = "Malformed {$context} declaration at line ".($lineNumber + 1).'.';

                continue;
            }

            $block = $this->lineSeparatedFencedBlock($lines, $lineNumber, $context);
            $violations = [...$violations, ...$block['violations']];
            if ($block['fields'] !== null) {
                $blocks[] = $block['fields'];
            }
        }

        if ($declarationCount !== 1) {
            $violations[] = "Exactly one {$context} declaration is required.";
        }
        if (count($blocks) !== 1) {
            $violations[] = "Exactly one valid {$context} block is required.";
        }

        return [
            'fields' => count($blocks) === 1 ? $blocks[0] : [],
            'declaration_count' => $declarationCount,
            'valid_block_count' => count($blocks),
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{statuses: array<string, string>, violations: array<int, string>}
     */
    private function phaseThreeArchitectureStatusContract(string $architecture): array
    {
        $table = $this->structuralMarkdownTable(
            $architecture,
            '| Finding | Architecture status | Exact boundary |',
            '| :--- | :--- | --- |',
            'Phase III architecture status',
            3,
            'Exactly one architecture blocker remains: `PH3-RDY-003`, solely for approved',
        );
        $violations = $table['violations'];
        $rows = [];
        foreach ($table['rows'] as $position => $row) {
            $parsed = $this->structuralMarkdownRowCells(
                $row,
                3,
                'Phase III architecture status',
                $position + 1,
            );
            $violations = [...$violations, ...$parsed['violations']];
            if ($parsed['cells'] === null) {
                continue;
            }

            [$idCell, $statusCell, $boundary] = $parsed['cells'];
            if (preg_match('/^`(?<id>PH3-RDY-[0-9]{3})`$/', $idCell, $id) !== 1
                || preg_match('/^`(?<status>[^`]+)`$/', $statusCell, $status) !== 1
                || $boundary === '') {
                $violations[] = 'Malformed Phase III architecture status row '.($position + 1).'.';

                continue;
            }
            $rows[] = ['id' => $id['id'], 'status' => $status['status']];
        }

        $expected = [
            'PH3-RDY-001' => 'CLOSED IN DESIGN',
            'PH3-RDY-002' => 'CLOSED IN DESIGN',
            'PH3-RDY-003' => 'BLOCKED',
            'PH3-RDY-004' => 'CLOSED',
        ];
        $violations = [
            ...$violations,
            ...$this->duplicateStructuralKeyViolations($rows, 'id', 'Phase III architecture status'),
        ];
        if ($table['physical_count'] !== count($expected) || count($rows) !== count($expected)) {
            $violations[] = 'Phase III architecture status must contain exactly four parsed declarations.';
        }

        $statuses = [];
        if ($violations === []) {
            foreach ($rows as $row) {
                $statuses[$row['id']] = $row['status'];
            }
            ksort($statuses);
            if ($statuses !== $expected) {
                $violations[] = 'Phase III architecture statuses do not match the canonical registry.';
            }
        }

        return [
            'statuses' => $statuses,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{full_block: string, violations: array<int, string>}
     */
    private function phaseThreeArchitectureSemanticContract(string $design): array
    {
        $authority = $this->phaseThreeArchitectureAuthorityContract($design);
        $contract = $this->phaseThreeArchitectureContract($design);
        $violations = [...$authority['violations'], ...$contract['violations']];
        $architecture = $contract['body'];
        if ($architecture === '') {
            return ['full_block' => $contract['full_block'], 'violations' => array_values(array_unique($violations))];
        }

        $fieldContracts = [
            [
                'Canonical source-profile descriptor fields (ordered):',
                'Canonical source-profile descriptor fields',
                ['schema', 'supplier_id', 'supplier_feed_id', 'source_locator_contract_key', 'source_locator_contract_version', 'source_locator_key', 'source_access_scope_key', 'feed_type', 'importer_key', 'importer_version', 'mapping_contract_version', 'mapping_contract_fingerprint'],
            ],
            [
                'Canonical non-secret source-locator fields (ordered):',
                'Canonical non-secret source-locator fields',
                ['schema', 'source_locator_contract_key', 'source_locator_contract_version', 'scheme', 'ascii_host', 'port', 'path_components', 'query_components'],
            ],
            [
                'Canonical resolved source context fields (ordered):',
                'Canonical resolved source context fields',
                ['schema', 'source_profile_id', 'source_identity', 'source_descriptor_version', 'source_descriptor_fingerprint', 'supplier_id', 'supplier_feed_id', 'source_locator_contract_key', 'source_locator_contract_version', 'source_locator_key', 'source_locator_canonical_bytes', 'source_access_scope_key', 'feed_type', 'importer_key', 'importer_version', 'mapping_contract_version', 'mapping_canonical_bytes', 'mapping_contract_fingerprint'],
            ],
            [
                'Canonical ImportJob identity fields (ordered):',
                'Canonical ImportJob identity fields',
                ['schema', 'import_job_id', 'supplier_id', 'supplier_feed_id', 'xml_mapping_template_id', 'import_type'],
            ],
            [
                'Canonical source-execution fingerprint fields (ordered):',
                'Canonical source-execution fingerprint fields',
                ['schema', 'supplier_id', 'supplier_feed_id', 'import_job_id', 'import_history_id', 'supplier_import_source_profile_id', 'source_identity', 'source_descriptor_version', 'source_descriptor_fingerprint', 'import_job_identity_version', 'import_job_identity_fingerprint', 'resolved_source_context_version', 'source_locator_contract_key', 'source_locator_contract_version', 'source_locator_key', 'source_access_scope_key', 'feed_type', 'importer_key', 'importer_version', 'mapping_contract_version', 'mapping_contract_fingerprint', 'captured_at'],
            ],
            [
                'Canonical source-payload receipt fingerprint fields (ordered):',
                'Canonical source-payload receipt fingerprint fields',
                ['schema', 'supplier_import_source_execution_id', 'source_execution_fingerprint', 'accepted_payload_bytes', 'accepted_payload_sha256'],
            ],
            [
                'Bounded immutable source payload fields (ordered):',
                'Bounded immutable source payload fields',
                ['supplier_import_source_execution_id', 'source_execution_fingerprint', 'source_payload_receipt_id', 'receipt_version', 'accepted_payload_bytes', 'accepted_payload_sha256', 'payload_receipt_fingerprint', 'payload_storage_kind', 'payload_file_identity', 'payload_lifecycle_state', 'authoritative_read_handle'],
            ],
            [
                'Canonical supplier-product logical-head key fields (ordered):',
                'Canonical supplier-product logical-head key fields',
                ['supplier_id', 'supplier_feed_id', 'supplier_sku_bytes'],
            ],
            [
                'Canonical finalization fixed row mutations (ordered):',
                'Canonical finalization fixed row mutations',
                ['supplier_offer_snapshot_generation_insert', 'import_history_terminal_update', 'import_job_terminal_update', 'supplier_feed_terminal_update', 'supplier_import_execution_claim_terminal_update', 'supplier_import_run_terminal_update_when_orchestrated'],
            ],
        ];
        foreach ($fieldContracts as [$declaration, $context, $expected]) {
            $fields = $this->phaseThreeOrderedFieldContract($architecture, $declaration, $context);
            $violations = [...$violations, ...$fields['violations']];
            if ($fields['fields'] !== $expected) {
                $violations[] = "{$context} do not match the canonical ordered registry.";
            }
        }

        $semanticArchitecture = preg_replace('/\s+/', ' ', $architecture) ?? $architecture;
        foreach ([
            'The sole future registry is `supplier_import_source_profiles`.',
            'supplier_import_resolved_source_context_v1',
            'source_locator_canonical_bytes',
            'mapping_canonical_bytes',
            'The canonical source-resolution consistency boundary is one MySQL',
            'The sole future immutable selector snapshot is',
            'supplier_import_job_identity_v1',
            'locks the exact ImportJob row with `SELECT ... FOR UPDATE`',
            'single deterministic cross-table lock order is `import_jobs` by primary key, then `supplier_feeds` by',
            'then, only for XML, `xml_mapping_templates` by primary key.',
            'The transaction performs exactly this sequence:',
            'read every source-defining feed and effective mapping value from the locked rows without a later selector reread;',
            'cannot produce job identity T1 with parser mapping T2.',
            'The canonical mapping bytes contain every effective parser instruction.',
            'downloadSource(ResolvedSupplierImportSourceContext)',
            'MUST NOT read',
            'source_execution_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin',
            'cannot reuse A\'s globally unique `source_identity`',
            'source_profile_descriptor_fingerprint_collision',
            'source_execution_fingerprint_collision',
            'The sole first-insert coordination authority is the append-only',
            'supplier_sku_bytes VARBINARY(1020)',
            'supplier_product_staging_projection_v1:'."\n".
                'schema, supplier_id, supplier_feed_id, supplier_sku_bytes,',
            'supplier_product_source_revision_v1:'."\n".
                'schema, supplier_product_identity_head_id, supplier_product_id,'."\n".
                'supplier_import_source_execution_id, supplier_id, supplier_feed_id,'."\n".
                'supplier_sku_bytes, source_identity, source_descriptor_fingerprint,',
            '`VARBINARY` makes uniqueness byte-exact',
            'rather than a gap or nonexistent SupplierProduct row lock',
            'legacy_supplier_product_identity_ambiguous',
            'Migration must not infer provenance from current feed URL/type/mapping',
            'The selected protected redirect policy is exactly',
            '`protected_source_redirect_policy_v1`: HTTP redirect following is disabled',
            'fails closed as `source_redirect_rejected`',
            'SSRF acceptance of a redirect target is never provenance equivalence and never authorizes contact on the protected path.',
            'A same-host, cross-host, same-scope or cross-scope redirect is rejected identically; no redirect target can become source B implicitly.',
            'To move from canonical locator A to B, mutable feed configuration must be changed and a later normal locking source-resolution transaction must create or resolve profile/context/execution B under the existing A-to-B rules.',
            'Retry of execution EA reconstructs immutable context A and repeats only locator A with redirect following still disabled.',
            'because the downloader cannot consume a redirected locator, EA cannot attest A while parsing bytes obtained from B.',
            'The persistence authority is one dedicated append-only table,',
            '`supplier_import_source_payload_receipts`',
            '`NO_RECEIPT -> RECEIPT_COMMITTED` once',
            'A-to-B replacement, clearing, partial digest/size visibility, UPDATE and DELETE are forbidden.',
            '`accepted_payload_sha256` is SHA-256 over the exact decoded bytes',
            '`private_open_regular_file_v1`',
            'The identity is attempt-local security metadata, not persisted receipt identity and not a pathname.',
            'No protected parser/service may reopen payload contents by pathname.',
            'The parser receives only the resolved context plus that already-open payload object.',
            'A verification wrapper reads from the same handle, recomputes byte count',
            'Early parser success before verified EOF is forbidden.',
            'Mode 0600 alone is never treated as immutability proof; digest verification is',
            'no leftover pathname is trusted',
            'Retry reconstructs A without rereading current ImportJob/feed/template selectors,',
            '`K` always means records per',
            'T >= C + 6 for the policy worst case',
            'rows/transaction, never statements, bytes or time',
            'overflow never opens or commits a partial transaction',
        ] as $authority) {
            $semanticAuthority = preg_replace('/\s+/', ' ', $authority) ?? $authority;
            if (! str_contains($semanticArchitecture, $semanticAuthority)) {
                $violations[] = "Missing Phase III architecture authority: {$authority}.";
            }
        }
        foreach (['maximum records and their encoded bytes', 'T >= 1 + C + H', '`H`', 'Redirects are independently SSRF-revalidated'] as $forbidden) {
            if (str_contains($architecture, $forbidden)) {
                $violations[] = "Forbidden ambiguous Phase III architecture declaration: {$forbidden}.";
            }
        }
        foreach ([
            'approved row and byte memory ceilings',
            'approved canonical child-row and encoded-byte ceilings',
            '| external-sort chunk |',
            '| immutable DB insert batch |',
            '| snapshot transaction bound |',
        ] as $staleGlobalDeclaration) {
            if (str_contains($design, $staleGlobalDeclaration)) {
                $violations[] = "Stale Phase III declaration exists outside the canonical block: {$staleGlobalDeclaration}.";
            }
        }

        $bounds = $this->structuralMarkdownTable(
            $architecture,
            '| Bound | Exact semantic, unit and scope | Enforcement and failure | Value/status |',
            '| :--- | --- | --- | ---: |',
            'Phase III operational bounds',
            4,
            'All are hard, application-owned, supplier-invariant limits.',
        );
        $violations = [...$violations, ...$bounds['violations']];
        $expectedBounds = ['`max_source_rows`', '`max_source_bytes`', '`max_spool_rows`', '`max_spool_bytes`', '`max_enrollments`', '`max_observations`', '`max_canonical_children`', '`external_sort_chunk`', '`db_insert_batch_ceiling`', '`snapshot_transaction_bound`'];
        $actualBounds = [];
        foreach ($bounds['rows'] as $position => $row) {
            $parsed = $this->structuralMarkdownRowCells($row, 4, 'Phase III operational bounds', $position + 1);
            $violations = [...$violations, ...$parsed['violations']];
            if ($parsed['cells'] === null) {
                continue;
            }
            $actualBounds[] = $parsed['cells'][0];
            if ($parsed['cells'][3] !== '`NOT SPECIFIED`') {
                $violations[] = "Phase III bound {$parsed['cells'][0]} has a numeric or approved value.";
            }
            if ($parsed['cells'][0] === '`external_sort_chunk`'
                && $parsed['cells'][1] !== 'maximum canonical records admitted to one in-memory external-sort run; records/run only') {
                $violations[] = 'external_sort_chunk must have the sole records/run unit.';
            }
            if ($parsed['cells'][0] === '`snapshot_transaction_bound`'
                && $parsed['cells'][1] !== 'total inserted or updated rows in one snapshot finalization transaction; rows/transaction, never statements, bytes or time') {
                $violations[] = 'snapshot_transaction_bound must have the sole row-mutation unit.';
            }
        }
        if ($actualBounds !== $expectedBounds) {
            $violations[] = 'Phase III operational bounds do not match the exact ten-bound registry.';
        }

        $status = $this->phaseThreeArchitectureStatusContract($architecture);
        $violations = [...$violations, ...$status['violations']];
        $exclusiveSemantics = $this->phaseThreeExclusiveSemanticContract($design);
        $violations = [...$violations, ...$exclusiveSemantics['violations']];

        return [
            'full_block' => $contract['full_block'],
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /** @return array<int, string> */
    private function canonicalAuthorizationCompletenessTuple(string $sourceBinding): array
    {
        $contract = $this->canonicalAuthorizationCompletenessTupleContract($sourceBinding);

        $this->assertSame([], $contract['violations'], implode(PHP_EOL, $contract['violations']));

        return $contract['fields'];
    }

    /**
     * @return array{
     *     fields: array<int, string>,
     *     declaration_count: int,
     *     valid_block_count: int,
     *     violations: array<int, string>
     * }
     */
    private function canonicalAuthorizationCompletenessTupleContract(string $sourceBinding): array
    {
        $declaration = 'Canonical proposed future authorization completeness tuple (ordered):';
        $lexicalIntent = 'Canonical proposed future authorization completeness tuple';
        $blocks = [];
        $declarationCount = 0;
        $violations = [];
        $lines = preg_split('/\R/', $sourceBinding) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (! str_contains($line, $lexicalIntent)) {
                continue;
            }

            $declarationCount++;
            if ($line !== $declaration) {
                $violations[] = 'Malformed canonical authorization tuple declaration at line '.($lineNumber + 1).'.';

                continue;
            }

            $block = $this->lineSeparatedFencedBlock(
                $lines,
                $lineNumber,
                'canonical authorization tuple',
            );
            $violations = [...$violations, ...$block['violations']];
            if ($block['fields'] !== null) {
                $blocks[] = $block['fields'];
            }
        }

        if ($declarationCount !== 1) {
            $violations[] = 'Exactly one canonical authorization tuple declaration is required.';
        }
        if (count($blocks) !== 1) {
            $violations[] = 'Exactly one valid canonical authorization tuple block is required.';
        }

        return [
            'fields' => count($blocks) === 1 ? $blocks[0] : [],
            'declaration_count' => $declarationCount,
            'valid_block_count' => count($blocks),
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @param  array<int, string>  $expectedTuple
     * @return array{
     *     registry_ids: array<int, string>,
     *     registry_declaration_count: int,
     *     registry_valid_block_count: int,
     *     marker_candidate_count: int,
     *     declaration_candidate_count: int,
     *     violations: array<int, string>
     * }
     */
    private function authorizationProcedureContract(string $design, array $expectedTuple): array
    {
        $registry = $this->authorizationProcedureRegistryContract($design);
        $registryIds = $registry['ids'];
        $structure = $this->authorizationProcedureStructure($design);
        $startIds = $structure['start_ids'];
        $endIds = $structure['end_ids'];
        $declarationIds = $structure['declaration_ids'];
        $blockRows = $this->authorizationProcedureBlockRows($design);
        $blockIds = array_column($blockRows, 'id');
        $violations = [...$registry['violations'], ...$structure['violations']];

        if ($registryIds === []) {
            $violations[] = 'Normative authorization procedure registry is missing or empty.';
        }

        foreach ([
            'registry' => $registryIds,
            'start markers' => $startIds,
            'end markers' => $endIds,
            'declarations' => $declarationIds,
            'bounded blocks' => $blockIds,
        ] as $source => $ids) {
            if (count($ids) !== count(array_unique($ids))) {
                $violations[] = "Duplicate normative authorization procedure ID in {$source}.";
            }
        }

        $blocks = [];
        if (count($blockIds) === count(array_unique($blockIds))) {
            foreach ($blockRows as $blockRow) {
                $blocks[$blockRow['id']] = $blockRow['body'];
            }
        }

        foreach ([
            'start markers' => $startIds,
            'end markers' => $endIds,
            'declarations' => $declarationIds,
            'bounded blocks' => $blockIds,
        ] as $source => $ids) {
            if ($registryIds !== $ids) {
                $violations[] = "Registry IDs do not exactly match {$source}.";
            }
        }

        foreach ($registryIds as $procedureId) {
            if (! isset($blocks[$procedureId])) {
                continue;
            }

            $block = $blocks[$procedureId];
            $tuple = $this->authorizationProcedureTuple($block);
            if ($tuple !== $expectedTuple) {
                $violations[] = "Authorization procedure {$procedureId} does not use the exact ordered tuple.";
            }

            $normalized = preg_replace('/\s+/', ' ', $block);
            if (! is_string($normalized)) {
                $violations[] = "Authorization procedure {$procedureId} cannot be normalized.";

                continue;
            }

            if (! str_contains(
                $normalized,
                'Atomic authorization transaction: authorization members + exact five-field tuple + `cohort_source_identity` `NULL -> A`.',
            )) {
                $violations[] = "Authorization procedure {$procedureId} lacks atomic member/source binding.";
            }

            if (! str_contains(
                $normalized,
                'Retry/recovery source authority: persisted `cohort_source_identity` only; mutable current SupplierFeed configuration is prohibited.',
            )) {
                $violations[] = "Authorization procedure {$procedureId} lacks persisted-source retry/recovery authority.";
            }
        }

        return [
            'registry_ids' => $registryIds,
            'registry_declaration_count' => $registry['declaration_count'],
            'registry_valid_block_count' => $registry['valid_block_count'],
            'marker_candidate_count' => $structure['marker_candidate_count'],
            'declaration_candidate_count' => $structure['declaration_candidate_count'],
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{
     *     ids: array<int, string>,
     *     declaration_count: int,
     *     valid_block_count: int,
     *     violations: array<int, string>
     * }
     */
    private function authorizationProcedureRegistryContract(string $design): array
    {
        $heading = '### Normative authorization procedure registry';
        $declaration = 'Normative authorization procedure registry (ordered):';
        $lexicalIntent = 'Normative authorization procedure registry';
        $blocks = [];
        $declarationCount = 0;
        $violations = [];
        $lines = preg_split('/\R/', $design) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (! str_contains($line, $lexicalIntent) || $line === $heading) {
                continue;
            }

            $declarationCount++;
            if ($line !== $declaration) {
                $violations[] = 'Malformed normative authorization procedure registry declaration at line '.($lineNumber + 1).'.';

                continue;
            }

            $block = $this->lineSeparatedFencedBlock(
                $lines,
                $lineNumber,
                'normative authorization procedure registry',
            );
            $violations = [...$violations, ...$block['violations']];
            if ($block['fields'] !== null) {
                $blocks[] = $block['fields'];
            }
        }

        if ($declarationCount !== 1) {
            $violations[] = 'Exactly one normative authorization procedure registry declaration is required.';
        }
        if (count($blocks) !== 1) {
            $violations[] = 'Exactly one valid normative authorization procedure registry block is required.';
        }
        if (count($blocks) === 1 && $blocks[0] === []) {
            $violations[] = 'The normative authorization procedure registry may not be empty.';
        }

        return [
            'ids' => count($blocks) === 1 ? $blocks[0] : [],
            'declaration_count' => $declarationCount,
            'valid_block_count' => count($blocks),
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @return array{
     *     start_ids: array<int, string>,
     *     end_ids: array<int, string>,
     *     declaration_ids: array<int, string>,
     *     marker_candidate_count: int,
     *     declaration_candidate_count: int,
     *     violations: array<int, string>
     * }
     */
    private function authorizationProcedureStructure(string $design): array
    {
        $startIds = [];
        $endIds = [];
        $declarationIds = [];
        $markerCandidateCount = 0;
        $declarationCandidateCount = 0;
        $violations = [];

        foreach (preg_split('/\R/', $design) ?: [] as $lineNumber => $line) {
            if (str_contains($line, 'normative-authorization-procedure')) {
                $markerCandidateCount++;

                if (preg_match(
                    '/^<!-- normative-authorization-procedure:(?<marker>start|end) id=(?<id>[a-z0-9-]+) -->$/',
                    $line,
                    $marker,
                ) !== 1) {
                    $violations[] = 'Malformed normative authorization procedure marker at line '.($lineNumber + 1).'.';

                    continue;
                }

                if ($marker['marker'] === 'start') {
                    $startIds[] = $marker['id'];
                } else {
                    $endIds[] = $marker['id'];
                }
            }

            if (! str_contains($line, 'Normative authorization procedure')) {
                continue;
            }
            if (in_array($line, [
                '### Normative authorization procedure registry',
                'Normative authorization procedure registry (ordered):',
            ], true)) {
                continue;
            }

            $declarationCandidateCount++;
            if (preg_match(
                '/^Normative authorization procedure `(?<id>[a-z0-9-]+)`$/',
                $line,
                $declaration,
            ) !== 1) {
                $violations[] = 'Malformed normative authorization procedure declaration at line '.($lineNumber + 1).'.';

                continue;
            }

            $declarationIds[] = $declaration['id'];
        }

        return [
            'start_ids' => $startIds,
            'end_ids' => $endIds,
            'declaration_ids' => $declarationIds,
            'marker_candidate_count' => $markerCandidateCount,
            'declaration_candidate_count' => $declarationCandidateCount,
            'violations' => $violations,
        ];
    }

    /** @return array<int, array{id: string, body: string}> */
    private function authorizationProcedureBlockRows(string $design): array
    {
        preg_match_all(
            '/^<!-- normative-authorization-procedure:start id=(?<id>[a-z0-9-]+) -->\R'.
            '(?<body>.*?)\R'.
            '<!-- normative-authorization-procedure:end id=\k<id> -->$/ms',
            $design,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [
                'id' => $match['id'],
                'body' => $match['body'],
            ],
            $matches,
        );
    }

    /** @return array<int, string> */
    private function authorizationProcedureTuple(string $block): array
    {
        $matched = preg_match(
            '/^Normative authorization procedure `[a-z0-9-]+`\R'.
            'completeness tuple \(ordered\):\R\R```text\R(?<fields>.*?)\R```/s',
            $block,
            $tuple,
        );

        return $matched === 1
            ? $this->lineSeparatedFields($tuple['fields'] ?? '')
            : [];
    }

    /** @return array<int, string> */
    private function expectedAuthorizationTuple(): array
    {
        return [
            'cohort_authorization_version',
            'cohort_authorized_at',
            'cohort_seed_count',
            'cohort_seed_fingerprint',
            'cohort_source_identity',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function watchdogDocumentation(): array
    {
        $root = base_path('docs');
        $documents = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $relativePath = 'docs/'.str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($root) + 1),
            );
            $contents = file_get_contents($file->getPathname());

            if (is_string($contents)) {
                $documents[$relativePath] = $contents;
            }
        }

        ksort($documents);

        return $documents;
    }

    /**
     * @param  array<string, string>  $documents
     * @return array{
     *     violations: array<int, string>,
     *     state: array<string, string>,
     *     contexts: array<string, array{classification: string, column_occurrences: int, index_occurrences: int, contract: string}>,
     *     relevant_documents: array<int, string>
     * }
     */
    private function watchdogDocumentationContract(array $documents, string $migration): array
    {
        $column = 'delivery_watchdog_at';
        $index = 'ix_import_dispatch_outbox_state_watchdog_id';
        $contractId = 'watchdog-current-state-v1';
        $allowedClassifications = [
            'CURRENT_SCHEMA_STATUS',
            'HISTORICAL',
            'FUTURE_RUNTIME_BEHAVIOR',
            'SCHEMA_DEFINITION_REFERENCE',
        ];
        $stateBoundaryContract = $this->watchdogBoundedDeclarations(
            $documents,
            'watchdog-current-state-contract',
            'id',
            'watchdog current-state contract',
        );
        $referenceBoundaryContract = $this->watchdogBoundedDeclarations(
            $documents,
            'watchdog-current-state-reference',
            'contract',
            'watchdog current-state reference',
        );
        $violations = [
            ...$stateBoundaryContract['violations'],
            ...$referenceBoundaryContract['violations'],
        ];
        $relevantDocuments = [];
        $contexts = [];
        $stateContracts = $stateBoundaryContract['declarations'];
        $referenceDeclarations = $referenceBoundaryContract['declarations'];
        $stateReferences = [];

        ksort($documents);

        foreach ($documents as $path => $document) {
            $referenceMatches = array_values(array_filter(
                $referenceDeclarations,
                static fn (array $declaration): bool => $declaration['path'] === $path,
            ));

            $contextContract = $this->watchdogDocumentContextContract($document, $path);
            $contextMatches = $contextContract['contexts'];
            $violations = [...$violations, ...$contextContract['violations']];

            $isRelevant = str_contains($document, $column) || str_contains($document, $index);
            $residualDocument = preg_replace(
                [$this->watchdogStateContractPattern(), $this->watchdogStateReferencePattern()],
                '',
                $document,
            );
            if (! is_string($residualDocument)) {
                $violations[] = "Unable to classify watchdog occurrences in {$path}.";
                $residualDocument = $document;
            }
            $columnOccurrences = substr_count($residualDocument, $column);
            $indexOccurrences = substr_count($residualDocument, $index);

            if (! $isRelevant) {
                if ($contextContract['candidate_count'] !== 0 || $referenceMatches !== []) {
                    $violations[] = "Watchdog context marker in {$path} has no watchdog occurrence.";
                }

                continue;
            }

            $relevantDocuments[] = $path;

            if ($contextContract['candidate_count'] !== 1 || count($contextMatches) !== 1) {
                $violations[] = "{$path} must contain exactly one watchdog document context marker.";

                continue;
            }

            $context = $contextMatches[0];
            $classification = $context['classification'];
            $reportedColumnOccurrences = (int) $context['column'];
            $reportedIndexOccurrences = (int) $context['index'];
            $reportedContract = $context['contract'];

            $contexts[$path] = [
                'classification' => $classification,
                'column_occurrences' => $reportedColumnOccurrences,
                'index_occurrences' => $reportedIndexOccurrences,
                'contract' => $reportedContract,
            ];

            if (! in_array($classification, $allowedClassifications, true)) {
                $violations[] = "{$path} has an unsupported watchdog context classification.";
            }
            if ($reportedContract !== $contractId) {
                $violations[] = "{$path} does not bind its occurrences to {$contractId}.";
            }
            if ($reportedColumnOccurrences !== $columnOccurrences) {
                $violations[] = "{$path} has an unclassified {$column} occurrence.";
            }
            if ($reportedIndexOccurrences !== $indexOccurrences) {
                $violations[] = "{$path} has an unclassified {$index} occurrence.";
            }

            if ($classification === 'CURRENT_SCHEMA_STATUS') {
                if (count($referenceMatches) !== 1) {
                    $violations[] = "{$path} must contain exactly one structural current-state reference.";
                } else {
                    $stateReferences[$path] = [
                        'contract' => $referenceMatches[0]['id'],
                        'body' => $referenceMatches[0]['body'],
                    ];
                }
            } elseif ($referenceMatches !== []) {
                $violations[] = "{$path} may not contain a current-state reference in {$classification} context.";
            }

            foreach ($this->watchdogSecondaryLintViolations($document) as $violation) {
                $violations[] = "{$path}: {$violation}";
            }
        }

        sort($relevantDocuments);
        ksort($contexts);

        $state = [];
        if (count($stateContracts) !== 1) {
            $violations[] = 'Exactly one authoritative watchdog current-state contract is required.';
        } else {
            $stateContract = $stateContracts[0];
            if ($stateContract['path'] !== 'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md') {
                $violations[] = 'The watchdog current-state contract must remain in the persistence design.';
            }
            if ($stateContract['id'] !== $contractId) {
                $violations[] = "The authoritative watchdog contract ID must be {$contractId}.";
            }

            foreach (preg_split('/\R/', $stateContract['body']) ?: [] as $line) {
                if (preg_match('/^(?<key>[a-z_]+)=(?<value>.+)$/', trim($line), $field) !== 1) {
                    $violations[] = 'The watchdog current-state contract contains an invalid field.';

                    continue;
                }

                if (array_key_exists($field['key'], $state)) {
                    $violations[] = "Duplicate watchdog current-state field {$field['key']}.";

                    continue;
                }

                $state[$field['key']] = $field['value'];
            }
        }

        $migrationState = $this->watchdogMigrationState($migration);
        $expectedState = [
            'schema_table' => $migrationState['schema_table'] ?? '',
            'column_name' => $migrationState['column_name'] ?? '',
            'column_state' => 'PRESENT / DEPLOYED',
            'column_type' => $migrationState['column_type'] ?? '',
            'column_precision' => $migrationState['column_precision'] ?? '',
            'column_nullable' => $migrationState['column_nullable'] ?? '',
            'index_name' => $migrationState['index_name'] ?? '',
            'index_state' => 'PRESENT / DEPLOYED',
            'index_ordered_columns' => $migrationState['index_ordered_columns'] ?? '',
            'runtime_state' => 'INACTIVE / UNWIRED',
            'future_work' => 'RUNTIME ENABLEMENT ONLY; NO SCHEMA ADDITION',
        ];

        if (count($migrationState) !== 7) {
            $violations[] = 'Unable to derive the complete watchdog schema from the deployed migration.';
        }
        if ($state !== $expectedState) {
            $violations[] = 'The watchdog current-state declaration does not match repository schema and lifecycle truth.';
        }

        $expectedReference = [
            'classification' => 'CURRENT_SCHEMA_STATUS',
            'column_name' => $expectedState['column_name'],
            'column_state' => $expectedState['column_state'],
            'index_name' => $expectedState['index_name'],
            'index_state' => $expectedState['index_state'],
            'index_ordered_columns' => $expectedState['index_ordered_columns'],
            'runtime_state' => $expectedState['runtime_state'],
            'future_work' => $expectedState['future_work'],
        ];

        foreach ($stateReferences as $path => $reference) {
            if ($reference['contract'] !== $contractId) {
                $violations[] = "{$path} current-state reference has the wrong contract ID.";
            }

            $referenceState = [];
            foreach (preg_split('/\R/', $reference['body']) ?: [] as $line) {
                if (preg_match('/^(?<key>[a-z_]+)=(?<value>.+)$/', trim($line), $field) !== 1) {
                    $violations[] = "{$path} current-state reference contains an invalid field.";

                    continue;
                }

                if (array_key_exists($field['key'], $referenceState)) {
                    $violations[] = "{$path} duplicates current-state field {$field['key']}.";

                    continue;
                }

                $referenceState[$field['key']] = $field['value'];
            }

            if ($referenceState !== $expectedReference) {
                $violations[] = "{$path} current-state reference does not match {$contractId}.";
            }
        }

        foreach ([
            'docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md',
            'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md',
        ] as $requiredCurrentDocument) {
            if (($contexts[$requiredCurrentDocument]['classification'] ?? null) !== 'CURRENT_SCHEMA_STATUS') {
                $violations[] = "{$requiredCurrentDocument} must declare CURRENT_SCHEMA_STATUS.";
            }
        }

        return [
            'violations' => array_values(array_unique($violations)),
            'state' => $state,
            'contexts' => $contexts,
            'relevant_documents' => $relevantDocuments,
        ];
    }

    /**
     * @return array{
     *     contexts: array<int, array{classification: string, column: string, index: string, contract: string}>,
     *     candidate_count: int,
     *     violations: array<int, string>
     * }
     */
    private function watchdogDocumentContextContract(string $document, string $path): array
    {
        $contexts = [];
        $candidateCount = 0;
        $violations = [];

        foreach (preg_split('/\R/', $document) ?: [] as $lineNumber => $line) {
            if (! str_contains($line, 'watchdog-document-context')) {
                continue;
            }

            $candidateCount++;
            if (preg_match(
                '/^<!-- watchdog-document-context classification=(?<classification>[A-Z_]+) '.
                'column_occurrences=(?<column>\d+) index_occurrences=(?<index>\d+) '.
                'contract=(?<contract>[a-z0-9-]+) -->$/',
                $line,
                $context,
            ) !== 1) {
                $violations[] = "Malformed watchdog document context marker in {$path} at line ".($lineNumber + 1).'.';

                continue;
            }

            $contexts[] = [
                'classification' => $context['classification'],
                'column' => $context['column'],
                'index' => $context['index'],
                'contract' => $context['contract'],
            ];
        }

        return [
            'contexts' => $contexts,
            'candidate_count' => $candidateCount,
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string, string>  $documents
     * @return array{
     *     declarations: array<int, array{path: string, id: string, body: string}>,
     *     violations: array<int, string>,
     *     start_count: int,
     *     end_count: int
     * }
     */
    private function watchdogBoundedDeclarations(
        array $documents,
        string $marker,
        string $identityAttribute,
        string $context,
    ): array {
        $rawStarts = [];
        $rawEnds = [];
        $validStarts = [];
        $validEnds = [];
        $documentLines = [];
        $violations = [];

        foreach ($documents as $path => $document) {
            $lines = preg_split('/\R/', $document) ?: [];
            $documentLines[$path] = $lines;

            foreach ($lines as $lineNumber => $line) {
                if (! str_contains($line, $marker)) {
                    continue;
                }

                $boundary = match (true) {
                    str_contains($line, "{$marker}:start") => 'start',
                    str_contains($line, "{$marker}:end") => 'end',
                    default => null,
                };

                if ($boundary === null) {
                    $violations[] = "Malformed {$context} boundary in {$path} at line ".($lineNumber + 1).'.';

                    continue;
                }

                $candidate = [
                    'path' => $path,
                    'line' => $lineNumber,
                ];
                if ($boundary === 'start') {
                    $rawStarts[] = $candidate;
                } else {
                    $rawEnds[] = $candidate;
                }

                $pattern = '/^<!-- '.preg_quote($marker, '/').':'.$boundary.' '.
                    preg_quote($identityAttribute, '/').'=(?<id>[a-z0-9-]+) -->$/';
                if (preg_match($pattern, $line, $match) !== 1) {
                    $violations[] = "Malformed {$context} {$boundary} marker in {$path} at line ".($lineNumber + 1).'.';

                    continue;
                }

                $validCandidate = [
                    ...$candidate,
                    'id' => $match['id'],
                ];
                if ($boundary === 'start') {
                    $validStarts[] = $validCandidate;
                } else {
                    $validEnds[] = $validCandidate;
                }
            }
        }

        $declarations = [];
        $paths = array_values(array_unique(array_map(
            static fn (array $candidate): string => $candidate['path'],
            [...$rawStarts, ...$rawEnds],
        )));

        foreach ($paths as $path) {
            $pathRawStarts = array_values(array_filter(
                $rawStarts,
                static fn (array $candidate): bool => $candidate['path'] === $path,
            ));
            $pathRawEnds = array_values(array_filter(
                $rawEnds,
                static fn (array $candidate): bool => $candidate['path'] === $path,
            ));
            $pathStarts = array_values(array_filter(
                $validStarts,
                static fn (array $candidate): bool => $candidate['path'] === $path,
            ));
            $pathEnds = array_values(array_filter(
                $validEnds,
                static fn (array $candidate): bool => $candidate['path'] === $path,
            ));

            if (count($pathRawStarts) !== count($pathRawEnds)) {
                $violations[] = "{$path} has unbalanced {$context} start/end counts.";
            }
            if (count($pathRawStarts) !== count($pathStarts) || count($pathRawEnds) !== count($pathEnds)) {
                $violations[] = "{$path} contains an invalid {$context} boundary.";
            }

            $startDuplicates = $this->duplicateStructuralKeyViolations($pathStarts, 'id', "{$context} start");
            $endDuplicates = $this->duplicateStructuralKeyViolations($pathEnds, 'id', "{$context} end");
            $violations = [...$violations, ...$startDuplicates, ...$endDuplicates];

            $startIds = array_column($pathStarts, 'id');
            $endIds = array_column($pathEnds, 'id');
            $sortedStartIds = $startIds;
            $sortedEndIds = $endIds;
            sort($sortedStartIds);
            sort($sortedEndIds);
            if ($sortedStartIds !== $sortedEndIds) {
                $violations[] = "{$path} has mismatched {$context} start/end IDs.";
            }

            if ($startDuplicates !== [] || $endDuplicates !== [] || $sortedStartIds !== $sortedEndIds) {
                continue;
            }

            $endsById = [];
            foreach ($pathEnds as $end) {
                $endsById[$end['id']] = $end;
            }

            $intervals = [];
            foreach ($pathStarts as $start) {
                $end = $endsById[$start['id']] ?? null;
                if ($end === null || $end['line'] <= $start['line']) {
                    $violations[] = "{$path} has an invalid {$context} boundary order for {$start['id']}.";

                    continue;
                }

                $intervals[] = [
                    'id' => $start['id'],
                    'start' => $start['line'],
                    'end' => $end['line'],
                ];
            }

            usort($intervals, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
            $previousEnd = -1;
            foreach ($intervals as $interval) {
                if ($interval['start'] <= $previousEnd) {
                    $violations[] = "{$path} contains crossed or nested {$context} boundaries.";

                    continue;
                }
                $previousEnd = $interval['end'];

                $bodyLines = array_slice(
                    $documentLines[$path],
                    $interval['start'] + 1,
                    $interval['end'] - $interval['start'] - 1,
                );
                if (count($bodyLines) < 3 || $bodyLines[0] !== '```text' || end($bodyLines) !== '```') {
                    $violations[] = "{$path} has a malformed {$context} body for {$interval['id']}.";

                    continue;
                }

                $declarations[] = [
                    'path' => $path,
                    'id' => $interval['id'],
                    'body' => implode(PHP_EOL, array_slice($bodyLines, 1, -1)),
                ];
            }
        }

        return [
            'declarations' => $declarations,
            'violations' => array_values(array_unique($violations)),
            'start_count' => count($rawStarts),
            'end_count' => count($rawEnds),
        ];
    }

    private function watchdogStateContractPattern(): string
    {
        return '/^<!-- watchdog-current-state-contract:start id=(?<id>[a-z0-9-]+) -->\R'.
            '```text\R(?<body>.*?)\R```\R'.
            '<!-- watchdog-current-state-contract:end id=\k<id> -->\R?/ms';
    }

    private function watchdogStateReferencePattern(): string
    {
        return '/^<!-- watchdog-current-state-reference:start contract=(?<contract>[a-z0-9-]+) -->\R'.
            '```text\R(?<body>.*?)\R```\R'.
            '<!-- watchdog-current-state-reference:end contract=\k<contract> -->\R?/ms';
    }

    /** @return array<string, string> */
    private function watchdogMigrationState(string $migration): array
    {
        $state = [];

        if (preg_match(
            '/Schema::create\(\'(?<table>supplier_import_dispatch_outbox)\'/',
            $migration,
            $table,
        ) === 1) {
            $state['schema_table'] = $table['table'];
        }

        if (preg_match(
            '/\$table->(?<type>timestamp)\(\'(?<column>delivery_watchdog_at)\',\s*'.
            '(?<precision>\d+)\)(?<nullable>->nullable\(\))?;/',
            $migration,
            $column,
        ) === 1) {
            $state['column_name'] = $column['column'];
            $state['column_type'] = strtoupper($column['type']);
            $state['column_precision'] = $column['precision'];
            $state['column_nullable'] = ($column['nullable'] ?? '') === '->nullable()' ? 'YES' : 'NO';
        }

        if (preg_match(
            '/\$table->index\(\s*\[(?<columns>[^\]]+)\],\s*'.
            '\'(?<name>ix_import_dispatch_outbox_state_watchdog_id)\',?\s*\);/s',
            $migration,
            $index,
        ) === 1) {
            preg_match_all('/\'(?<column>[a-z_]+)\'/', $index['columns'], $columns);
            $state['index_name'] = $index['name'];
            $state['index_ordered_columns'] = implode(',', $columns['column'] ?? []);
        }

        return $state;
    }

    /** @return array<int, string> */
    private function watchdogSecondaryLintViolations(string $document): array
    {
        $violations = [];

        foreach (['delivery_watchdog_at', 'ix_import_dispatch_outbox_state_watchdog_id'] as $artifact) {
            $quotedArtifact = '`?'.preg_quote($artifact, '/').'`?';
            foreach ([
                '/'.$quotedArtifact.'[^.\r\n]{0,120}\b(?:remains?|is)\s+(?:a\s+)?future\s+'.
                    '(?:schema\s+)?(?:addition|column|index)\b/i',
                '/'.$quotedArtifact.'[^.\r\n]{0,120}\bwill\s+be\s+(?:added|created)\s+later\b/i',
            ] as $pattern) {
                if (preg_match($pattern, $document) === 1) {
                    $violations[] = "Deployed watchdog artifact {$artifact} is described as future schema.";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, string>  $documents
     * @return array<string, string>
     */
    private function replaceWatchdogDocumentation(array $documents, string $search, string $replacement): array
    {
        $path = 'docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md';
        $this->assertArrayHasKey($path, $documents);
        $replacementCount = substr_count($documents[$path], $search);

        $this->assertSame(1, $replacementCount, "Expected one watchdog mutation target for {$search}.");
        $documents[$path] = str_replace($search, $replacement, $documents[$path]);

        return $documents;
    }

    private function registerAuthorizationProcedure(string $design, string $procedureId): string
    {
        $updated = preg_replace(
            '/(Normative authorization procedure registry \(ordered\):\R\R```text\R.*?)(\R```)/s',
            '$1'.PHP_EOL.$procedureId.'$2',
            $design,
            1,
            $count,
        );

        $this->assertSame(1, $count, 'Unable to mutate the authorization procedure registry.');
        $this->assertIsString($updated);

        return $updated;
    }

    private function appendRegisteredProcedure(string $design, string $procedureId, string $block): string
    {
        return $this->registerAuthorizationProcedure($design, $procedureId).PHP_EOL.PHP_EOL.$block;
    }

    private function renameRegisteredAuthorizationProcedure(
        string $design,
        string $oldProcedureId,
        string $newProcedureId,
    ): string {
        $updated = preg_replace(
            '/(Normative authorization procedure registry \(ordered\):\R\R```text\R.*?)'.
            preg_quote($oldProcedureId, '/').
            '(\R```|\R)/s',
            '$1'.$newProcedureId.'$2',
            $design,
            1,
            $count,
        );

        $this->assertSame(1, $count, 'Unable to rename the registered authorization procedure.');
        $this->assertIsString($updated);

        return $updated;
    }

    /** @param array<int, string> $fields */
    private function markedAuthorizationProcedureBlock(
        string $procedureId,
        array $fields,
        bool $includeAtomicSourceBinding = true,
    ): string {
        $lines = [
            "<!-- normative-authorization-procedure:start id={$procedureId} -->",
            "Normative authorization procedure `{$procedureId}`",
            'completeness tuple (ordered):',
            '',
            '```text',
            ...$fields,
            '```',
            '',
            $includeAtomicSourceBinding
                ? 'Atomic authorization transaction: authorization members + exact five-field'
                : 'Atomic authorization transaction: authorization members commit before source binding.',
        ];

        if ($includeAtomicSourceBinding) {
            $lines[] = 'tuple + `cohort_source_identity` `NULL -> A`.';
        }

        $lines[] = 'Retry/recovery source authority: persisted `cohort_source_identity` only;';
        $lines[] = 'mutable current SupplierFeed configuration is prohibited.';
        $lines[] = "<!-- normative-authorization-procedure:end id={$procedureId} -->";

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{fields: array<int, string>|null, violations: array<int, string>}
     */
    private function lineSeparatedFencedBlock(array $lines, int $declarationLine, string $context): array
    {
        $violations = [];
        if (($lines[$declarationLine + 1] ?? null) !== ''
            || ($lines[$declarationLine + 2] ?? null) !== '```text') {
            return [
                'fields' => null,
                'violations' => ["The {$context} declaration has a malformed fenced-block opening."],
            ];
        }

        $closingFence = null;
        for ($lineNumber = $declarationLine + 3; $lineNumber < count($lines); $lineNumber++) {
            if ($lines[$lineNumber] === '```') {
                $closingFence = $lineNumber;

                break;
            }
        }

        if ($closingFence === null) {
            return [
                'fields' => null,
                'violations' => ["The {$context} declaration has no closing fence."],
            ];
        }

        $fields = $this->lineSeparatedFields(implode(PHP_EOL, array_slice(
            $lines,
            $declarationLine + 3,
            $closingFence - $declarationLine - 3,
        )));
        if ($fields === []) {
            $violations[] = "The {$context} declaration may not be empty.";
        }

        return [
            'fields' => $fields,
            'violations' => $violations,
        ];
    }

    /** @return array<int, string> */
    private function lineSeparatedFields(string $fields): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $field): string => trim($field),
                preg_split('/\R/', $fields) ?: [],
            ),
            static fn (string $field): bool => $field !== '',
        ));
    }

    /**
     * @return array{
     *     rows: array<int, array{id: string, status: string}>,
     *     statuses: array<string, string>,
     *     violations: array<int, string>,
     *     raw_count: int,
     *     parsed_count: int,
     *     unique_count: int,
     *     expected_count: int
     * }
     */
    private function readinessStatusContract(string $readiness): array
    {
        $table = $this->structuralMarkdownTable(
            $readiness,
            '| Finding | Verdict | Exact boundary |',
            '| --- | --- | --- |',
            'readiness status',
            3,
            'Phase III implementation remains prohibited while the historical',
        );
        $rows = [];
        $violations = $table['violations'];

        foreach ($table['rows'] as $position => $physicalRow) {
            $parsed = $this->structuralMarkdownRowCells(
                $physicalRow,
                3,
                'readiness status',
                $position + 1,
            );
            $violations = [...$violations, ...$parsed['violations']];

            if ($parsed['cells'] === null) {
                continue;
            }

            [$idCell, $statusCell, $boundary] = $parsed['cells'];
            if (preg_match('/^`(?<id>PH3-RDY-[0-9]{3})`$/', $idCell, $idMatch) !== 1) {
                $violations[] = 'Malformed readiness status ID at physical row '.($position + 1).'.';

                continue;
            }
            if (preg_match('/^`(?<status>[^`]*)`$/', $statusCell, $statusMatch) !== 1) {
                $violations[] = "Malformed readiness status value for {$idMatch['id']}.";

                continue;
            }
            if ($boundary === '') {
                $violations[] = "Readiness status {$idMatch['id']} has an empty boundary.";

                continue;
            }

            $rows[] = [
                'id' => $idMatch['id'],
                'status' => $statusMatch['status'],
            ];

            if (! in_array($statusMatch['status'], ['BLOCKED', 'CLOSED'], true)) {
                $violations[] = "Unsupported readiness status {$statusMatch['status']} for {$idMatch['id']}.";
            }
        }
        $expectedStatuses = [
            'PH3-RDY-001' => 'CLOSED',
            'PH3-RDY-002' => 'CLOSED',
            'PH3-RDY-003' => 'BLOCKED',
            'PH3-RDY-004' => 'CLOSED',
        ];
        $ids = array_column($rows, 'id');
        $uniqueIds = array_values(array_unique($ids));
        $duplicateViolations = $this->duplicateStructuralKeyViolations($rows, 'id', 'readiness status');
        $violations = [...$violations, ...$duplicateViolations];
        $expectedIds = array_keys($expectedStatuses);
        $actualIdSet = $uniqueIds;
        sort($actualIdSet);
        $expectedIdSet = $expectedIds;
        sort($expectedIdSet);

        if ($table['physical_count'] !== count($expectedIds)) {
            $violations[] = 'Readiness status raw declaration count does not match the expected registry.';
        }
        if (count($rows) !== $table['physical_count']) {
            $violations[] = 'Every physical readiness declaration must parse successfully.';
        }
        if (count($uniqueIds) !== count($expectedIds)) {
            $violations[] = 'Readiness status unique ID count does not match the expected registry.';
        }
        if ($actualIdSet !== $expectedIdSet) {
            $violations[] = 'Readiness status IDs do not exactly match the expected registry.';
        }

        $statuses = [];
        if ($violations === []) {
            foreach ($rows as $row) {
                $statuses[$row['id']] = $row['status'];
            }
            ksort($statuses);

            if ($statuses !== $expectedStatuses) {
                $violations[] = 'Readiness statuses do not match the expected values.';
            }
        }

        return [
            'rows' => $rows,
            'statuses' => $statuses,
            'violations' => array_values(array_unique($violations)),
            'raw_count' => $table['physical_count'],
            'parsed_count' => count($rows),
            'unique_count' => count($uniqueIds),
            'expected_count' => count($expectedIds),
        ];
    }

    /**
     * @return array{
     *     rows: array<int, array{artifact: string, artifact_status: string, runtime_status: string}>,
     *     artifacts: array<string, array{artifact_status: string, runtime_status: string}>,
     *     violations: array<int, string>,
     *     raw_count: int,
     *     parsed_count: int,
     *     unique_count: int,
     *     expected_count: int
     * }
     */
    private function runtimeInventoryContract(string $inventory): array
    {
        $table = $this->structuralMarkdownTable(
            $inventory,
            '| Artifact | Artifact status | Supplier-runtime status | Repository evidence |',
            '| --- | --- | --- | --- |',
            'runtime inventory',
            4,
            null,
        );
        $rows = [];
        $violations = $table['violations'];

        foreach ($table['rows'] as $position => $physicalRow) {
            $parsed = $this->structuralMarkdownRowCells(
                $physicalRow,
                4,
                'runtime inventory',
                $position + 1,
            );
            $violations = [...$violations, ...$parsed['violations']];

            if ($parsed['cells'] === null) {
                continue;
            }

            [$artifactCell, $artifactStatusCell, $runtimeStatusCell, $evidence] = $parsed['cells'];
            if (preg_match('/^`(?<value>[^`]+)`$/', $artifactCell, $artifactMatch) !== 1) {
                $violations[] = 'Malformed runtime inventory artifact at physical row '.($position + 1).'.';

                continue;
            }
            if (preg_match('/^`(?<value>[^`]*)`$/', $artifactStatusCell, $artifactStatusMatch) !== 1) {
                $violations[] = "Malformed artifact status for {$artifactMatch['value']}.";

                continue;
            }
            if (preg_match('/^`(?<value>[^`]*)`$/', $runtimeStatusCell, $runtimeStatusMatch) !== 1) {
                $violations[] = "Malformed runtime status for {$artifactMatch['value']}.";

                continue;
            }
            if ($evidence === '') {
                $violations[] = "Runtime inventory artifact {$artifactMatch['value']} has empty evidence.";

                continue;
            }

            $rows[] = [
                'artifact' => $artifactMatch['value'],
                'artifact_status' => $artifactStatusMatch['value'],
                'runtime_status' => $runtimeStatusMatch['value'],
            ];
        }
        $expectedArtifacts = $this->expectedRuntimeInventory();
        $artifactIds = array_column($rows, 'artifact');
        $uniqueArtifactIds = array_values(array_unique($artifactIds));
        $duplicateViolations = $this->duplicateStructuralKeyViolations(
            $rows,
            'artifact',
            'runtime inventory artifact',
        );
        $violations = [...$violations, ...$duplicateViolations];
        $expectedArtifactIds = array_keys($expectedArtifacts);
        $actualIdSet = $uniqueArtifactIds;
        sort($actualIdSet);
        $expectedIdSet = $expectedArtifactIds;
        sort($expectedIdSet);

        if ($table['physical_count'] !== count($expectedArtifactIds)) {
            $violations[] = 'Runtime inventory raw declaration count does not match the expected registry.';
        }
        if (count($rows) !== $table['physical_count']) {
            $violations[] = 'Every physical runtime inventory row must parse successfully.';
        }
        if (count($uniqueArtifactIds) !== count($expectedArtifactIds)) {
            $violations[] = 'Runtime inventory unique artifact count does not match the expected registry.';
        }
        if ($actualIdSet !== $expectedIdSet) {
            $violations[] = 'Runtime inventory artifacts do not exactly match the expected registry.';
        }

        $artifacts = [];
        if ($violations === []) {
            foreach ($rows as $row) {
                $artifacts[$row['artifact']] = [
                    'artifact_status' => $row['artifact_status'],
                    'runtime_status' => $row['runtime_status'],
                ];
            }
            ksort($artifacts);
            $sortedExpectedArtifacts = $expectedArtifacts;
            ksort($sortedExpectedArtifacts);

            if ($artifacts !== $sortedExpectedArtifacts) {
                $violations[] = 'Runtime inventory classifications do not match the expected registry.';
            }
        }

        return [
            'rows' => $rows,
            'artifacts' => $artifacts,
            'violations' => array_values(array_unique($violations)),
            'raw_count' => $table['physical_count'],
            'parsed_count' => count($rows),
            'unique_count' => count($uniqueArtifactIds),
            'expected_count' => count($expectedArtifactIds),
        ];
    }

    /** @return array<string, array{artifact_status: string, runtime_status: string}> */
    private function expectedRuntimeInventory(): array
    {
        $presentInactive = [
            'artifact_status' => 'PRESENT / DEPLOYED',
            'runtime_status' => 'INACTIVE / UNWIRED',
        ];
        $presentUncalled = [
            'artifact_status' => 'PRESENT / DEPLOYED',
            'runtime_status' => 'UNCALLED',
        ];

        return [
            'supplier_import_execution_claims' => $presentInactive,
            'supplier_import_dispatch_outbox' => $presentInactive,
            'supplier_import_dispatch_monitor_health' => $presentInactive,
            'supplier_import_dispatch_alert_intents' => $presentInactive,
            'supplier_import_dispatch_recovery_authorizations' => $presentInactive,
            'supplier_import_dispatch_recovery_results' => $presentInactive,
            'supplier_import_cohort_authorization_members' => $presentInactive,
            'supplier_offer_snapshot_generations' => $presentInactive,
            'supplier_offer_snapshot_enrollments' => $presentInactive,
            'supplier_offer_snapshot_observations' => $presentInactive,
            'SupplierImportExecutionClaim' => $presentUncalled,
            'SupplierImportDispatchOutbox' => $presentUncalled,
            'SupplierImportDispatchMonitorHealth' => $presentUncalled,
            'SupplierImportDispatchAlertIntent' => $presentUncalled,
            'SupplierImportDispatchRecoveryAuthorization' => $presentUncalled,
            'SupplierImportDispatchRecoveryResult' => $presentUncalled,
            'SupplierImportCohortAuthorizationMember' => $presentUncalled,
            'SupplierOfferSnapshotGeneration' => $presentUncalled,
            'SupplierOfferSnapshotEnrollment' => $presentUncalled,
            'SupplierOfferSnapshotObservation' => $presentUncalled,
            'Phase II canonical byte/value contracts' => $presentUncalled,
            'SupplierSnapshotFingerprintService' => $presentUncalled,
            'SnapshotSourceIdentity' => $presentUncalled,
        ];
    }

    /**
     * @return array{
     *     rows: array<int, string>,
     *     violations: array<int, string>,
     *     raw_count: int,
     *     parsed_count: int
     * }
     */
    private function rolloutCheckpointContract(string $rollout): array
    {
        $table = $this->structuralMarkdownTable(
            $rollout,
            '| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |',
            '| --- | --- | --- | --- | --- | --- | --- | --- |',
            'rollout checkpoint',
            8,
            'The 103-row dependency audit checks 104 prerequisite edges and has zero missing',
        );
        $rows = [];
        $keys = [];
        $violations = $table['violations'];

        foreach ($table['rows'] as $position => $physicalRow) {
            $parsed = $this->structuralMarkdownRowCells(
                $physicalRow,
                8,
                'rollout checkpoint',
                $position + 1,
            );
            $violations = [...$violations, ...$parsed['violations']];

            if ($parsed['cells'] === null) {
                continue;
            }

            $idCell = $parsed['cells'][0];
            if (preg_match('/^[0-9]+$/', $idCell) !== 1) {
                $violations[] = 'Malformed rollout checkpoint ID at physical row '.($position + 1).'.';

                continue;
            }

            $id = (int) $idCell;
            $rows[] = $physicalRow;
            $keys[] = ['id' => $id];
        }

        $expectedIds = range(1, 103);
        $actualIds = array_column($keys, 'id');
        $violations = [
            ...$violations,
            ...$this->duplicateStructuralKeyViolations($keys, 'id', 'rollout checkpoint'),
        ];

        if ($table['physical_count'] !== count($expectedIds)) {
            $violations[] = 'Rollout checkpoint raw declaration count does not match the expected registry.';
        }
        if (count($rows) !== $table['physical_count']) {
            $violations[] = 'Every physical rollout checkpoint declaration must parse successfully.';
        }
        if ($actualIds !== $expectedIds) {
            $violations[] = 'Rollout checkpoint IDs must be exactly the ordered range 1 through 103.';
        }

        return [
            'rows' => $rows,
            'violations' => array_values(array_unique($violations)),
            'raw_count' => $table['physical_count'],
            'parsed_count' => count($rows),
        ];
    }

    /**
     * @return array{rows: array<int, string>, violations: array<int, string>, physical_count: int}
     */
    private function structuralMarkdownTable(
        string $contents,
        string $expectedHeader,
        string $expectedSeparator,
        string $context,
        int $expectedColumns,
        ?string $expectedEndMarker,
    ): array {
        $lines = preg_split('/\R/', $contents) ?: [];
        $headerPositions = array_keys($lines, $expectedHeader, true);
        $separatorPositions = array_keys($lines, $expectedSeparator, true);
        $endPositions = $expectedEndMarker === null
            ? []
            : array_keys($lines, $expectedEndMarker, true);
        $rows = [];

        $violations = [];
        if (count($headerPositions) !== 1) {
            $violations[] = "The {$context} table must contain exactly one canonical header.";
        }
        if (count($separatorPositions) !== 1) {
            $violations[] = "The {$context} table must contain exactly one canonical separator.";
        }
        if ($expectedEndMarker !== null && count($endPositions) !== 1) {
            $violations[] = "The {$context} table must contain exactly one canonical end marker.";
        }
        if (count($headerPositions) === 1 && count($separatorPositions) === 1
            && $separatorPositions[0] !== $headerPositions[0] + 1) {
            $violations[] = "The {$context} table separator must immediately follow its header.";
        }
        if (count($headerPositions) === 1 && count($separatorPositions) === 1
            && $separatorPositions[0] === $headerPositions[0] + 1) {
            $bodyEnd = $expectedEndMarker === null || count($endPositions) !== 1
                ? count($lines)
                : $endPositions[0];
            if ($bodyEnd <= $separatorPositions[0]) {
                $violations[] = "The {$context} table end marker must follow its separator.";
                $bodyEnd = $separatorPositions[0] + 1;
            }

            for ($lineNumber = $separatorPositions[0] + 1; $lineNumber < $bodyEnd; $lineNumber++) {
                if (trim($lines[$lineNumber]) === '') {
                    continue;
                }

                $rows[] = $lines[$lineNumber];
            }
        }
        foreach ($rows as $position => $row) {
            $parsed = $this->structuralMarkdownRowCells(
                $row,
                $expectedColumns,
                $context,
                $position + 1,
            );
            $violations = [...$violations, ...$parsed['violations']];
        }

        return [
            'rows' => $rows,
            'violations' => $violations,
            'physical_count' => count($rows),
        ];
    }

    /**
     * @return array{cells: array<int, string>|null, violations: array<int, string>}
     */
    private function structuralMarkdownRowCells(
        string $row,
        int $expectedColumns,
        string $context,
        int $position,
    ): array {
        if (! str_starts_with($row, '|') || ! str_ends_with($row, '|')) {
            return [
                'cells' => null,
                'violations' => ["Malformed {$context} physical row {$position}."],
            ];
        }

        $cells = array_map('trim', explode('|', substr($row, 1, -1)));
        if (count($cells) !== $expectedColumns) {
            return [
                'cells' => null,
                'violations' => [
                    "Malformed {$context} physical row {$position}: expected {$expectedColumns} columns, found ".count($cells).'.',
                ],
            ];
        }

        return [
            'cells' => $cells,
            'violations' => [],
        ];
    }

    /**
     * @param  array<int, array<string, int|string>>  $rows
     * @return array<int, string>
     */
    private function duplicateStructuralKeyViolations(array $rows, string $key, string $context): array
    {
        $counts = [];
        $violations = [];

        foreach ($rows as $row) {
            if (! array_key_exists($key, $row)) {
                $violations[] = "Missing {$context} structural key {$key}.";

                continue;
            }

            $value = (string) $row[$key];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        foreach ($counts as $value => $count) {
            if ($count > 1) {
                $violations[] = "Duplicate {$context} key: {$value} ({$count} occurrences).";
            }
        }

        return $violations;
    }

    private function structuralMarkdownRow(string $section, string $identity): string
    {
        preg_match_all(
            '/^\| `'.preg_quote($identity, '/').'` \|.*\|$/m',
            $section,
            $matches,
        );

        $this->assertCount(1, $matches[0], "Expected exactly one structural row for {$identity}.");

        return $matches[0][0];
    }

    private function insertStructuralRow(
        string $section,
        string $anchor,
        string $row,
        bool $before = false,
    ): string {
        $this->assertSame(1, substr_count($section, $anchor), 'Structural mutation anchor must be unique.');
        $lineEnding = str_contains($section, "\r\n") ? "\r\n" : "\n";

        return str_replace(
            $anchor,
            $before ? $row.$lineEnding.$anchor : $anchor.$lineEnding.$row,
            $section,
        );
    }

    private function removeStructuralRow(string $section, string $row): string
    {
        $this->assertSame(1, substr_count($section, $row), 'Structural removal target must be unique.');

        return str_replace($row, '', $section);
    }

    private function replaceStructuralText(string $contents, string $search, string $replacement): string
    {
        $this->assertSame(1, substr_count($contents, $search), 'Structural replacement target must be unique.');

        return str_replace($search, $replacement, $contents);
    }

    /**
     * @param  array<string, string>  $documents
     * @return array<string, string>
     */
    private function mutateDocument(array $documents, string $path, string $search, string $replacement): array
    {
        $documents[$path] = $this->replaceStructuralText($documents[$path], $search, $replacement);

        return $documents;
    }

    /** @return array<int, string> */
    private function commaSeparatedColumns(string $columns): array
    {
        return array_map(
            static fn (string $column): string => trim($column),
            explode(',', $columns),
        );
    }

    /** @return array<int, string> */
    private function phaseThreeCurrentStateViolations(string $design, string $plan): array
    {
        $violations = [];
        $normalizedDesign = preg_replace('/\s+/', ' ', $design);
        $normalizedPlan = preg_replace('/\s+/', ' ', $plan);

        if (! is_string($normalizedDesign) || ! is_string($normalizedPlan)) {
            return ['Unable to normalize Phase III current-state documentation.'];
        }

        if (! str_contains(
            $normalizedDesign,
            'The documented 86-identity APCOM staging-only cohort remains historical/staging evidence only and is not current canonical Phase III authorization.',
        )) {
            $violations[] = 'The 86-identity APCOM staging cohort is not explicitly historical and non-authoritative.';
        }
        if (str_contains($normalizedDesign, '86-identity APCOM staging-only cohort is therefore authorized')) {
            $violations[] = 'The historical 86-identity APCOM staging cohort is described as currently authorized.';
        }
        if (! str_contains($design, 'The exact affected deployed Phase I hexadecimal columns are:')) {
            $violations[] = 'The hexadecimal storage inventory is not classified as deployed Phase I schema.';
        }
        if (str_contains($design, 'The exact affected proposed columns are:')) {
            $violations[] = 'Deployed Phase I hexadecimal columns are described as proposed.';
        }
        if (! str_contains($normalizedPlan, 'The completed Phase I staging deployment added schema only.')) {
            $violations[] = 'The completed Phase I staging deployment is not recorded as past work.';
        }
        if (str_contains($plan, '**Mutation boundary.** Future deployment adds schema only.')) {
            $violations[] = 'The completed Phase I staging deployment is described as future work.';
        }

        return $violations;
    }

    private function readDocument(string $path): string
    {
        $contents = file_get_contents(base_path($path));

        $this->assertIsString($contents, "Unable to read {$path}.");

        return $contents;
    }

    private function markdownSection(string $contents, string $startHeading, string $endHeading): string
    {
        $start = strpos($contents, $startHeading);
        $this->assertNotFalse($start, "Missing section {$startHeading}.");

        $end = strpos($contents, $endHeading, $start + strlen($startHeading));
        $this->assertNotFalse($end, "Missing section boundary {$endHeading}.");

        return substr($contents, $start, $end - $start);
    }
}
