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
        $this->assertStringContainsString('dispatch_durable_progress_stalled', $design);
        $this->assertStringNotContainsString('dispatch_payload_unobserved', $design);
        $this->assertStringContainsString('supplier-import-dispatch-recovery-resume-v1', $design);
        $this->assertStringContainsString('Phase B, committed-start resume', $design);

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
            'The 71-row dependency audit has no forward-created prerequisite',
        ] as $needle) {
            $this->assertStringContainsString($needle, $design);
        }

        $this->assertMatchesRegularExpression(
            '/It never writes a\s+terminal claim\/outbox\/parent result\./',
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

        $this->assertCount(14, $protocolRows);
        $this->assertCount(41, $crashRows);
        $this->assertCount(71, $rolloutRows);

        foreach ([3 => $protocolRows, 11 => $crashRows, 8 => $rolloutRows] as $expectedColumns => $rows) {
            foreach ($rows as $row) {
                $this->assertCount($expectedColumns, explode('|', trim($row, '|')));
            }
        }

        $this->assertStringContainsString('exactly 14 data rows and 3 columns', $design);
        $this->assertStringContainsString('exactly 41 data rows and 11 columns', $design);
        $this->assertStringContainsString('canonical 71-row fine-grained checkpoint matrix', $design);

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
            $this->assertStringNotContainsString('53-row fine-grained checkpoint matrix', $contents);
            $this->assertStringNotContainsString('53 fine-grained checkpoints', $contents);
            $this->assertStringNotContainsString('protocol table remains 12 outcomes', $contents);
            $this->assertStringNotContainsString('36-by-11 crash matrix', $contents);
            $this->assertStringContainsString('71-row fine-grained checkpoint matrix', $contents);
        }

        foreach (['docs/PHASES.md', 'docs/ROADMAP.md'] as $document) {
            $contents = file_get_contents(base_path($document));

            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression('/71(?: fine-grained)? checkpoints/', $contents);
        }
    }
}
