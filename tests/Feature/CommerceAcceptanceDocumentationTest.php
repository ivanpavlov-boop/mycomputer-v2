<?php

namespace Tests\Feature;

use Tests\TestCase;

class CommerceAcceptanceDocumentationTest extends TestCase
{
    public function test_checkout_payment_acceptance_matrix_is_complete_and_machine_readable(): void
    {
        $path = base_path('docs/COMMERCE_CHECKOUT_PAYMENT_ACCEPTANCE.json');
        $matrix = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $requiredFields = [
            'id',
            'layer',
            'customer_type',
            'payment_method',
            'initial_state',
            'operation',
            'expected_http',
            'expected_order_count',
            'expected_transaction_count',
            'expected_attempt_count',
            'expected_provider_calls',
            'expected_leasing_applications',
            'expected_notifications',
            'expected_ui_action',
            'security_assertions',
            'test_evidence',
        ];

        $this->assertSame('Commerce Phase 1D.3', $matrix['phase']);
        $this->assertSame('complete_locally', $matrix['status']);
        $this->assertFalse($matrix['public_commerce_enabled']);
        $this->assertFalse($matrix['real_provider_enabled']);
        $this->assertCount(25, $matrix['scenarios']);

        foreach ($matrix['scenarios'] as $scenario) {
            $this->assertEmpty(array_diff($requiredFields, array_keys($scenario)));
            $this->assertNotEmpty($scenario['security_assertions']);
            $this->assertNotEmpty($scenario['test_evidence']);
        }

        $groups = collect($matrix['scenarios'])
            ->map(fn (array $scenario): string => (string) preg_replace(
                '/-\d+$/',
                '',
                $scenario['id'],
            ))
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            'CHECKOUT',
            'PAYMENT-PRESENTATION',
            'PAYMENT-RETRY',
            'OWNERSHIP',
            'CAPABILITIES',
            'CONCURRENCY',
            'ROLLBACK',
            'NOTIFICATIONS',
            'BROWSER',
            'RELEASE-GATE',
        ], $groups);
    }

    public function test_documentation_records_deployed_retry_controls_and_closed_release_gate(): void
    {
        $acceptance = (string) file_get_contents(
            base_path('docs/COMMERCE_CHECKOUT_PAYMENT_ACCEPTANCE.md'),
        );
        $gapRegister = (string) file_get_contents(base_path('docs/CART_GAP_REGISTER.json'));

        $this->assertStringContainsString(
            'Commerce Phase 1D.2B is merged, MySQL CI verified, deployed',
            $acceptance,
        );
        $this->assertStringContainsString('CART-008 is remediated, deployed', $acceptance);
        $this->assertStringContainsString('CART-023 therefore remains open', $acceptance);
        $this->assertStringContainsString('"status": "complete_locally"', $gapRegister);
        $this->assertStringContainsString('"id": "CART-008"', $gapRegister);
        $this->assertStringContainsString('"status": "remediated"', $gapRegister);
    }

    public function test_payment_and_catalog_sync_launch_flags_remain_fail_closed(): void
    {
        $this->assertFalse((bool) config('payments.card.enabled'));
        $this->assertFalse((bool) config('payments.leasing.enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }
}
