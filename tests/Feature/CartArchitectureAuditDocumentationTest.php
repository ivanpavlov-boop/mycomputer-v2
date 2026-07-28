<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CartArchitectureAuditDocumentationTest extends TestCase
{
    public function test_architecture_report_contains_every_required_section(): void
    {
        $path = base_path('docs/CART_ARCHITECTURE_SAFETY_AUDIT.md');

        $this->assertFileExists($path);

        $report = file_get_contents($path);

        $this->assertIsString($report);

        $headings = [
            'Executive Summary',
            'Scope and Method',
            'Current Cart Architecture',
            'Guest Cart Lifecycle',
            'Authenticated Cart Lifecycle',
            'Guest-to-Authenticated Transition',
            'API Endpoint Matrix',
            'Cart Identity and Ownership',
            'Cart Data Model and Constraints',
            'Product Eligibility',
            'Pricing Authority',
            'Promotions, Coupons and Gifts',
            'Bundles',
            'Stock and Availability',
            'Expiry and Cart Status',
            'Cart Recovery and Email',
            'Frontend State Architecture',
            'SSR, Hydration and Persistence',
            'Error and Offline Behavior',
            'Cart-to-Checkout Boundary',
            'Checkout, Order and Stock Boundary',
            'Concurrency and Idempotency',
            'Security Review',
            'Performance and Query Review',
            'Existing Test Coverage',
            'Confirmed Findings',
            'Open Questions',
            'Prioritized Remediation Plan',
            'Proposed Commerce Phase Sequence',
            'Release Gates',
        ];

        foreach ($headings as $index => $heading) {
            $this->assertStringContainsString(
                sprintf('## %d. %s', $index + 1, $heading),
                $report,
            );
        }
    }

    public function test_gap_register_has_a_safe_complete_machine_readable_contract(): void
    {
        $path = base_path('docs/CART_GAP_REGISTER.json');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        $register = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Commerce Phase 1A', $register['phase'] ?? null);
        $this->assertSame('read_only', $register['audit_type'] ?? null);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{40}\z/',
            $register['generated_from_commit'] ?? '',
        );
        $this->assertNotEmpty($register['findings'] ?? []);

        $allowedSeverities = ['blocker', 'high', 'medium', 'low', 'info'];
        $allowedConfidence = ['confirmed', 'likely', 'open_question'];
        $allowedTargets = [
            'Commerce Phase 1B',
            'Commerce Phase 1C',
            'Commerce Phase 1D',
            'Commerce Phase 1D.2B',
            'Later',
        ];
        $ids = [];

        foreach ($register['findings'] as $index => $finding) {
            $expectedId = sprintf('CART-%03d', $index + 1);

            $this->assertSame($expectedId, $finding['id'] ?? null);
            $this->assertMatchesRegularExpression('/\ACART-\d{3}\z/', $finding['id'] ?? '');
            $this->assertNotContains($finding['id'], $ids);
            $ids[] = $finding['id'];

            foreach ([
                'area',
                'title',
                'current_behavior',
                'risk',
                'verification',
                'recommendation',
            ] as $requiredText) {
                $this->assertIsString($finding[$requiredText] ?? null);
                $this->assertNotSame('', trim($finding[$requiredText] ?? ''));
            }

            $this->assertContains($finding['severity'] ?? null, $allowedSeverities);
            $this->assertContains($finding['confidence'] ?? null, $allowedConfidence);
            $this->assertSame(
                $finding['id'] === 'CART-008' ? 'remediated' : 'open',
                $finding['status'] ?? null,
            );
            if (in_array($finding['id'], ['CART-002', 'CART-004', 'CART-005', 'CART-009', 'CART-010', 'CART-014', 'CART-015', 'CART-026'], true)) {
                $this->assertSame('remediated_locally', $finding['local_remediation_status'] ?? null);
            }
            $this->assertContains($finding['target_phase'] ?? null, $allowedTargets);
            $this->assertNotEmpty($finding['acceptance_criteria'] ?? []);
            $this->assertNotEmpty($finding['evidence'] ?? []);

            foreach ($finding['acceptance_criteria'] as $criterion) {
                $this->assertIsString($criterion);
                $this->assertNotSame('', trim($criterion));
            }

            foreach ($finding['evidence'] as $evidence) {
                $evidencePath = $evidence['path'] ?? '';

                $this->assertIsString($evidencePath);
                $this->assertNotSame('', trim($evidencePath));
                $this->assertIsString($evidence['symbol'] ?? null);
                $this->assertNotSame('', trim($evidence['symbol'] ?? ''));
                $this->assertDoesNotMatchRegularExpression('/\A[A-Za-z]:[\\\\\/]/', $evidencePath);
                $this->assertStringStartsNotWith('/', $evidencePath);
                $this->assertStringNotContainsString('\\', $evidencePath);
                $this->assertStringNotContainsString('../', $evidencePath);
                $this->assertFileExists(base_path($evidencePath));
            }
        }

        $this->assertCount(count(array_unique($ids)), $ids);

        $progress = $register['remediation_progress'] ?? [];

        $this->assertCount(15, $progress);
        $this->assertSame('Commerce Phase 1B.1', $progress[0]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[0]['status'] ?? null);
        $this->assertSame(['CART-001', 'CART-022'], $progress[0]['finding_ids'] ?? null);
        $this->assertSame(['CART-017'], $progress[0]['partial_finding_ids'] ?? null);
        $this->assertSame([], $progress[0]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[0]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1B.2', $progress[1]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[1]['status'] ?? null);
        $this->assertSame(['CART-003', 'CART-011'], $progress[1]['finding_ids'] ?? null);
        $this->assertSame([], $progress[1]['partial_finding_ids'] ?? null);
        $this->assertSame([], $progress[1]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[1]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1B.3', $progress[2]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[2]['status'] ?? null);
        $this->assertSame(['CART-006', 'CART-007'], $progress[2]['finding_ids'] ?? null);
        $this->assertSame([], $progress[2]['partial_finding_ids'] ?? null);
        $this->assertSame([], $progress[2]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[2]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1B.4', $progress[3]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[3]['status'] ?? null);
        $this->assertSame(['CART-012', 'CART-013'], $progress[3]['finding_ids'] ?? null);
        $this->assertSame([], $progress[3]['partial_finding_ids'] ?? null);
        $this->assertSame([], $progress[3]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[3]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1B.5', $progress[4]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[4]['status'] ?? null);
        $this->assertSame(['CART-014', 'CART-015'], $progress[4]['finding_ids'] ?? null);
        $this->assertSame([], $progress[4]['partial_finding_ids'] ?? null);
        $this->assertSame([], $progress[4]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[4]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1B.6', $progress[5]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[5]['status'] ?? null);
        $this->assertSame(['CART-009', 'CART-010'], $progress[5]['finding_ids'] ?? null);
        $this->assertNotEmpty($progress[5]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1C.1', $progress[6]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[6]['status'] ?? null);
        $this->assertSame(['CART-004', 'CART-005'], $progress[6]['finding_ids'] ?? null);
        $this->assertSame(['CART-018', 'CART-019'], $progress[6]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-024', 'CART-026'], $progress[6]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[6]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1C.2', $progress[7]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[7]['status'] ?? null);
        $this->assertSame(['CART-018', 'CART-019'], $progress[7]['finding_ids'] ?? null);
        $this->assertSame([], $progress[7]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-024', 'CART-026'], $progress[7]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[7]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1C.3', $progress[8]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[8]['status'] ?? null);
        $this->assertSame(['CART-024'], $progress[8]['finding_ids'] ?? null);
        $this->assertSame([], $progress[8]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-023', 'CART-026'], $progress[8]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[8]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1C.4', $progress[9]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[9]['status'] ?? null);
        $this->assertSame(['CART-026'], $progress[9]['finding_ids'] ?? null);
        $this->assertSame([], $progress[9]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-023'], $progress[9]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[9]['notes'] ?? []);
        $this->assertContains(
            'Issued and deletion cookies use explicit Symfony Cookie domain null.',
            $progress[9]['notes'],
        );
        $this->assertContains(
            'Phase 1C.4 is merged, deployed and staging verified.',
            $progress[9]['notes'],
        );
        $this->assertSame('Commerce Phase 1D.1', $progress[10]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[10]['status'] ?? null);
        $this->assertSame(['CART-002'], $progress[10]['finding_ids'] ?? null);
        $this->assertSame([], $progress[10]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-008', 'CART-023'], $progress[10]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[10]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1D.2A', $progress[11]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[11]['status'] ?? null);
        $this->assertSame([], $progress[11]['finding_ids'] ?? null);
        $this->assertSame(['CART-008'], $progress[11]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-008', 'CART-023'], $progress[11]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[11]['notes'] ?? []);
        $this->assertSame('Commerce Phase 1D.2B', $progress[12]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[12]['status'] ?? null);
        $this->assertSame(['CART-008'], $progress[12]['finding_ids'] ?? null);
        $this->assertSame([], $progress[12]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-023'], $progress[12]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[12]['notes'] ?? []);
        $this->assertContains(
            'Phase 1D.2B is merged, MySQL CI verified, deployed and staging schema/security verified.',
            $progress[12]['notes'] ?? [],
        );
        $this->assertSame('Commerce Phase 1D.3', $progress[13]['phase'] ?? null);
        $this->assertSame('complete_locally', $progress[13]['status'] ?? null);
        $this->assertSame(['CART-008'], $progress[13]['finding_ids'] ?? null);
        $this->assertSame([], $progress[13]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-023'], $progress[13]['open_finding_ids'] ?? null);
        $this->assertNotEmpty($progress[13]['notes'] ?? []);
        $this->assertSame('Commerce Leasing Phase A', $progress[14]['phase'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $progress[14]['status'] ?? null);
        $this->assertSame([], $progress[14]['finding_ids'] ?? null);
        $this->assertSame([], $progress[14]['partial_finding_ids'] ?? null);
        $this->assertSame(['CART-023'], $progress[14]['open_finding_ids'] ?? null);
        $this->assertContains(
            'Commerce Leasing Phase A is merged, MySQL CI verified, deployed and staging schema/safety verified; leasing remains disabled by default.',
            $progress[14]['notes'] ?? [],
        );

        $cart008 = collect($register['findings'])->firstWhere('id', 'CART-008');
        $this->assertSame('remediated', $cart008['status'] ?? null);
        $this->assertSame('merged_deployed_staging_verified', $cart008['local_remediation_status'] ?? null);
    }

    public function test_audit_artifacts_contain_no_environment_or_secret_material(): void
    {
        $contents = implode("\n", [
            file_get_contents(base_path('docs/CART_ARCHITECTURE_SAFETY_AUDIT.md')),
            file_get_contents(base_path('docs/CART_GAP_REGISTER.json')),
        ]);

        $this->assertStringNotContainsString('/var/www/', $contents);
        $this->assertStringNotContainsString('computer2u.eu', $contents);
        $this->assertStringNotContainsString('mycomputer.bg', $contents);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]:\\\\/', $contents);
        $this->assertDoesNotMatchRegularExpression(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            $contents,
        );
        $this->assertDoesNotMatchRegularExpression('/Bearer\s+[A-Za-z0-9._~+\/=-]{16,}/i', $contents);
        $this->assertDoesNotMatchRegularExpression(
            '/(?:password|api[_ -]?key|secret)\s*[:=]\s*["\']?[^\s"\']{8,}/i',
            $contents,
        );
    }

    public function test_phase_documents_record_the_audit_and_preserve_release_gates(): void
    {
        $phases = file_get_contents(base_path('docs/PHASES.md'));
        $roadmap = file_get_contents(base_path('docs/ROADMAP.md'));

        $this->assertIsString($phases);
        $this->assertIsString($roadmap);

        $this->assertStringContainsString('Commerce Phase 1A', $phases);
        $this->assertStringContainsString('Commerce Phase 1B.1', $phases);
        $this->assertStringContainsString('Commerce Phase 1B.2', $phases);
        $this->assertStringContainsString('Commerce Phase 1B.3', $phases);
        $this->assertStringContainsString('Commerce Phase 1B.4', $phases);
        $this->assertStringContainsString('Commerce Phase 1B.5', $phases);
        $this->assertStringContainsString('Commerce Phase 1B.6', $phases);
        $this->assertStringContainsString('Commerce Phase 1C.1', $phases);
        $this->assertStringContainsString('Commerce Phase 1C.2', $phases);
        $this->assertStringContainsString('Commerce Phase 1C.3', $phases);
        foreach (['Commerce Phase 1A', 'Commerce Phase 1B', 'Commerce Phase 1C', 'Commerce Phase 1D'] as $phase) {
            $this->assertStringContainsString($phase, $roadmap);
        }

        foreach ([$phases, $roadmap] as $document) {
            $normalizedDocument = preg_replace('/\s+/', ' ', $document);

            $this->assertIsString($normalizedDocument);
            $this->assertStringContainsString(
                'Phase 9C.9 final manual staging verification remains',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'Phase 9C.11 is merged, deployed and staging verified',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'Public Cart and checkout pages remain disabled',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'CART-024',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'CART-026',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'Staging verification confirmed',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'Commerce Phase 1D.1',
                $normalizedDocument,
            );
            $this->assertStringContainsString(
                'CART-003',
                $normalizedDocument,
            );
        }

        $register = json_decode(
            file_get_contents(base_path('docs/CART_GAP_REGISTER.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($register['findings'] as $finding) {
            $this->assertNotContains(
                strtolower($finding['status']),
                ['fixed', 'complete', 'completed', 'resolved'],
            );
        }
    }
}
