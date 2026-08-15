<?php

namespace Tests\Feature;

use App\Data\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundle;
use App\Data\Suppliers\Onboarding\OperationalSupplierOfferLifecyclePreviewReport;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use App\Services\Imports\XmlImportEngine;
use App\Services\Security\SsrfProtectionService;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundleReader;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferIdentityHasher;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferLifecyclePreviewService;
use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ExactSyntheticFeedSsrfProtectionService;
use Tests\TestCase;

final class OperationalSupplierOfferLifecyclePreviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_command_contract_is_apcom_only_and_exposes_no_mutation_control(): void
    {
        $command = Artisan::all()['suppliers:preview-apcom-offer-lifecycle'];
        $definition = $command->getDefinition();

        foreach (['supplier', 'evidence', 'expected-sha256', 'evaluated-at', 'format', 'limit'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }
        foreach (['apply', 'persist', 'import', 'sync', 'sync-all', 'create', 'update', 'link', 'unlink', 'schedule', 'cleanup', 'fetch', 'download'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
        $this->assertStringContainsString('CLI-only', (string) $command->getDescription());
        $this->assertStringContainsString('read-only', (string) $command->getDescription());
    }

    public function test_three_qualified_absences_and_48_hours_emit_preview_only_deactivation_and_day_zero_unavailability(): void
    {
        $supplier = $this->supplier('apcom');
        [$product, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-MISSING');
        $times = ['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-03T00:00:00+00:00'];
        $snapshots = $this->snapshots('apcom', $skuHash, $times, present: false);
        $evaluatedAt = CarbonImmutable::parse($times[2]);
        $evidence = $this->evidence($snapshots, productLifecycleEvidence: [[
            'product_reference_hash' => $this->hasher()->product($product->id),
            'continuous_qualified_absence_proven' => true,
            'zero_active_offers_since' => $times[2],
        ]]);

        $before = $this->protectedCounts();
        $payload = $this->preview($evidence, $evaluatedAt);

        $this->assertSame(3, $payload['counts']['qualified_snapshot_count']);
        $this->assertSame(1, $payload['counts']['confirmed_missing_count']);
        $this->assertSame(1, $payload['recommendation_counts']['would_deactivate_offer']);
        $this->assertSame(1, $payload['recommendation_counts']['would_mark_unavailable']);
        $this->assertSame('inactive_missing_from_feed', $payload['bounded_samples']['offer_evaluations'][0]['presence_status']);
        $this->assertSame('would_mark_unavailable', $payload['bounded_samples']['product_recommendations'][0]['recommendation']);
        $this->assertSame(200, $payload['bounded_samples']['product_recommendations'][0]['visibility_preview']['direct_page_http_status']);
        $this->assertFalse($payload['bounded_samples']['product_recommendations'][0]['delete_allowed']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());
    }

    public function test_one_two_and_early_third_absence_are_not_deactivation_eligible(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-EARLY');
        $cases = [
            [['2026-08-01T00:00:00+00:00'], 1, 'missing_once'],
            [['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00'], 2, 'missing_repeatedly'],
            [['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-02T23:00:00+00:00'], 3, 'missing_threshold_reached_waiting_duration'],
        ];

        foreach ($cases as [$times, $count, $status]) {
            $payload = $this->preview($this->evidence($this->snapshots('apcom', $skuHash, $times, present: false)), CarbonImmutable::parse(end($times)));
            $row = $payload['bounded_samples']['offer_evaluations'][0];
            $this->assertSame($count, $row['consecutive_missing_count']);
            $this->assertSame($status, $row['presence_status']);
            $this->assertSame(0, $payload['counts']['confirmed_missing_count']);
            $this->assertArrayNotHasKey('would_deactivate_offer', $payload['recommendation_counts']);
            $this->assertStringNotContainsString('eol', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    public function test_failed_and_duplicate_snapshots_freeze_without_increment_or_reset(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-FROZEN');
        $times = ['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-03T00:00:00+00:00'];
        $snapshots = $this->snapshots('apcom', $skuHash, $times, present: false);
        $snapshots[1]['successful'] = false;
        $snapshots[1]['status'] = 'failed';
        $snapshots[2]['fingerprint'] = $snapshots[0]['fingerprint'];

        $payload = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[2]));

        $this->assertSame(2, $payload['counts']['frozen_snapshot_count']);
        $this->assertSame(1, $payload['bounded_samples']['offer_evaluations'][0]['consecutive_missing_count']);
        $this->assertSame(0, $payload['counts']['confirmed_missing_count']);
        $this->assertSame(1, $payload['reason_code_counts']['snapshot_not_successful']);
        $this->assertGreaterThanOrEqual(1, $payload['reason_code_counts']['duplicate_snapshot_fingerprint']);
    }

    public function test_apcom_freshness_is_inclusive_at_24_hours_and_stale_after_it_without_missing_increment(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-FRESHNESS');
        $captured = '2026-08-01T00:00:00+00:00';
        $evidence = $this->evidence($this->snapshots('apcom', $skuHash, [$captured], present: true));

        $fresh = $this->preview($evidence, CarbonImmutable::parse('2026-08-02T00:00:00+00:00'));
        $stale = $this->preview($evidence, CarbonImmutable::parse('2026-08-02T00:00:01+00:00'));

        $this->assertSame(0, $fresh['counts']['stale_offer_count']);
        $this->assertSame('keep_active', $fresh['bounded_samples']['offer_evaluations'][0]['recommendation']);
        $this->assertSame(1, $stale['counts']['stale_offer_count']);
        $this->assertSame(0, $stale['bounded_samples']['offer_evaluations'][0]['consecutive_missing_count']);
        $this->assertSame('manual_review', $stale['bounded_samples']['product_recommendations'][0]['recommendation']);
        $this->assertNull($stale['bounded_samples']['product_recommendations'][0]['visibility_preview']);
    }

    public function test_other_supplier_requires_its_own_freshness_policy_and_valid_alternative_keeps_product_active(): void
    {
        $apcom = $this->supplier('apcom');
        $backup = $this->supplier('backup-supplier');
        [$product, $apcomHash] = $this->linkedOffer($apcom, 'SYNTHETIC-APCOM-ALT');
        [, $backupHash] = $this->linkedOffer($backup, 'SYNTHETIC-BACKUP-ALT', $product);
        $times = ['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-03T00:00:00+00:00'];
        $snapshots = $this->interleavedSnapshots($apcomHash, $backupHash, $times);
        $scope = ['apcom', 'backup-supplier'];
        $withoutPolicy = $this->evidence($snapshots, $scope);

        $manual = $this->preview($withoutPolicy, CarbonImmutable::parse($times[2]));
        $this->assertSame('manual_review', $manual['bounded_samples']['product_recommendations'][0]['recommendation']);
        $this->assertArrayHasKey('missing_supplier_freshness_policy', $manual['reason_code_counts']);

        $freshness = [...$this->apcomFreshness(), [
            'supplier' => 'backup-supplier',
            'policy_key' => 'backup-supplier-approved-freshness-v1',
            'max_age_hours' => 48,
            'approved' => true,
        ]];
        $snapshots[3]['authoritative_snapshot_at'] = CarbonImmutable::parse($times[2])->subHours(36)->toAtomString();
        $withPolicy = $this->evidence($snapshots, $scope, $freshness);
        $active = $this->preview($withPolicy, CarbonImmutable::parse($times[2]));

        $this->assertSame(1, $active['counts']['active_alternative_count']);
        $backupRow = collect($active['bounded_samples']['offer_evaluations'])->firstWhere('supplier', 'backup-supplier');
        $this->assertFalse($backupRow['stale']);
        $this->assertSame('keep_active', $active['bounded_samples']['product_recommendations'][0]['recommendation']);
        $this->assertSame(1, $active['recommendation_counts']['would_deactivate_offer']);
        $this->assertSame(0, $active['records_changed']['products']);
    }

    public function test_alternative_source_identity_drift_is_rejected_before_keep_active_without_side_effects(): void
    {
        $apcom = $this->supplier('apcom');
        $backup = $this->supplier('backup-supplier');
        [$product, $apcomHash] = $this->linkedOffer($apcom, 'SYNTHETIC-APCOM-ALT-DRIFT');
        [, $backupHash] = $this->linkedOffer($backup, 'SYNTHETIC-BACKUP-ALT-DRIFT', $product);
        $times = ['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-03T00:00:00+00:00'];
        $snapshots = [
            ...$this->snapshots('apcom', $apcomHash, $times, present: false),
            ...$this->snapshots('backup-supplier', $backupHash, [
                '2026-08-01T12:00:00+00:00',
                '2026-08-02T12:00:00+00:00',
            ], present: true),
        ];
        $snapshots[3]['source_identity'] = 'synthetic-backup-identity-v1';
        $snapshots[4]['source_identity'] = 'SYNTHETIC-BACKUP-IDENTITY-V1 ';
        usort($snapshots, static fn (array $left, array $right): int => $left['captured_at'] <=> $right['captured_at']);
        $freshness = [...$this->apcomFreshness(), [
            'supplier' => 'backup-supplier',
            'policy_key' => 'backup-supplier-approved-freshness-v1',
            'max_age_hours' => 48,
            'approved' => true,
        ]];
        $evidence = $this->evidence($snapshots, ['apcom', 'backup-supplier'], $freshness);
        $before = $this->protectedCounts();
        Bus::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $report = null;

        try {
            $report = $this->previewReport($evidence, CarbonImmutable::parse($times[2]));
            $this->fail('Expected alternative source identity drift to reject the complete bundle.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('source_identity_mismatch', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-backup-identity-v1', $exception->getMessage());
            $this->assertStringNotContainsString('SYNTHETIC-BACKUP-IDENTITY-V1 ', $exception->getMessage());
        }

        $this->assertNull($report);
        $this->assertSame($before, $this->protectedCounts());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_invalid_direct_bundle_cannot_reach_lifecycle_evaluation_or_produce_side_effects(): void
    {
        $path = base_path('tests/Fixtures/Suppliers/apcom_offer_lifecycle/operational-evidence-v1.json');
        $valid = app(OperationalSupplierOfferEvidenceBundleReader::class)->read($path, hash_file('sha256', $path));
        $snapshots = $valid->snapshots;
        $snapshots[0]['source_identity'] = "\xC3\x28";
        $before = $this->protectedCounts();
        $writes = [];
        $previewReached = false;
        $report = null;
        Bus::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        Cache::spy();
        Storage::fake('local');
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        try {
            $bundle = new OperationalSupplierOfferEvidenceBundle(
                evidenceFingerprint: $valid->evidenceFingerprint,
                supplierKey: $valid->supplierKey,
                supplierScope: $valid->supplierScope,
                policyVersions: $valid->policyVersions,
                sourceIdentity: "\xC3\x28",
                freshnessPolicies: $valid->freshnessPolicies,
                snapshots: $snapshots,
                productLifecycleEvidence: $valid->productLifecycleEvidence,
            );
            $previewReached = true;
            $report = app(OperationalSupplierOfferLifecyclePreviewService::class)->preview(
                $bundle,
                CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
                10,
            );
            $this->fail('Expected invalid direct source identity to fail before evaluation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid_source_identity', $exception->getMessage());
        }

        $this->assertFalse($previewReached);
        $this->assertNull($report);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->protectedCounts());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Cache::shouldNotHaveReceived('put');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_zero_price_is_present_manual_review_and_never_starts_missing_or_archival(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-ZERO');
        $snapshot = $this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: true, price: '0')[0];
        $payload = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse('2026-08-01T00:00:00+00:00'));

        $offer = $payload['bounded_samples']['offer_evaluations'][0];
        $product = $payload['bounded_samples']['product_recommendations'][0];
        $this->assertTrue($offer['zero_price']);
        $this->assertSame(0, $offer['consecutive_missing_count']);
        $this->assertSame('manual_review', $offer['recommendation']);
        $this->assertSame('manual_review', $product['recommendation']);
        $this->assertNull($product['visibility_preview']);
        $this->assertStringNotContainsString('selling_price', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_present_out_of_stock_and_duplicate_offer_remain_separate_review_states(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-OUT');
        $snapshot = $this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: true)[0];
        $snapshot['observations'][0]['raw_quantity_observed'] = 0;
        $snapshot['observations'][0]['eol_flag'] = 1;

        $payload = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse('2026-08-01T00:00:00+00:00'));
        $offer = $payload['bounded_samples']['offer_evaluations'][0];
        $product = $payload['bounded_samples']['product_recommendations'][0];

        $this->assertSame('present', $offer['classification']);
        $this->assertSame('discontinued', $offer['canonical_public_status']);
        $this->assertSame(0, $offer['consecutive_missing_count']);
        $this->assertSame('manual_review', $offer['recommendation']);
        $this->assertSame('manual_review', $product['recommendation']);
        $this->assertNull($product['visibility_preview']);

        $snapshot['observations'][0]['duplicate_offer'] = true;
        $duplicate = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse('2026-08-01T00:00:00+00:00'));
        $this->assertContains('duplicate_offer', $duplicate['bounded_samples']['offer_evaluations'][0]['reason_codes']);
    }

    public function test_valid_reappearance_resets_preview_while_zero_price_and_conflicts_remain_review_only(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-RETURN');
        $times = ['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-03T00:00:00+00:00', '2026-08-04T00:00:00+00:00'];
        $snapshots = $this->snapshots('apcom', $skuHash, array_slice($times, 0, 3), present: false);
        $snapshots[] = $this->snapshots('apcom', $skuHash, [$times[3]], present: true, startIndex: 4)[0];
        $valid = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));

        $this->assertSame(1, $valid['counts']['reappearance_count']);
        $this->assertSame('would_reactivate_offer', $valid['bounded_samples']['offer_evaluations'][0]['recommendation']);
        $this->assertSame(0, $valid['bounded_samples']['offer_evaluations'][0]['consecutive_missing_count']);

        $snapshots[3]['observations'][0]['price'] = '0';
        $zero = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));
        $zeroRow = $zero['bounded_samples']['offer_evaluations'][0];
        $this->assertSame(0, $zero['counts']['reappearance_count']);
        $this->assertSame(0, $zero['counts']['confirmed_missing_count']);
        $this->assertSame(1, $zero['counts']['zero_price_manual_review_count']);
        $this->assertSame('present', $zeroRow['presence_status']);
        $this->assertSame(0, $zeroRow['consecutive_missing_count']);
        $this->assertNull($zeroRow['first_missing_at']);
        $this->assertSame('manual_review', $zeroRow['recommendation']);
        $this->assertArrayNotHasKey('would_deactivate_offer', $zero['recommendation_counts']);
        $this->assertArrayNotHasKey('would_reactivate_offer', $zero['recommendation_counts']);
        $this->assertNull($zero['bounded_samples']['product_recommendations'][0]['visibility_preview']);

        $snapshots[3]['observations'][0]['price'] = null;
        $missingPrice = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));
        $missingPriceRow = $missingPrice['bounded_samples']['offer_evaluations'][0];
        $this->assertSame('present', $missingPriceRow['presence_status']);
        $this->assertSame(0, $missingPriceRow['consecutive_missing_count']);
        $this->assertNull($missingPriceRow['first_missing_at']);
        $this->assertSame('manual_review', $missingPriceRow['recommendation']);

        $snapshots[3]['observations'][0]['price'] = '100';
        $snapshots[3]['observations'][0]['raw_quantity_observed'] = 0;
        $snapshots[3]['observations'][0]['eol_flag'] = 1;
        $outOfStock = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));
        $outOfStockRow = $outOfStock['bounded_samples']['offer_evaluations'][0];
        $this->assertSame('present', $outOfStockRow['presence_status']);
        $this->assertSame(0, $outOfStockRow['consecutive_missing_count']);
        $this->assertNull($outOfStockRow['first_missing_at']);

        $snapshots[3]['observations'][0]['price'] = '100';
        $snapshots[3]['observations'][0]['raw_quantity_observed'] = 6;
        $snapshots[3]['observations'][0]['eol_flag'] = 0;
        $snapshots[3]['observations'][0]['identifier_conflict'] = true;
        $conflict = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));
        $conflictRow = $conflict['bounded_samples']['offer_evaluations'][0];
        $this->assertContains('identifier_conflict', $conflictRow['reason_codes']);
        $this->assertSame('inactive_missing_from_feed', $conflictRow['presence_status']);
        $this->assertSame(3, $conflictRow['consecutive_missing_count']);
        $this->assertNotNull($conflictRow['first_missing_at']);
        $this->assertSame('manual_review', $conflictRow['recommendation']);

        $snapshots[3]['observations'][0]['identifier_conflict'] = false;
        $snapshots[3]['observations'][0]['exact_supplier_sku_match'] = false;
        $mismatch = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));
        $this->assertContains('supplier_sku_mismatch', $mismatch['bounded_samples']['offer_evaluations'][0]['reason_codes']);
        $this->assertSame('manual_review', $mismatch['bounded_samples']['offer_evaluations'][0]['recommendation']);
    }

    public function test_mismatched_sku_does_not_reset_confirmed_missing_state(): void
    {
        $this->assertUnsafeIdentityPreservesConfirmedMissingState(
            static function (array &$observation): void {
                $observation['exact_supplier_sku_match'] = false;
            },
            'supplier_sku_mismatch',
        );
    }

    public function test_identifier_conflict_does_not_reset_confirmed_missing_state(): void
    {
        $this->assertUnsafeIdentityPreservesConfirmedMissingState(
            static function (array &$observation): void {
                $observation['identifier_conflict'] = true;
            },
            'identifier_conflict',
        );
    }

    public function test_duplicate_offer_does_not_reset_confirmed_missing_state(): void
    {
        $this->assertUnsafeIdentityPreservesConfirmedMissingState(
            static function (array &$observation): void {
                $observation['duplicate_offer'] = true;
            },
            'duplicate_offer',
        );
    }

    public function test_mapper_validation_failure_does_not_reset_confirmed_missing_state(): void
    {
        $this->assertUnsafeIdentityPreservesConfirmedMissingState(
            static function (array &$observation): void {
                $observation['supplier_mapper_valid'] = false;
            },
            'supplier_mapper_validation_failed',
        );
    }

    public function test_blocking_validation_issue_does_not_reset_confirmed_missing_state(): void
    {
        $this->assertUnsafeIdentityPreservesConfirmedMissingState(
            static function (array &$observation): void {
                $observation['blocking_validation_issue'] = true;
            },
            'blocking_validation_issue',
        );
    }

    public function test_source_only_is_potential_create_with_no_mpn_inference_link_or_product_write(): void
    {
        $this->supplier('apcom');
        $hash = str_repeat('b', 64);
        $snapshot = $this->snapshots('apcom', $hash, ['2026-08-01T00:00:00+00:00'], present: true)[0];
        $beforeProducts = Product::query()->count();
        $payload = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse('2026-08-01T00:00:00+00:00'));
        $row = $payload['bounded_samples']['offer_evaluations'][0];

        $this->assertSame('potential_create', $row['classification']);
        $this->assertTrue($row['source_only']);
        $this->assertFalse($row['mpn_inferred']);
        $this->assertNull($row['product_reference_hash']);
        $this->assertSame(1, $payload['counts']['source_only_potential_create_count']);
        $this->assertSame($beforeProducts, Product::query()->count());
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['product_supplier_offers']);
    }

    public function test_visibility_day_59_day_60_and_month_24_are_planning_only(): void
    {
        $supplier = $this->supplier('apcom');
        [$product, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-VISIBILITY');
        $firstMissingAt = CarbonImmutable::parse('2024-01-01T00:00:00+00:00');
        $zeroSince = $firstMissingAt->addDays(2);
        $snapshots = $this->snapshots('apcom', $skuHash, [
            $firstMissingAt->toAtomString(),
            $firstMissingAt->addDay()->toAtomString(),
            $zeroSince->toAtomString(),
        ], present: false);
        $productEvidence = [[
            'product_reference_hash' => $this->hasher()->product($product->id),
            'continuous_qualified_absence_proven' => true,
            'zero_active_offers_since' => $zeroSince->toAtomString(),
        ]];

        foreach ([
            [59, 'would_mark_unavailable', 'unavailable_indexable'],
            [60, 'would_mark_archived_noindex', 'archived_noindex'],
        ] as [$days, $recommendation, $state]) {
            $payload = $this->preview($this->evidence($snapshots, productLifecycleEvidence: $productEvidence), $zeroSince->addDays($days));
            $row = $payload['bounded_samples']['product_recommendations'][0];
            $this->assertSame($recommendation, $row['recommendation']);
            $this->assertSame($state, $row['visibility_preview']['visibility_state']);
            $this->assertSame(200, $row['visibility_preview']['direct_page_http_status']);
            $this->assertFalse($row['delete_allowed']);
        }

        $cold = $this->preview($this->evidence($snapshots, productLifecycleEvidence: $productEvidence), $zeroSince->addMonthsNoOverflow(24));
        $row = $cold['bounded_samples']['product_recommendations'][0];
        $this->assertSame('would_mark_cold_archive_candidate', $row['recommendation']);
        $this->assertSame('cold_archive_candidate', $row['visibility_preview']['visibility_state']);
        $this->assertSame('noindex, follow', $row['visibility_preview']['robots_directive']);
        $this->assertFalse($row['visibility_preview']['sitemap_allowed']);
    }

    public function test_database_timestamps_cannot_prove_continuous_absence_and_incomplete_catalog_evidence_aborts(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-NO-HISTORY');
        ProductSupplierOffer::query()->update(['last_seen_at' => '2020-01-01 00:00:00']);
        SupplierProduct::query()->update(['received_at' => '2020-01-01 00:00:00']);
        $times = ['2026-08-01T00:00:00+00:00', '2026-08-02T00:00:00+00:00', '2026-08-03T00:00:00+00:00'];
        $evidence = $this->evidence($this->snapshots('apcom', $skuHash, $times, present: false));
        $payload = $this->preview($evidence, CarbonImmutable::parse($times[2]));

        $this->assertSame('manual_review', $payload['bounded_samples']['product_recommendations'][0]['recommendation']);
        $this->assertContains('unprovable_continuous_absence', $payload['bounded_samples']['product_recommendations'][0]['reason_codes']);
        $this->assertNull($payload['bounded_samples']['product_recommendations'][0]['visibility_preview']);

        $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-UNOBSERVED');
        $this->assertFailsWith(
            fn () => $this->preview($evidence, CarbonImmutable::parse($times[2])),
            'incomplete_offer_presence_observations',
        );
    }

    public function test_manual_override_manual_products_non_public_workflow_and_unprovable_absence_fail_closed(): void
    {
        $supplier = $this->supplier('apcom');
        [$product, $hash] = $this->linkedOffer($supplier, 'SYNTHETIC-PROTECTED');
        foreach ([
            ['manual_override' => true, 'source' => Product::SOURCE_SUPPLIER_IMPORT, 'workflow_status' => Product::WORKFLOW_PUBLISHED, 'reason' => 'manual_override'],
            ['manual_override' => false, 'source' => Product::SOURCE_MANUAL, 'workflow_status' => Product::WORKFLOW_PUBLISHED, 'reason' => 'manual_product_excluded'],
            ['manual_override' => false, 'source' => Product::SOURCE_SUPPLIER_IMPORT, 'workflow_status' => Product::WORKFLOW_DRAFT, 'reason' => 'non_public_workflow_state'],
        ] as $case) {
            $reason = $case['reason'];
            unset($case['reason']);
            $product->forceFill($case)->save();
            $payload = $this->preview($this->evidence($this->snapshots('apcom', $hash, ['2026-08-01T00:00:00+00:00'], present: true)), CarbonImmutable::parse('2026-08-01T00:00:00+00:00'));
            $row = collect($payload['bounded_samples']['product_recommendations'])->firstWhere('product_reference_hash', $this->hasher()->product($product->id));
            $this->assertSame('manual_review', $row['recommendation']);
            $this->assertContains($reason, $row['reason_codes']);
        }
    }

    public function test_canonical_json_is_deterministic_and_catalog_fingerprint_tracks_relevant_read_only_state(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash, $offer] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-DETERMINISTIC');
        $evidence = $this->evidence($this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: true));
        $at = CarbonImmutable::parse('2026-08-01T00:00:00+00:00');
        $first = $this->previewReport($evidence, $at);
        $second = $this->previewReport($evidence, $at);

        $this->assertSame($first->canonicalJson(), $second->canonicalJson());
        $firstFingerprint = $first->toArray()['catalog_state_fingerprint'];
        $offer->forceFill(['quantity' => 7])->save();
        $changed = $this->previewReport($evidence, $at)->toArray();
        $this->assertNotSame($firstFingerprint, $changed['catalog_state_fingerprint']);
    }

    public function test_catalog_fingerprint_is_stable_for_shuffled_supplier_rows_and_duplicate_labels(): void
    {
        $apcom = $this->supplier('apcom');
        $backup = $this->supplier('backup-supplier');
        $apcom->forceFill(['company_name' => 'Equivalent label'])->save();
        $backup->forceFill(['company_name' => 'Equivalent label'])->save();
        $this->linkedOffer($apcom, 'SYNTHETIC-ORDER-APCOM');
        $this->linkedOffer($backup, 'SYNTHETIC-ORDER-BACKUP');

        $method = new ReflectionMethod(OperationalSupplierOfferLifecyclePreviewService::class, 'catalogState');
        $service = app(OperationalSupplierOfferLifecyclePreviewService::class);
        $forward = collect(['apcom' => $apcom, 'backup-supplier' => $backup]);
        $reverse = collect(['backup-supplier' => $backup, 'apcom' => $apcom]);

        $first = $method->invoke($service, $forward);
        $second = $method->invoke($service, $reverse);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);

        $backup->forceFill(['status' => 'disabled'])->save();
        $changed = $method->invoke($service, $reverse);
        $this->assertNotSame($first['fingerprint'], $changed['fingerprint']);
    }

    public function test_exact_product_drop_boundary_qualifies_and_fraction_above_freezes(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-EXACT-DROP');
        $snapshot = $this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: true)[0];
        $snapshot['product_drop_percent'] = '40';
        $boundary = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse($snapshot['captured_at']));
        $this->assertSame(1, $boundary['counts']['qualified_snapshot_count']);

        $snapshot['product_drop_percent'] = '40.000001';
        $above = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse($snapshot['captured_at']));
        $this->assertSame(1, $above['counts']['frozen_snapshot_count']);
        $this->assertGreaterThanOrEqual(1, $above['reason_code_counts']['maximum_product_drop_exceeded']);
    }

    public function test_real_importer_generation_change_during_evaluation_aborts_without_a_valid_report(): void
    {
        $expectedUrl = 'https://feeds.example.test/concurrent.xml';
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-IMPORT-GENERATION');
        $bundle = $this->readBundle($this->evidence($this->snapshots(
            'apcom',
            $skuHash,
            ['2026-08-01T00:00:00+00:00'],
            present: true,
        )));
        $before = $this->catalogMutationCounts();
        $catalogSyncBefore = $this->catalogSyncActivityCounts();
        $job = $this->realXmlImportJob($supplier);
        $initialGenerationId = (int) (ImportHistory::query()
            ->where('supplier_id', $supplier->id)
            ->max('id') ?? 0);
        Bus::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            $expectedUrl => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products><product><code></code><name>Invalid concurrent row</name><price>bad</price></product></products>
XML, 200),
        ]);
        $originalSsrfProtection = app(SsrfProtectionService::class);
        $isolatedSsrfProtection = new ExactSyntheticFeedSsrfProtectionService($expectedUrl);
        $this->app->instance(SsrfProtectionService::class, $isolatedSsrfProtection);
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $job): void {
            if (! $injected && str_contains(strtolower($query->sql), 'product_supplier_offers')) {
                $injected = true;
                app(XmlImportEngine::class)->import($job);
            }
        });

        $report = null;
        try {
            try {
                $report = app(OperationalSupplierOfferLifecyclePreviewService::class)->preview(
                    $bundle,
                    CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
                    20,
                );
                $this->fail('Expected import generation change to abort.');
            } catch (RuntimeException $exception) {
                $this->assertSame('import_generation_changed_during_preview', $exception->getMessage());
            }
        } finally {
            $this->app->instance(SsrfProtectionService::class, $originalSsrfProtection);
        }

        $generation = ImportHistory::query()->where('import_job_id', $job->id)->sole();
        $this->assertTrue($injected);
        $this->assertNull($report);
        $this->assertGreaterThan($initialGenerationId, $generation->id);
        $this->assertSame('finished', $generation->event);
        $this->assertSame([$expectedUrl], $isolatedSsrfProtection->requestedUrls);
        $this->assertSame('completed_with_errors', $job->refresh()->status);
        $this->assertSame($before, $this->catalogMutationCounts());
        $this->assertSame($catalogSyncBefore, $this->catalogSyncActivityCounts());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertSent(static fn (Request $request): bool => $request->url() === $expectedUrl);
        Http::assertSentCount(1);

        try {
            ImportHistory::query()->create([
                'import_job_id' => $job->id,
                'supplier_id' => $job->supplier_id,
                'supplier_feed_id' => $job->supplier_feed_id,
                'event' => 'started',
                'level' => 'info',
                'message' => 'Importer context must be cleared.',
            ]);
            $this->fail('Expected importer mutation context to be cleared.');
        } catch (LogicException $exception) {
            $this->assertSame('Import history can only be created by an import engine.', $exception->getMessage());
        }
    }

    public function test_initial_import_activity_inspection_precedes_generation_and_catalog_baselines(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-INITIAL-GATE-ORDER');
        $bundle = $this->readBundle($this->evidence($this->snapshots(
            'apcom',
            $skuHash,
            ['2026-08-01T00:00:00+00:00'],
            present: true,
        )));
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(OperationalSupplierOfferLifecyclePreviewService::class)->preview(
            $bundle,
            CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
            20,
        );

        $importRunIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains($query, 'supplier_import_runs'),
        );
        $importJobIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains($query, 'import_jobs'),
        );
        $generationIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains($query, 'import_histories')
                && str_contains($query, 'max('),
        );
        $catalogIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains($query, 'product_supplier_offers'),
        );

        $this->assertNotNull($importRunIndex);
        $this->assertNotNull($importJobIndex);
        $this->assertNotNull($generationIndex);
        $this->assertNotNull($catalogIndex);
        $this->assertLessThan($generationIndex, $importRunIndex);
        $this->assertLessThan($generationIndex, $importJobIndex);
        $this->assertLessThan($catalogIndex, $generationIndex);
    }

    public function test_final_gate_has_no_deferred_database_reads_when_the_materialized_report_is_serialized(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-FINAL-NO-READ');
        $bundle = $this->readBundle($this->evidence($this->snapshots(
            'apcom',
            $skuHash,
            ['2026-08-01T00:00:00+00:00'],
            present: true,
        )));
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $report = app(OperationalSupplierOfferLifecyclePreviewService::class)->preview(
            $bundle,
            CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
            20,
        );
        $queryCountAtReturn = count($queries);
        $this->assertGreaterThan(0, $queryCountAtReturn);
        $this->assertStringContainsString('failed_jobs', $queries[array_key_last($queries)]);

        $report->toArray();
        $report->canonicalJson();

        $this->assertCount($queryCountAtReturn, $queries);
    }

    public function test_historical_completed_with_errors_is_terminal_but_its_evidence_remains_frozen(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-COMPLETED-WITH-ERRORS');
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'completed_with_errors',
        ]);
        $snapshot = $this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: false)[0];
        $snapshot['status'] = 'completed_with_errors';
        $snapshot['successful'] = false;

        $payload = $this->preview($this->evidence([$snapshot]), CarbonImmutable::parse($snapshot['captured_at']));

        $this->assertSame(1, $payload['counts']['frozen_snapshot_count']);
        $this->assertSame(0, $payload['counts']['confirmed_missing_count']);
        $this->assertSame(0, $payload['bounded_samples']['offer_evaluations'][0]['consecutive_missing_count']);
        $this->assertArrayHasKey('snapshot_not_successful', $payload['reason_code_counts']);
    }

    public function test_final_active_and_unknown_import_states_abort_without_preview_writes(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-FINAL-IMPORT-STATE');
        $bundle = $this->readBundle($this->evidence($this->snapshots(
            'apcom',
            $skuHash,
            ['2026-08-01T00:00:00+00:00'],
            present: true,
        )));
        $before = $this->catalogMutationCounts();
        Bus::fake();
        Queue::fake();
        Http::fake();

        foreach (['running' => 'active_import_state', 'mystery' => 'unknown_import_state'] as $status => $reason) {
            $guard = (object) ['injected' => false];
            DB::listen(function (QueryExecuted $query) use ($guard, $supplier, $status): void {
                if (! $guard->injected && str_contains(strtolower($query->sql), 'product_supplier_offers')) {
                    $guard->injected = true;
                    DB::table('supplier_import_runs')->insert([
                        'supplier_id' => $supplier->id,
                        'trigger_type' => 'manual',
                        'status' => $status,
                    ]);
                }
            });

            $report = null;
            try {
                $report = app(OperationalSupplierOfferLifecyclePreviewService::class)->preview(
                    $bundle,
                    CarbonImmutable::parse('2026-08-01T00:00:00+00:00'),
                    20,
                );
                $this->fail("Expected {$reason}.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($reason, $exception->getMessage());
            }
            $this->assertTrue($guard->injected);
            $this->assertNull($report);
            SupplierImportRun::query()->delete();
        }

        $this->assertSame($before, $this->catalogMutationCounts());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_command_json_and_table_outputs_are_redacted_and_preview_executes_only_select_queries(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'DO-NOT-EMIT-RAW-SKU');
        $path = $this->writeEvidence($this->evidence($this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: true)));
        $arguments = [
            '--supplier' => 'apcom',
            '--evidence' => $path,
            '--expected-sha256' => hash_file('sha256', $path),
            '--evaluated-at' => '2026-08-01T00:00:00+00:00',
            '--format' => 'json',
            '--limit' => '10',
            '--no-interaction' => true,
            '--no-ansi' => true,
        ];

        Bus::fake();
        Queue::fake();
        Http::fake();
        Cache::spy();
        Storage::fake('local');
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->assertSame(0, Artisan::call('suppliers:preview-apcom-offer-lifecycle', $arguments));
        $jsonOutput = Artisan::output();
        $payload = json_decode($jsonOutput, true, 128, JSON_THROW_ON_ERROR);
        $this->assertSame('supplier-offer-lifecycle-operational-preview-v1', $payload['schema_version']);
        $this->assertStringNotContainsString('DO-NOT-EMIT-RAW-SKU', $jsonOutput);
        $this->assertStringNotContainsString($path, $jsonOutput);
        $this->assertStringNotContainsString('supplier_sku"', $jsonOutput);
        $this->assertSame([], $writes);
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Cache::shouldNotHaveReceived('put');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $arguments['--format'] = 'table';
        $this->assertSame(0, Artisan::call('suppliers:preview-apcom-offer-lifecycle', $arguments));
        $this->assertStringContainsString('Records changed', Artisan::output());
        $this->assertStringNotContainsString('DO-NOT-EMIT-RAW-SKU', Artisan::output());
    }

    public function test_active_unknown_import_schedule_and_unsafe_sync_flags_abort_fail_closed(): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-APCOM-GATES');
        $bundle = $this->readBundle($this->evidence($this->snapshots('apcom', $skuHash, ['2026-08-01T00:00:00+00:00'], present: true)));
        $service = app(OperationalSupplierOfferLifecyclePreviewService::class);
        $at = CarbonImmutable::parse('2026-08-01T00:00:00+00:00');

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        SupplierImportRun::query()->create(['supplier_id' => $supplier->id, 'trigger_type' => 'manual', 'status' => 'running']);
        $queries = [];
        $this->assertFailsWith(fn () => $service->preview($bundle, $at, 20), 'active_import_state');
        $this->assertFalse(collect($queries)->contains(static fn (string $query): bool => str_contains($query, 'import_histories')));
        $this->assertFalse(collect($queries)->contains(static fn (string $query): bool => str_contains($query, 'product_supplier_offers')));
        SupplierImportRun::query()->delete();
        SupplierImportRun::query()->create(['supplier_id' => $supplier->id, 'trigger_type' => 'manual', 'status' => 'mystery']);
        $queries = [];
        $this->assertFailsWith(fn () => $service->preview($bundle, $at, 20), 'unknown_import_state');
        $this->assertFalse(collect($queries)->contains(static fn (string $query): bool => str_contains($query, 'import_histories')));
        $this->assertFalse(collect($queries)->contains(static fn (string $query): bool => str_contains($query, 'product_supplier_offers')));
        SupplierImportRun::query()->delete();

        $supplier->forceFill(['schedule_enabled' => true])->save();
        $this->assertFailsWith(fn () => $service->preview($bundle, $at, 20), 'supplier_schedule_must_remain_disabled');
        $supplier->forceFill(['schedule_enabled' => false])->save();
        config()->set('catalog_sync.update_enabled', true);
        $this->assertFailsWith(fn () => $service->preview($bundle, $at, 20), 'unsafe_catalog_sync_configuration');
    }

    public function test_v4_contract_is_additive_and_all_mutation_permissions_remain_false(): void
    {
        $decisions = app(SupplierHumanDecisionRegistry::class);
        $profiles = app(SupplierPreviewFeedProfileDesignRegistry::class);
        foreach (['apcom-human-decisions-v1', 'apcom-human-decisions-v2', 'apcom-human-decisions-v3'] as $key) {
            $this->assertSame($key, $decisions->find($key)?->key);
        }
        foreach (['apcom-preview-feed-profile-v1', 'apcom-preview-feed-profile-v2', 'apcom-preview-feed-profile-v3'] as $key) {
            $this->assertSame($key, $profiles->find($key)?->key);
        }

        $v4 = $decisions->apcomV4();
        $profile = $profiles->apcomV4();
        $this->assertSame('apcom-human-decisions-v3', $v4->supersedesKey);
        $this->assertSame('apcom-human-decisions-v4', $profile->decisionRegisterKey);
        foreach (['catalog_sync_allowed', 'import_allowed', 'lifecycle_write_allowed', 'link_change_allowed', 'schedule_change_allowed', 'visibility_write_allowed'] as $key) {
            $this->assertFalse($profile->safetyPolicy[$key]);
        }
        $this->assertTrue($profile->safetyPolicy['evaluation_allowed']);
    }

    private function supplier(string $slug): Supplier
    {
        return Supplier::factory()->create([
            'company_name' => 'Synthetic '.ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => false,
            'schedule_type' => 'manual_only',
            'minimum_product_count' => 1,
            'maximum_product_drop_percent' => 40,
        ]);
    }

    /** @return array{0: Product, 1: string, 2: ProductSupplierOffer} */
    private function linkedOffer(Supplier $supplier, string $sku, ?Product $product = null): array
    {
        $product ??= Product::factory()->supplierPublished()->create(['supplier_id' => $supplier->id]);
        $staged = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'supplier_sku' => $sku,
            'name' => 'Synthetic lifecycle fixture',
            'price' => '100.00',
            'quantity' => 6,
            'currency' => 'EUR',
            'raw_data' => ['synthetic' => true],
            'payload_hash' => hash('sha256', 'synthetic-staged-'.$supplier->id.'-'.$sku),
            'received_at' => '2026-08-01 00:00:00',
            'status' => 'new',
        ]);
        $offer = ProductSupplierOffer::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_id' => $staged->id,
            'supplier_sku' => $sku,
            'price' => '100.00',
            'quantity' => 6,
            'currency' => 'EUR',
            'last_seen_at' => '2026-08-01 00:00:00',
        ]);

        return [$product, $this->hasher()->supplierSku($supplier->slug, $sku), $offer];
    }

    private function realXmlImportJob(Supplier $supplier): ImportJob
    {
        $feed = SupplierFeed::query()->create([
            'supplier_id' => $supplier->id,
            'feed_name' => 'Synthetic concurrent XML',
            'feed_type' => 'xml',
            'feed_url' => 'https://feeds.example.test/concurrent.xml',
            'status' => 'active',
        ]);
        $template = XmlMappingTemplate::query()->create([
            'supplier_id' => $supplier->id,
            'name' => 'Synthetic concurrent mapping',
            'root_path' => 'product',
            'field_map' => [
                'supplier_sku' => 'code',
                'name' => 'name',
                'price' => 'price',
            ],
            'validation_rules' => [
                'supplier_sku' => ['required'],
                'name' => ['required'],
                'price' => ['required', 'numeric'],
            ],
            'is_active' => true,
        ]);

        return ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'completed',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshots(string $supplier, string $skuHash, array $times, bool $present, string $price = '100', int $startIndex = 1): array
    {
        $snapshots = [];
        foreach (array_values($times) as $offset => $time) {
            $index = $startIndex + $offset;
            $snapshots[] = [
                'snapshot_id' => 'synthetic-'.$supplier.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'supplier' => $supplier,
                'source_identity' => 'synthetic-'.$supplier.'-stock-price-v1',
                'captured_at' => $time,
                'authoritative_snapshot_at' => $time,
                'fingerprint' => hash('sha256', 'synthetic-'.$supplier.'-snapshot-'.$index),
                'status' => 'completed',
                'successful' => true,
                'full' => true,
                'schema_valid' => true,
                'truncated' => false,
                'product_count' => 100,
                'minimum_product_count' => 1,
                'product_drop_percent' => 0,
                'maximum_product_drop_percent' => 40,
                'fatal_integrity_blocker' => false,
                'supplier_identity_confirmed' => true,
                'comparable' => true,
                'observations' => [[
                    'supplier_sku_hash' => $skuHash,
                    'present' => $present,
                    'price' => $present ? $price : null,
                    'raw_quantity_observed' => $supplier === 'apcom' && $present ? 6 : null,
                    'eol_flag' => $supplier === 'apcom' && $present ? 0 : null,
                    'canonical_public_status' => $supplier === 'apcom' || ! $present ? null : 'in_stock',
                    'supplier_mapper_valid' => true,
                    'exact_supplier_sku_match' => true,
                    'identifier_conflict' => false,
                    'blocking_validation_issue' => false,
                    'duplicate_offer' => false,
                    'reliable_manufacturer_mpn_hash' => null,
                ]],
            ];
        }

        return $snapshots;
    }

    /** @return array<int, array<string, mixed>> */
    private function interleavedSnapshots(string $apcomHash, string $backupHash, array $times): array
    {
        $snapshots = $this->snapshots('apcom', $apcomHash, $times, present: false);
        $snapshots[] = $this->snapshots('backup-supplier', $backupHash, [end($times)], present: true)[0];

        return $snapshots;
    }

    /** @return array<string, mixed> */
    private function evidence(array $snapshots, array $scope = ['apcom'], ?array $freshness = null, array $productLifecycleEvidence = []): array
    {
        return [
            'schema_version' => OperationalSupplierOfferEvidenceBundle::SCHEMA_VERSION,
            'supplier' => 'apcom',
            'supplier_scope' => $scope,
            'policy_versions' => [
                'aggregation' => 'catalog-offer-aggregation-policy-v1',
                'decision_register' => 'apcom-human-decisions-v4',
                'deletion' => 'catalog-product-deletion-policy-v1',
                'missing_offer' => 'supplier-offer-missing-policy-v1',
                'preview_profile' => 'apcom-preview-feed-profile-v4',
                'reappearance' => 'supplier-offer-reappearance-policy-v1',
                'retention' => 'supplier-technical-retention-policy-v1',
                'snapshot_qualification' => 'supplier-full-snapshot-qualification-policy-v1',
                'visibility' => 'catalog-product-visibility-lifecycle-policy-v1',
            ],
            'source_identity' => 'synthetic-apcom-stock-price-v1',
            'freshness_policies' => $freshness ?? $this->apcomFreshness(),
            'snapshots' => $snapshots,
            'product_lifecycle_evidence' => $productLifecycleEvidence,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function apcomFreshness(): array
    {
        return [[
            'supplier' => 'apcom',
            'policy_key' => 'apcom-snapshot-freshness-policy-v1',
            'max_age_hours' => 24,
            'approved' => true,
        ]];
    }

    private function preview(array $evidence, CarbonImmutable $evaluatedAt): array
    {
        return $this->previewReport($evidence, $evaluatedAt)->toArray();
    }

    private function previewReport(array $evidence, CarbonImmutable $evaluatedAt): OperationalSupplierOfferLifecyclePreviewReport
    {
        return app(OperationalSupplierOfferLifecyclePreviewService::class)->preview($this->readBundle($evidence), $evaluatedAt, 100);
    }

    private function readBundle(array $evidence): OperationalSupplierOfferEvidenceBundle
    {
        $path = $this->writeEvidence($evidence);

        return app(OperationalSupplierOfferEvidenceBundleReader::class)->read($path, hash_file('sha256', $path));
    }

    private function writeEvidence(array $evidence): string
    {
        $path = tempnam(sys_get_temp_dir(), 'operational-evidence-');
        if ($path === false) {
            $this->fail('Unable to create synthetic evidence file.');
        }
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function hasher(): OperationalSupplierOfferIdentityHasher
    {
        return app(OperationalSupplierOfferIdentityHasher::class);
    }

    private function assertUnsafeIdentityPreservesConfirmedMissingState(callable $configure, string $expectedReason): void
    {
        $supplier = $this->supplier('apcom');
        [, $skuHash] = $this->linkedOffer($supplier, 'SYNTHETIC-UNSAFE-'.$expectedReason);
        $times = [
            '2026-08-01T00:00:00+00:00',
            '2026-08-02T00:00:00+00:00',
            '2026-08-03T00:00:00+00:00',
            '2026-08-04T00:00:00+00:00',
        ];
        $snapshots = $this->snapshots('apcom', $skuHash, array_slice($times, 0, 3), present: false);
        $unsafe = $this->snapshots('apcom', $skuHash, [$times[3]], present: true, startIndex: 4)[0];
        $configure($unsafe['observations'][0]);
        $snapshots[] = $unsafe;
        $before = $this->protectedCounts();
        Bus::fake();
        Queue::fake();
        Http::fake();

        $payload = $this->preview($this->evidence($snapshots), CarbonImmutable::parse($times[3]));
        $offer = $payload['bounded_samples']['offer_evaluations'][0];
        $product = $payload['bounded_samples']['product_recommendations'][0];

        $this->assertSame('inactive_missing_from_feed', $offer['presence_status']);
        $this->assertSame(3, $offer['consecutive_missing_count']);
        $this->assertSame($times[0], $offer['first_missing_at']);
        $this->assertSame(1, $payload['counts']['confirmed_missing_count']);
        $this->assertSame(0, $payload['counts']['reappearance_count']);
        $this->assertSame('manual_review', $offer['recommendation']);
        $this->assertContains($expectedReason, $offer['reason_codes']);
        $this->assertSame('manual_review', $product['recommendation']);
        $this->assertNull($product['visibility_preview']);
        foreach ([
            'would_deactivate_offer',
            'would_reactivate_offer',
            'would_mark_unavailable',
            'would_mark_archived_noindex',
            'would_mark_cold_archive_candidate',
        ] as $recommendation) {
            $this->assertArrayNotHasKey($recommendation, $payload['recommendation_counts']);
        }
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame(0, $payload['dispatched_jobs']);
        $this->assertSame(0, $payload['dispatched_events']);
        $this->assertSame($before, $this->protectedCounts());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        $counts = [];
        foreach ([
            'suppliers', 'supplier_products', 'product_supplier_offers', 'products', 'categories', 'brands',
            'product_images', 'users', 'roles', 'permissions', 'supplier_import_runs', 'import_jobs',
            'catalog_sync_batches', 'catalog_sync_logs', 'jobs', 'failed_jobs',
        ] as $table) {
            $counts[$table] = Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function catalogMutationCounts(): array
    {
        return [
            'product_supplier_offers' => ProductSupplierOffer::query()->count(),
            'products' => Product::withTrashed()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
        ];
    }

    /** @return array<string, int> */
    private function catalogSyncActivityCounts(): array
    {
        return [
            'catalog_sync_batches' => Schema::hasTable('catalog_sync_batches') ? (int) DB::table('catalog_sync_batches')->count() : 0,
            'catalog_sync_logs' => Schema::hasTable('catalog_sync_logs') ? (int) DB::table('catalog_sync_logs')->count() : 0,
        ];
    }

    private function assertFailsWith(callable $callback, string $reason): void
    {
        try {
            $callback();
            $this->fail("Expected {$reason}.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
    }
}
