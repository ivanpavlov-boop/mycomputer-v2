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

        $tableRows = static function (string $header) use ($design): array {
            $lines = preg_split('/\\R/', $design);
            $headerIndex = array_search($header, $lines, true);

            if ($headerIndex === false) {
                return [];
            }

            $rows = [];

            for ($index = $headerIndex + 2; isset($lines[$index]) && str_starts_with($lines[$index], '|'); $index++) {
                $rows[] = $lines[$index];
            }

            return $rows;
        };

        $protocolRows = $tableRows('| Ownership and payload observation | Transport/response boundary | Permitted protocol outcome |');
        $crashRows = $tableRows('| Boundary | Path | SupplierImportRun | ImportJob | ImportHistory | Claim | Outbox | Evidence | Allowed recovery | Prohibited actions | Required operator action |');
        $rolloutRows = $tableRows('| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |');
        $stateFieldRows = $tableRows('| Position | Key | Exact JSON type and value contract | Nullable |');
        $digestRows = $tableRows('| # | Identity | Purpose | Producer | Canonical bytes and domain | Algorithm | Persistence location | Immutability | Comparison point |');
        $hexStorageRows = $tableRows('| Table | Non-null lowercase hexadecimal columns | Nullable lowercase hexadecimal columns |');

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
        $design = file_get_contents(base_path('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md'));
        $plan = file_get_contents(base_path('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md'));

        $this->assertIsString($design);
        $this->assertIsString($plan);

        foreach ([
            '| `PH3-RDY-001` | `CLOSED` |',
            '| `PH3-RDY-002` | `BLOCKED` |',
            '| `PH3-RDY-003` | `BLOCKED` |',
            '| `PH3-RDY-004` | `CLOSED` |',
            '(supplier_id, supplier_feed_id, source_identity)',
            'matching supplier with `supplier_feed_id IS NULL` | `FAIL CLOSED`',
            'non-null `supplier_feed_id` differs | `EXCLUDE`',
            '`product_supplier_offers` contains neither `supplier_feed_id` nor',
            '`supplier_id` or equal SKU text alone is never enough',
            'matching supplier with null/missing `supplier_product_id` | `FAIL CLOSED`',
            'sort lowercase ASCII hashes by unsigned byte order',
            'different validated canonical SKU bytes producing',
            'An empty sorted distinct seed is valid',
            'supplier_import_execution_claims.cohort_source_identity',
            'uq_import_execution_claim_id_cohort_source',
            'current mutable feed configuration is never a backfill source',
            '| `max_source_rows` | `NOT SPECIFIED` |',
            '| `max_spool_rows` | `NOT SPECIFIED` |',
            '| `max_spool_bytes` | `NOT SPECIFIED` |',
            '| `max_enrollments` | `NOT SPECIFIED` |',
            '| `max_observations` | `NOT SPECIFIED` |',
            '| `max_canonical_children` | `NOT SPECIFIED` |',
            '| external-sort chunk | `NOT SPECIFIED` |',
            '| immutable DB insert batch | `NOT SPECIFIED` |',
            '| snapshot transaction bound | `NOT SPECIFIED` |',
            'frozen `capture_outcome=overflow` header with `capture_overflow`',
            'Overflow is not retryable under unchanged bounds',
            'Phase III implementation remains prohibited',
        ] as $contract) {
            $this->assertStringContainsString($contract, $design);
        }

        foreach ([
            'Phase I canonical schema: implemented, merged through PR #212',
            'Phase II guarded models and canonical byte contracts: implemented, merged',
            'Phase III snapshot persistence/cohort authorization: readiness remediation',
            'Phase III remains blocked by `PH3-RDY-002` and `PH3-RDY-003`',
        ] as $status) {
            $this->assertStringContainsString($status, $plan);
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
            'docs/PHASES.md',
            'docs/ROADMAP.md',
            'docs/SUPPLIER_ONBOARDING_FRAMEWORK.md',
            'docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md',
        ] as $document) {
            $contents = file_get_contents(base_path($document));

            $this->assertIsString($contents);
            $this->assertStringContainsString('Phase I', $contents);
            $this->assertStringContainsString('Phase II', $contents);
            $this->assertStringContainsString('Phase III', $contents);
            $this->assertStringContainsString('unimplemented', $contents);
        }
    }
}
