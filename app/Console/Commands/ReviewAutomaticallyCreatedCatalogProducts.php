<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\ProductWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewAutomaticallyCreatedCatalogProducts extends Command
{
    protected $signature = 'catalog:review-auto-created-products
        {--apply : Persist the status move for the known automatically-created products}
        {--status= : Target workflow status. Allowed: pending_review, draft}';

    protected $description = 'Dry-run or apply a safe review status move for known auto-created catalog products.';

    private const KNOWN_SKUS = [
        'VMA3600-10000S',
        'VMC4460P-100EUS',
        'VMC4260P-100EUS',
    ];

    private const REVIEW_NOTE = 'Moved to manual review by catalog:review-auto-created-products because this product was automatically created before the Phase 9C.4.2 supplier import safety hotfix.';

    public function handle(ProductWorkflowService $workflow): int
    {
        $targetStatus = $this->targetStatus();

        if ($targetStatus === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $products = Product::query()
            ->whereIn('sku', self::KNOWN_SKUS)
            ->get()
            ->keyBy('sku');

        $rows = collect(self::KNOWN_SKUS)
            ->map(function (string $sku) use ($products, $targetStatus): array {
                $product = $products->get($sku);

                return [
                    'sku' => $sku,
                    'product' => $product,
                    'desired' => $product ? $this->desiredReviewValues($targetStatus, $product) : [],
                    'needs_change' => $product ? $this->needsReviewMove($product, $targetStatus) : false,
                ];
            });

        $changed = 0;

        if ($apply) {
            $changed = DB::transaction(function () use ($rows, $targetStatus, $workflow): int {
                $changed = 0;

                foreach ($rows as $row) {
                    /** @var Product|null $product */
                    $product = $row['product'];

                    if (! $product || ! $this->needsReviewMove($product, $targetStatus)) {
                        continue;
                    }

                    $workflow->moveToReviewForMaintenance(
                        $product,
                        $targetStatus,
                        $this->reviewNotesWithSafetyNote($product),
                    );
                    $changed++;
                }

                return $changed;
            });
        }

        $this->info($apply
            ? 'Apply mode. Known automatically-created catalog products were reviewed.'
            : 'Dry-run only. No records were changed.');
        $this->line('Known SKUs considered: '.implode(', ', self::KNOWN_SKUS));
        $this->line('Target workflow_status: '.$targetStatus);
        $this->line('Target product_status: '.$this->targetProductStatus($targetStatus));
        $this->line('Target active: false');
        $this->line('Products found: '.$rows->whereNotNull('product')->count());
        $this->line('Products missing: '.$rows->whereNull('product')->count());
        $this->line('Products to change: '.$rows->where('needs_change', true)->count());
        $this->line('Products changed: '.$changed);

        foreach ($rows as $row) {
            /** @var Product|null $product */
            $product = $row['product'];

            if (! $product) {
                $this->warn(sprintf('SKU %s: missing; no change proposed.', $row['sku']));

                continue;
            }

            $desired = $row['desired'];
            $action = $row['needs_change']
                ? ($apply ? 'changed' : 'would_change')
                : 'already_target';

            $this->line(sprintf(
                'SKU %s: product_id=%d; current workflow_status=%s; current product_status=%s; current active=%s; proposed workflow_status=%s; proposed product_status=%s; proposed active=%s; action=%s',
                $row['sku'],
                $product->id,
                $product->workflow_status ?? '(null)',
                $product->product_status ?? '(null)',
                $product->active ? 'true' : 'false',
                $desired['workflow_status'],
                $desired['product_status'],
                $desired['active'] ? 'true' : 'false',
                $action,
            ));
        }

        $this->line('Products deleted: 0');
        $this->line('Products soft-deleted: 0');
        $this->line('Products created: 0');
        $this->line('supplier_products changed: 0');
        $this->line('product_attribute_values changed: 0');
        $this->line('category_product_attributes changed: 0');
        $this->line('Catalog Sync behavior changed: no');
        $this->line('Sync All added: no');
        $this->line('Automatic sync enabled: no');
        $this->line('UPDATE sync enabled: no');

        return self::SUCCESS;
    }

    private function targetStatus(): ?string
    {
        $status = $this->option('status') ?: Product::WORKFLOW_PENDING_REVIEW;
        $status = trim((string) $status);

        $allowed = [
            Product::WORKFLOW_PENDING_REVIEW,
            Product::WORKFLOW_DRAFT,
        ];

        if (! in_array($status, $allowed, true)) {
            $this->error(sprintf(
                'Invalid target status "%s". Allowed statuses: %s.',
                $status,
                implode(', ', $allowed),
            ));

            return null;
        }

        if (! array_key_exists($status, Product::workflowStatusOptions())) {
            $this->error(sprintf(
                'Target status "%s" is not a valid product workflow status in this project.',
                $status,
            ));

            return null;
        }

        return $status;
    }

    /**
     * @return array{workflow_status: string, product_status: string, active: bool, review_notes: string}
     */
    private function desiredReviewValues(string $targetStatus, Product $product): array
    {
        return [
            'workflow_status' => $targetStatus,
            'product_status' => $this->targetProductStatus($targetStatus),
            'active' => false,
            'review_notes' => $this->reviewNotesWithSafetyNote($product),
        ];
    }

    private function targetProductStatus(string $targetStatus): string
    {
        return $targetStatus === Product::WORKFLOW_DRAFT ? 'draft' : 'hidden';
    }

    private function needsReviewMove(Product $product, string $targetStatus): bool
    {
        $desired = $this->desiredReviewValues($targetStatus, $product);

        return $product->workflow_status !== $desired['workflow_status']
            || $product->product_status !== $desired['product_status']
            || (bool) $product->active !== $desired['active']
            || $product->review_notes !== $desired['review_notes'];
    }

    private function reviewNotesWithSafetyNote(Product $product): string
    {
        $existingNotes = trim((string) $product->review_notes);

        if ($existingNotes === '') {
            return self::REVIEW_NOTE;
        }

        if (str_contains($existingNotes, self::REVIEW_NOTE)) {
            return $existingNotes;
        }

        return $existingNotes.PHP_EOL.PHP_EOL.self::REVIEW_NOTE;
    }
}
