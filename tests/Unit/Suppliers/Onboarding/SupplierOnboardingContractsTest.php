<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\CandidateFingerprintInterface;
use App\Contracts\Suppliers\Onboarding\SupplierFeedDriverInterface;
use App\Data\Suppliers\Onboarding\AvailabilityNormalizationResult;
use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use App\Data\Suppliers\Onboarding\DriverInspection;
use App\Data\Suppliers\Onboarding\NormalizedSupplierRecord;
use App\Data\Suppliers\Onboarding\PostApplyVerificationResult;
use App\Data\Suppliers\Onboarding\PreviewClassification;
use App\Data\Suppliers\Onboarding\PreviewReport;
use App\Data\Suppliers\Onboarding\SourceFingerprint;
use App\Data\Suppliers\Onboarding\StagingPlan;
use App\Data\Suppliers\Onboarding\SupplierFeedProfile;
use App\Data\Suppliers\Onboarding\SupplierFeedSource;
use App\Data\Suppliers\Onboarding\ValidationIssue;
use App\Data\Suppliers\Onboarding\ValidationSeverity;
use App\Data\Suppliers\Onboarding\VerificationVerdict;
use App\Services\Suppliers\Onboarding\AvailabilityNormalizationService;
use App\Services\Suppliers\Onboarding\CandidateFingerprintService;
use App\Services\Suppliers\Onboarding\PriceNormalizationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SupplierOnboardingContractsTest extends TestCase
{
    public function test_canonical_serialization_and_candidate_fingerprint_are_deterministic(): void
    {
        $first = $this->record('SKU-001', 'Product one');
        $second = $this->record('SKU-002', 'Product two');
        $service = new CandidateFingerprintService;

        $left = $service->fingerprint([$first, $second]);
        $right = $service->fingerprint([$second, $first]);

        $this->assertSame($left, $right);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $left);
        $this->assertSame('supplier-feed-driver-v1', SupplierFeedDriverInterface::CONTRACT_VERSION);
        $this->assertSame('supplier-feed-profile-v1', SupplierFeedProfile::CONTRACT_VERSION);
        $this->assertSame('supplier-candidate-fingerprint-v1', CandidateFingerprintInterface::CONTRACT_VERSION);
        $this->assertSame(
            CanonicalOnboardingData::encode($first->toCanonicalArray()),
            CanonicalOnboardingData::encode($first->toCanonicalArray())
        );
    }

    public function test_source_serialization_uses_fingerprint_and_does_not_expose_local_path_or_secrets(): void
    {
        $source = SupplierFeedSource::localFile(
            logicalSourceName: 'fixture-feed',
            path: 'tests/Fixtures/supplier.xml',
            expectedFingerprint: SourceFingerprint::sha256(str_repeat('a', 64), 'fixture-feed'),
            safeMetadata: ['format' => 'xml']
        );

        $serialized = $source->toArray();

        $this->assertArrayNotHasKey('local_path_or_stream', $serialized);
        $this->assertSame(str_repeat('a', 64), $serialized['expected_fingerprint']['digest']);
        $this->assertSame('supplier-feed-source-v1', SupplierFeedSource::SCHEMA_VERSION);
        $this->assertThrows(fn (): SupplierFeedSource => SupplierFeedSource::localFile(
            'unsafe-feed',
            'tests/Fixtures/feed.xml',
            safeMetadata: ['password' => 'do-not-store']
        ));
        $this->assertThrows(fn (): SupplierFeedSource => SupplierFeedSource::localFile(
            'remote-feed',
            'https://example.invalid/feed.xml'
        ));
    }

    public function test_price_and_availability_normalization_are_pure_and_explicit(): void
    {
        $price = (new PriceNormalizationService)->normalize('0012.30', 'eur');

        $this->assertTrue($price->valid);
        $this->assertSame('12.30', $price->normalizedPrice);
        $this->assertSame('EUR', $price->sourceCurrency);

        $negative = (new PriceNormalizationService)->normalize('-1', 'EUR');
        $this->assertFalse($negative->valid);
        $this->assertTrue($negative->negativeDetected);

        $availability = (new AvailabilityNormalizationService)->normalize(
            externalCode: 'supplier-low',
            externalLabel: 'Low stock',
            quantity: 2,
            mapping: ['supplier-low' => 'limited_stock'],
            profileVersion: 'profile-v1'
        );

        $this->assertInstanceOf(AvailabilityNormalizationResult::class, $availability);
        $this->assertSame('limited_stock', $availability->normalizedKey);
        $this->assertSame('explicit', $availability->mappingConfidence);

        $quantityFallback = (new AvailabilityNormalizationService)->normalize(null, null, 0);
        $this->assertSame('out_of_stock', $quantityFallback->normalizedKey);
        $this->assertSame('quantity', $quantityFallback->mappingConfidence);
    }

    public function test_preview_report_staging_plan_and_verification_are_read_only_structures(): void
    {
        $issue = new ValidationIssue(
            code: 'missing_price',
            severity: ValidationSeverity::WARNING,
            field: 'supplier_price_raw',
            messageKey: 'supplier_onboarding.missing_price',
            safeContext: ['record' => 'SKU-002']
        );
        $fingerprint = SourceFingerprint::sha256(str_repeat('b', 64), 'fixture-feed');

        $report = new PreviewReport(
            totalSourceRecords: 2,
            normalizedRecordCount: 2,
            validRecordCount: 1,
            rejectedRecordCount: 1,
            classificationCounts: [
                PreviewClassification::READY_TO_CREATE->value => 1,
                PreviewClassification::READY_WITH_WARNING->value => 1,
            ],
            issueCounts: ['missing_price' => 1],
            issueSamples: [$issue],
            sourceFingerprints: [$fingerprint],
            candidateFingerprint: str_repeat('c', 64),
            profileVersion: 'profile-v1',
            driverVersion: 'fake-v1',
        );
        $reportArray = $report->toArray();

        $this->assertTrue($reportArray['read_only']);
        $this->assertSame(0, $reportArray['products_changed']);
        $this->assertSame(0, $reportArray['supplier_products_changed']);
        $this->assertFalse($reportArray['catalog_sync_called']);
        $this->assertSame('warning', $reportArray['issue_samples'][0]['severity']);

        $plan = StagingPlan::createOnly(
            supplierKey: 'fixture-supplier',
            sourceFingerprints: [$fingerprint],
            candidateFingerprint: str_repeat('c', 64),
            candidateCount: 1,
            expectedCurrentStagedCount: 0,
            plannedInsertCount: 1,
            warnings: [$issue]
        );

        $this->assertSame('supplier_products-only', $plan->toArray()['write_scope']);
        $this->assertSame(0, $plan->toArray()['planned_update_count']);
        $this->assertFalse(method_exists($plan, 'apply'));

        $verification = new PostApplyVerificationResult(
            sourceFingerprintMatch: true,
            candidateFingerprintMatch: true,
            candidateCountMatch: true,
            exactSkuReconciliation: true,
            canonicalRowReconciliation: true,
            provenanceVerification: true,
            pricingVerification: true,
            availabilityVerification: true,
            truncationSchemaVerification: true,
            verdict: VerificationVerdict::VERIFIED,
        );

        $this->assertTrue($verification->isVerified());
        $this->assertTrue($verification->toArray()['read_only']);
        $this->assertSame(0, $verification->toArray()['records_changed']['products']);
    }

    public function test_driver_contract_can_be_used_by_a_fake_without_runtime_wiring(): void
    {
        $driver = new class implements SupplierFeedDriverInterface
        {
            public function key(): string
            {
                return 'fake-local';
            }

            public function contractVersion(): string
            {
                return 'fake-v1';
            }

            public function supportedSourceFormats(): array
            {
                return ['xml'];
            }

            public function supports(SupplierFeedSource $source, SupplierFeedProfile $profile): bool
            {
                return $profile->driverKey === $this->key() && $source->sourceType === 'local_file';
            }

            public function inspect(SupplierFeedSource $source, SupplierFeedProfile $profile): DriverInspection
            {
                return new DriverInspection(true, 'xml', ['sku']);
            }

            public function records(SupplierFeedSource $source, SupplierFeedProfile $profile): iterable
            {
                yield new NormalizedSupplierRecord($profile->supplierKey, 'SKU-001');
            }

            public function diagnostics(): array
            {
                return ['mode' => 'test-only'];
            }
        };
        $source = SupplierFeedSource::localFile('fixture', 'tests/Fixtures/feed.xml');
        $profile = new SupplierFeedProfile('fixture-supplier', 'fixture-profile', 'v1', 'fake-local', 'xml', 'records');

        $this->assertTrue($driver->supports($source, $profile));
        $this->assertSame('SKU-001', iterator_to_array($driver->records($source, $profile))[0]->supplierSku);
    }

    public function test_onboarding_contracts_are_not_wired_to_writes_http_jobs_images_schedules_or_catalog_sync(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = array_merge(
            glob($root.'/app/Contracts/Suppliers/Onboarding/*.php') ?: [],
            glob($root.'/app/Data/Suppliers/Onboarding/*.php') ?: [],
            array_filter(
                glob($root.'/app/Services/Suppliers/Onboarding/*.php') ?: [],
                fn (string $path): bool => ! in_array(basename($path), [
                    'SupplierReadinessMatrixService.php',
                    // This phase deliberately audits existing staging through read-only queries.
                    'LegacySupplierStagingAuditService.php',
                    // This phase deliberately adds a separately guarded runtime schedule-only service.
                    'ControlledSupplierScheduleFreezeService.php',
                    // This phase deliberately reads safe aggregate state to produce a zero-write local plan.
                    'LocalSupplierSourceNormalizationPlanner.php',
                    // This phase deliberately reads import activity state for zero-write local audits.
                    'SupplierImportActivityInspector.php',
                    // This phase deliberately compares an explicit local source to staging without writes.
                    'LocalSupplierSourceStagingReconciler.php',
                ], true),
            ),
        );

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $contents = (string) file_get_contents($path);

            $this->assertStringNotContainsString('Illuminate\\Database', $contents, $path);
            $this->assertStringNotContainsString('Illuminate\\Http', $contents, $path);
            $this->assertStringNotContainsString('Illuminate\\Queue', $contents, $path);
            $this->assertStringNotContainsString('CatalogSync', $contents, $path);
            $this->assertStringNotContainsString('dispatch(', $contents, $path);
            $this->assertStringNotContainsString('Storage::', $contents, $path);
        }
    }

    public function test_apcom_deterministic_audit_closeout_and_local_planner_document_critical_safety_facts(): void
    {
        $root = dirname(__DIR__, 4);
        $closeoutPath = $root.'/docs/APCOM_DETERMINISTIC_AUDIT_CLOSEOUT.md';
        $phasesPath = $root.'/docs/PHASES.md';
        $catalogSafetyPath = $root.'/docs/CATALOG_SYNC_SAFETY.md';
        $planPath = $root.'/docs/APCOM_LOCAL_SOURCE_NORMALIZATION_PLAN.md';
        $reconciliationPath = $root.'/docs/APCOM_OFFICIAL_FIELD_SEMANTICS_RECONCILIATION.md';

        $this->assertFileExists($closeoutPath);
        $this->assertFileExists($phasesPath);
        $this->assertFileExists($catalogSafetyPath);
        $this->assertFileExists($planPath);
        $this->assertFileExists($reconciliationPath);

        $closeout = (string) file_get_contents($closeoutPath);
        $phases = (string) file_get_contents($phasesPath);
        $catalogSafety = (string) file_get_contents($catalogSafetyPath);
        $plan = (string) file_get_contents($planPath);
        $reconciliation = (string) file_get_contents($reconciliationPath);

        foreach ([
            'APCOM is Supplier #1',
            'ASBIS is Supplier #2',
            'Supplier #3 remains unselected',
            'schedule_enabled=false',
            'APCOM `import_enabled` remains `true`',
            'APCOM_DETERMINISTIC_AUDIT_COMPARISON_PASSED',
            'legacy_state_requires_review',
            'blockers: none',
            'staging_present_without_verification',
            'historical_causation_unknown',
            'total rows: `1872`',
            'linked: `989`',
            'unlinked: `883`',
            'No automatic unfreeze exists',
            'Phase 9C.6.5C.3 - APCOM Local Source Profile and Normalization Plan',
            'authorized local C.3 profile has since run without writes',
            'Phase 9C.6.5C.3A tooling is implemented locally and in review',
        ] as $fact) {
            $this->assertStringContainsString($fact, $closeout, $fact);
        }

        $this->assertStringContainsString('Phase 9C.6.5C.1', $phases);
        $this->assertStringContainsString('Phase 9C.6.5C.2', $phases);
        $this->assertStringContainsString('Phase 9C.6.5C.3', $phases);
        $this->assertStringContainsString('Phase 9C.6.5C.3A', $phases);
        $this->assertStringContainsString('UPDATE disabled', $catalogSafety);
        $this->assertStringContainsString('Sync All disabled', $catalogSafety);
        $this->assertStringContainsString('automatic sync disabled', $catalogSafety);
        $this->assertStringContainsString('No Catalog Sync', $catalogSafety);
        $this->assertStringContainsString('suppliers:plan-local-source-normalization', $catalogSafety);
        $this->assertStringContainsString('suppliers:reconcile-local-source-staging', $catalogSafety);
        $this->assertStringContainsString('strictly read-only', $plan);
        $this->assertStringContainsString('authorized local C.3 profiler run has completed', $plan);
        $this->assertStringContainsString('`--apply` mode is', $plan);
        $this->assertStringContainsString('apcom-official-v1', $reconciliation);
        $this->assertStringContainsString('`stock` is never interpreted as quantity', $reconciliation);
        $this->assertStringContainsString('No C.3A operational', $reconciliation);
    }

    private function record(string $sku, string $name): NormalizedSupplierRecord
    {
        return new NormalizedSupplierRecord(
            supplierKey: 'fixture-supplier',
            supplierSku: $sku,
            name: $name,
            normalizedPrice: '12.30',
            currency: 'eur',
            quantity: 4,
        );
    }

    private function assertThrows(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
