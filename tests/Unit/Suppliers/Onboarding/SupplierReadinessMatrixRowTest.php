<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\ReadinessStage;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixRow;
use App\Data\Suppliers\Onboarding\ValidationIssue;
use App\Data\Suppliers\Onboarding\ValidationSeverity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SupplierReadinessMatrixRowTest extends TestCase
{
    public function test_row_is_immutable_safe_and_reconciles_staging_counts(): void
    {
        $row = $this->row();
        $payload = $row->toArray();

        $this->assertSame('supplier-readiness-matrix-v1', $payload['schema_version']);
        $this->assertSame('fixture-supplier', $payload['supplier_key']);
        $this->assertSame('source_profile_required', $payload['readiness_stage']);
        $this->assertSame(10, $payload['staging_row_count']);
        $this->assertSame(2, $payload['linked_staging_row_count']);
        $this->assertSame(8, $payload['unlinked_staging_row_count']);
        $this->assertSame('feed_profile_missing', $payload['blockers'][0]['code']);
        $this->assertSame('authentication_unknown', $payload['warnings'][0]['code']);
    }

    public function test_row_rejects_inconsistent_counts_and_unsafe_metadata(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SupplierReadinessMatrixRow(
            supplierKey: 'fixture-supplier',
            supplierName: 'Fixture Supplier',
            supplierSlug: 'fixture-supplier',
            active: true,
            importEnabled: true,
            scheduleEnabled: false,
            scheduleType: 'manual_only',
            sourceFormat: 'xml',
            sourceConfigured: true,
            authenticationRequired: null,
            authenticationConfigured: null,
            driverKey: 'legacy-xml-staging',
            driverAvailable: true,
            driverContractVersion: 'supplier-feed-driver-v1',
            feedProfileKey: null,
            feedProfileVersion: null,
            feedProfileAvailable: false,
            capabilityAuditAvailable: true,
            previewCapability: false,
            controlledStagingCapability: false,
            postApplyVerificationCapability: false,
            mappingReviewCapability: true,
            manualCreateCapability: false,
            stagingRowCount: 3,
            linkedStagingRowCount: 1,
            unlinkedStagingRowCount: 1,
            categoryMappingCount: 0,
            canonicalFamilyCount: 0,
            lastImportAt: null,
            lastPreviewAt: null,
            lastVerifiedAt: null,
            readinessStage: ReadinessStage::SOURCE_PROFILE_REQUIRED,
            readinessScore: 55,
            blockers: [$this->issue('feed_profile_missing', ValidationSeverity::BLOCKER)],
            warnings: [],
            nextSafeAction: 'define_feed_profile',
            factsSource: ['database_metadata'],
            evidence: [],
            requiresProductionReadOnlyAudit: true,
        );
    }

    private function row(): SupplierReadinessMatrixRow
    {
        return new SupplierReadinessMatrixRow(
            supplierKey: 'fixture-supplier',
            supplierName: 'Fixture Supplier',
            supplierSlug: 'fixture-supplier',
            active: true,
            importEnabled: true,
            scheduleEnabled: false,
            scheduleType: 'manual_only',
            sourceFormat: 'xml',
            sourceConfigured: true,
            authenticationRequired: null,
            authenticationConfigured: null,
            driverKey: 'legacy-xml-staging',
            driverAvailable: true,
            driverContractVersion: 'supplier-feed-driver-v1',
            feedProfileKey: null,
            feedProfileVersion: null,
            feedProfileAvailable: false,
            capabilityAuditAvailable: true,
            previewCapability: false,
            controlledStagingCapability: false,
            postApplyVerificationCapability: false,
            mappingReviewCapability: true,
            manualCreateCapability: false,
            stagingRowCount: 10,
            linkedStagingRowCount: 2,
            unlinkedStagingRowCount: 8,
            categoryMappingCount: 0,
            canonicalFamilyCount: 0,
            lastImportAt: null,
            lastPreviewAt: null,
            lastVerifiedAt: null,
            readinessStage: ReadinessStage::SOURCE_PROFILE_REQUIRED,
            readinessScore: 55,
            blockers: [$this->issue('feed_profile_missing', ValidationSeverity::BLOCKER)],
            warnings: [$this->issue('authentication_unknown', ValidationSeverity::WARNING)],
            nextSafeAction: 'define_feed_profile',
            factsSource: ['onboarding_contracts', 'database_metadata'],
            evidence: ['driver_contract_available' => true],
            requiresProductionReadOnlyAudit: true,
        );
    }

    private function issue(string $code, ValidationSeverity $severity): ValidationIssue
    {
        return new ValidationIssue(
            code: $code,
            severity: $severity,
            messageKey: 'supplier_onboarding.readiness.'.$code,
            blocking: $severity === ValidationSeverity::BLOCKER,
        );
    }
}
