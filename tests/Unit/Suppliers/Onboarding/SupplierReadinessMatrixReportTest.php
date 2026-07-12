<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\ReadinessStage;
use App\Data\Suppliers\Onboarding\ReadinessVerdict;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixReport;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixRow;
use PHPUnit\Framework\TestCase;

class SupplierReadinessMatrixReportTest extends TestCase
{
    public function test_report_serializes_a_read_only_machine_readable_contract(): void
    {
        $report = new SupplierReadinessMatrixReport(
            generatedAt: '2026-07-12T12:00:00.000000Z',
            supplierCount: 1,
            activeSupplierCount: 1,
            disabledSupplierCount: 0,
            importEnabledCount: 1,
            scheduleEnabledCount: 0,
            sourceConfiguredCount: 1,
            authenticationConfiguredCount: 0,
            driverAvailableCount: 1,
            profileAvailableCount: 0,
            previewCapableCount: 0,
            controlledStagingCapableCount: 0,
            postApplyVerificationCapableCount: 0,
            suppliersWithStagingCount: 0,
            suppliersWithLinkedProductsCount: 0,
            totalStagingRows: 0,
            totalLinkedStagingRows: 0,
            globalSafetyFlags: [
                'catalog_sync_create_enabled' => true,
                'catalog_sync_update_enabled' => false,
                'catalog_sync_sync_all_enabled' => false,
                'catalog_sync_auto_enabled' => false,
            ],
            readinessStageCounts: ['source_profile_required' => 1],
            blockerCounts: ['feed_profile_missing' => 1],
            warningCounts: ['production_fact_unknown' => 1],
            suppliers: [$this->row()],
            recordsChanged: [],
            issues: [],
            elapsedSeconds: 0.123456,
            peakMemoryBytes: 1024,
            matrixVerdict: ReadinessVerdict::INCOMPLETE_INFORMATION,
        );
        $payload = $report->toArray();

        $this->assertSame('supplier-readiness-matrix-v1', $payload['schema_version']);
        $this->assertSame('multi_supplier_readiness_matrix', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('incomplete_information', $payload['matrix_verdict']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['catalog_sync']);
        $this->assertTrue($report->isSafeConfiguration());
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
            stagingRowCount: 0,
            linkedStagingRowCount: 0,
            unlinkedStagingRowCount: 0,
            categoryMappingCount: 0,
            canonicalFamilyCount: 0,
            lastImportAt: null,
            lastPreviewAt: null,
            lastVerifiedAt: null,
            readinessStage: ReadinessStage::SOURCE_PROFILE_REQUIRED,
            readinessScore: 55,
            blockers: [],
            warnings: [],
            nextSafeAction: 'define_feed_profile',
            factsSource: ['database_metadata'],
            evidence: [],
            requiresProductionReadOnlyAudit: true,
        );
    }
}
