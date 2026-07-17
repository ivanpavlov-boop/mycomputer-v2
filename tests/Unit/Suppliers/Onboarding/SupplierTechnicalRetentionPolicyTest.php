<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Services\Suppliers\Onboarding\SupplierTechnicalRetentionPolicy;
use PHPUnit\Framework\TestCase;

final class SupplierTechnicalRetentionPolicyTest extends TestCase
{
    public function test_retention_targets_are_non_executable(): void
    {
        $policy = (new SupplierTechnicalRetentionPolicy)->policy();

        $this->assertSame('supplier-technical-retention-policy-v1', $policy['policy_key']);
        $this->assertSame(90, $policy['detailed_technical_import_log_retention_days']);
        $this->assertSame(90, $policy['raw_supplier_snapshot_retention_days']);
        $this->assertSame(24, $policy['summarized_import_run_retention_months']);
        $this->assertSame('indefinite', $policy['critical_business_audit_retention']);
        $this->assertTrue($policy['meaningful_price_stock_history_only']);
        $this->assertFalse($policy['cleanup_execution_allowed']);
        $this->assertFalse($policy['scheduler_allowed']);
    }
}
