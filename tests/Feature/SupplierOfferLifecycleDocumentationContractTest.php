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

        $protocolRows = $this->markdownTableRows(
            $design,
            '| Ownership and payload observation | Transport/response boundary | Permitted protocol outcome |',
        );
        $crashRows = $this->markdownTableRows(
            $design,
            '| Boundary | Path | SupplierImportRun | ImportJob | ImportHistory | Claim | Outbox | Evidence | Allowed recovery | Prohibited actions | Required operator action |',
        );
        $rolloutRows = $this->markdownTableRows(
            $design,
            '| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |',
        );
        $stateFieldRows = $this->markdownTableRows(
            $design,
            '| Position | Key | Exact JSON type and value contract | Nullable |',
        );
        $digestRows = $this->markdownTableRows(
            $design,
            '| # | Identity | Purpose | Producer | Canonical bytes and domain | Algorithm | Persistence location | Immutability | Comparison point |',
        );
        $hexStorageRows = $this->markdownTableRows(
            $design,
            '| Table | Non-null lowercase hexadecimal columns | Nullable lowercase hexadecimal columns |',
        );

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
            '### Phase III readiness findings and authority',
            '### Canonical source scope',
        );

        $readinessStatusContract = $this->readinessStatusContract($readiness);
        $expectedReadinessStatuses = [
            'PH3-RDY-001' => 'BLOCKED',
            'PH3-RDY-002' => 'BLOCKED',
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
        $this->assertStringNotContainsString('| `PH3-RDY-001` | `CLOSED` |', $readiness);
        $this->assertStringContainsString(
            'Existing application candidate rows do not carry immutable source provenance',
            $readiness,
        );
        $this->assertStringContainsString('Three independent blockers remain', $readiness);

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
            'Phase III remains blocked until a separate immutable candidate-row/source',
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
            'Candidate provenance remediation is',
            '**REQUIRED / UNRESOLVED**',
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
            'canonical serializer/fingerprint revision is currently **NOT REQUIRED**',
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
            '| external-sort chunk | `NOT SPECIFIED` |',
            '| immutable DB insert batch | `NOT SPECIFIED` |',
            '| snapshot transaction bound | `NOT SPECIFIED` |',
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
            'Phase III snapshot persistence/cohort authorization: readiness remediation',
            'Phase III remains blocked by `PH3-RDY-001`, `PH3-RDY-002`, and `PH3-RDY-003`',
        ] as $status) {
            $this->assertStringContainsString($status, $plan);
        }

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
        $this->assertStringContainsString('Phase I\'s canonical schema is implemented through PR #212', $phasesInProgress);
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

        foreach ([$readiness, $reviewBoundary] as $currentReadinessSection) {
            $this->assertMatchesRegularExpression(
                '/\bthree\s+independent\s+blockers\b/i',
                $currentReadinessSection,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:two|both|either)\s+(?:(?:remaining|unresolved)\s+)?blockers?\b/i',
                $currentReadinessSection,
            );
        }
        $this->assertCount(
            3,
            array_filter(
                $readinessStatusContract['statuses'],
                static fn (string $status): bool => $status === 'BLOCKED',
            ),
        );

        foreach ([$phasesInProgress, $roadmap, $onboarding] as $currentStatus) {
            $this->assertStringContainsString('`PH3-RDY-001`', $currentStatus);
            $this->assertStringContainsString('`PH3-RDY-002`', $currentStatus);
            $this->assertStringContainsString('`PH3-RDY-003`', $currentStatus);
            $this->assertStringContainsString('`PH3-RDY-004`', $currentStatus);
            $this->assertStringNotContainsString(
                '`PH3-RDY-001` and `PH3-RDY-004` are closed',
                $currentStatus,
            );
        }
    }

    public function test_phase_three_readiness_structural_collections_reject_duplicate_shadowing(): void
    {
        $design = $this->readDocument('docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md');
        $plan = $this->readDocument('docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md');
        $readiness = $this->markdownSection(
            $design,
            '### Phase III readiness findings and authority',
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

        $rolloutRows = $this->markdownTableRows(
            $design,
            '| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |',
        );
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
    private function canonicalAuthorizationCompletenessTuple(string $sourceBinding): array
    {
        $matched = preg_match(
            '/Canonical proposed future authorization completeness tuple \(ordered\):\R\R```text\R(?<fields>.*?)\R```/s',
            $sourceBinding,
            $tuple,
        );

        $this->assertSame(1, $matched, 'Canonical authorization completeness tuple is missing.');

        return $this->lineSeparatedFields($tuple['fields'] ?? '');
    }

    /**
     * @param  array<int, string>  $expectedTuple
     * @return array{registry_ids: array<int, string>, violations: array<int, string>}
     */
    private function authorizationProcedureContract(string $design, array $expectedTuple): array
    {
        $registryIds = $this->authorizationProcedureRegistryIds($design);
        $startIds = $this->authorizationProcedureMarkerIds($design, 'start');
        $endIds = $this->authorizationProcedureMarkerIds($design, 'end');
        $declarationIds = $this->authorizationProcedureDeclarationIds($design);
        $blockRows = $this->authorizationProcedureBlockRows($design);
        $blockIds = array_column($blockRows, 'id');
        $violations = [];

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
            'violations' => $violations,
        ];
    }

    /** @return array<int, string> */
    private function authorizationProcedureRegistryIds(string $design): array
    {
        $matched = preg_match(
            '/Normative authorization procedure registry \(ordered\):\R\R```text\R(?<ids>.*?)\R```/s',
            $design,
            $registry,
        );

        return $matched === 1
            ? $this->lineSeparatedFields($registry['ids'] ?? '')
            : [];
    }

    /** @return array<int, string> */
    private function authorizationProcedureMarkerIds(string $design, string $marker): array
    {
        preg_match_all(
            '/^<!-- normative-authorization-procedure:'.preg_quote($marker, '/').' id=(?<id>[a-z0-9-]+) -->$/m',
            $design,
            $matches,
        );

        return array_values($matches['id'] ?? []);
    }

    /** @return array<int, string> */
    private function authorizationProcedureDeclarationIds(string $design): array
    {
        preg_match_all(
            '/^Normative authorization procedure `(?<id>[a-z0-9-]+)`$/m',
            $design,
            $matches,
        );

        return array_values($matches['id'] ?? []);
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
        $violations = [];
        $relevantDocuments = [];
        $contexts = [];
        $stateContracts = [];
        $stateReferences = [];

        ksort($documents);

        foreach ($documents as $path => $document) {
            preg_match_all(
                $this->watchdogStateContractPattern(),
                $document,
                $contractMatches,
                PREG_SET_ORDER,
            );

            foreach ($contractMatches as $match) {
                $stateContracts[] = [
                    'path' => $path,
                    'id' => $match['id'],
                    'body' => $match['body'],
                ];
            }

            preg_match_all(
                $this->watchdogStateReferencePattern(),
                $document,
                $referenceMatches,
                PREG_SET_ORDER,
            );

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

            preg_match_all(
                '/^<!-- watchdog-document-context classification=(?<classification>[A-Z_]+) '.
                'column_occurrences=(?<column>\d+) index_occurrences=(?<index>\d+) '.
                'contract=(?<contract>[a-z0-9-]+) -->\r?$/m',
                $document,
                $contextMatches,
                PREG_SET_ORDER,
            );

            if (! $isRelevant) {
                if ($contextMatches !== [] || $referenceMatches !== []) {
                    $violations[] = "Watchdog context marker in {$path} has no watchdog occurrence.";
                }

                continue;
            }

            $relevantDocuments[] = $path;

            if (count($contextMatches) !== 1) {
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
                        'contract' => $referenceMatches[0]['contract'],
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
     *     unique_count: int,
     *     expected_count: int
     * }
     */
    private function readinessStatusContract(string $readiness): array
    {
        preg_match_all(
            '/^\| `(?<id>PH3-RDY-[0-9]{3})` \| `(?<status>BLOCKED|CLOSED)` \|/m',
            $readiness,
            $matches,
            PREG_SET_ORDER,
        );

        $rows = array_map(
            static fn (array $match): array => [
                'id' => $match['id'],
                'status' => $match['status'],
            ],
            $matches,
        );
        $expectedStatuses = [
            'PH3-RDY-001' => 'BLOCKED',
            'PH3-RDY-002' => 'BLOCKED',
            'PH3-RDY-003' => 'BLOCKED',
            'PH3-RDY-004' => 'CLOSED',
        ];
        $ids = array_column($rows, 'id');
        $uniqueIds = array_values(array_unique($ids));
        $duplicateViolations = $this->duplicateStructuralKeyViolations($rows, 'id', 'readiness status');
        $violations = $duplicateViolations;
        $expectedIds = array_keys($expectedStatuses);
        $actualIdSet = $uniqueIds;
        sort($actualIdSet);
        $expectedIdSet = $expectedIds;
        sort($expectedIdSet);

        if (count($rows) !== count($expectedIds)) {
            $violations[] = 'Readiness status raw declaration count does not match the expected registry.';
        }
        if (count($uniqueIds) !== count($expectedIds)) {
            $violations[] = 'Readiness status unique ID count does not match the expected registry.';
        }
        if ($actualIdSet !== $expectedIdSet) {
            $violations[] = 'Readiness status IDs do not exactly match the expected registry.';
        }

        $statuses = [];
        if ($duplicateViolations === []) {
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
            'raw_count' => count($rows),
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
     *     unique_count: int,
     *     expected_count: int
     * }
     */
    private function runtimeInventoryContract(string $inventory): array
    {
        $rows = $this->runtimeInventoryRowList($inventory);
        $expectedArtifacts = $this->expectedRuntimeInventory();
        $artifactIds = array_column($rows, 'artifact');
        $uniqueArtifactIds = array_values(array_unique($artifactIds));
        $duplicateViolations = $this->duplicateStructuralKeyViolations(
            $rows,
            'artifact',
            'runtime inventory artifact',
        );
        $violations = $duplicateViolations;
        $expectedArtifactIds = array_keys($expectedArtifacts);
        $actualIdSet = $uniqueArtifactIds;
        sort($actualIdSet);
        $expectedIdSet = $expectedArtifactIds;
        sort($expectedIdSet);

        if (count($rows) !== count($expectedArtifactIds)) {
            $violations[] = 'Runtime inventory raw declaration count does not match the expected registry.';
        }
        if (count($uniqueArtifactIds) !== count($expectedArtifactIds)) {
            $violations[] = 'Runtime inventory unique artifact count does not match the expected registry.';
        }
        if ($actualIdSet !== $expectedIdSet) {
            $violations[] = 'Runtime inventory artifacts do not exactly match the expected registry.';
        }

        $artifacts = [];
        if ($duplicateViolations === []) {
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
            'raw_count' => count($rows),
            'unique_count' => count($uniqueArtifactIds),
            'expected_count' => count($expectedArtifactIds),
        ];
    }

    /** @return array<int, array{artifact: string, artifact_status: string, runtime_status: string}> */
    private function runtimeInventoryRowList(string $inventory): array
    {
        preg_match_all(
            '/^\| `(?<artifact>[^`]+)` \| `(?<artifact_status>[^`]+)` \| `(?<runtime_status>[^`]+)` \| .+ \|$/m',
            $inventory,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [
                'artifact' => $match['artifact'],
                'artifact_status' => $match['artifact_status'],
                'runtime_status' => $match['runtime_status'],
            ],
            $matches,
        );
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

    /** @return array<int, string> */
    private function markdownTableRows(string $contents, string $header): array
    {
        $lines = preg_split('/\\R/', $contents);
        $headerIndex = array_search($header, $lines, true);

        if ($headerIndex === false) {
            return [];
        }

        $rows = [];

        for ($index = $headerIndex + 2; isset($lines[$index]) && str_starts_with($lines[$index], '|'); $index++) {
            $rows[] = $lines[$index];
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function commaSeparatedColumns(string $columns): array
    {
        return array_map(
            static fn (string $column): string => trim($column),
            explode(',', $columns),
        );
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
