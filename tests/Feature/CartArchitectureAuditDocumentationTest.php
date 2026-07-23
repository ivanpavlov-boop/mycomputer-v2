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
            $this->assertSame('open', $finding['status'] ?? null);
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
                'Commerce Phase 1B must not start',
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
