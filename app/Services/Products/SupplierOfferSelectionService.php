<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Services\Suppliers\SupplierExclusionService;
use Illuminate\Support\Collection;

class SupplierOfferSelectionService
{
    public function __construct(
        private readonly SupplierExclusionService $exclusionService,
    ) {}

    /**
     * @return array{offer: ProductSupplierOffer|null, reason: string, candidates: array<int, array<string, mixed>>}
     */
    public function select(Product $product): array
    {
        $offers = $product->supplierOffers()
            ->with(['supplier', 'supplierProduct'])
            ->get();

        $candidates = $offers->map(fn (ProductSupplierOffer $offer): array => $this->candidate($offer))->values();
        $eligible = $candidates
            ->filter(fn (array $candidate): bool => $candidate['eligible'])
            ->sortBy([
                ['price_sort', 'asc'],
                ['supplier_priority', 'asc'],
                ['preferred_rank', 'asc'],
            ])
            ->values();

        $selected = $eligible->first();

        if ($selected) {
            return [
                'offer' => $offers->firstWhere('id', $selected['id']),
                'reason' => 'Lowest available in-stock supplier.',
                'candidates' => $candidates->all(),
            ];
        }

        return [
            'offer' => null,
            'reason' => $this->noEligibleReason($candidates),
            'candidates' => $candidates->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function candidate(ProductSupplierOffer $offer): array
    {
        $supplierProduct = $offer->supplierProduct;
        $excluded = $supplierProduct ? $this->exclusionService->evaluate($supplierProduct) : [
            'excluded' => false,
            'label' => null,
        ];
        $supplierActive = $offer->supplier?->status === 'active';
        $inStock = (int) $offer->quantity > 0;
        $eligible = $supplierActive && $inStock && ! $excluded['excluded'];

        return [
            'id' => $offer->id,
            'supplier_name' => $offer->supplier?->company_name,
            'supplier_id' => $offer->supplier_id,
            'supplier_sku' => $offer->supplier_sku,
            'price' => $offer->price !== null ? (float) $offer->price : null,
            'price_sort' => $offer->price !== null ? (float) $offer->price : PHP_FLOAT_MAX,
            'quantity' => (int) $offer->quantity,
            'supplier_priority' => (int) $offer->supplier_priority,
            'is_preferred' => (bool) $offer->is_preferred,
            'preferred_rank' => $offer->is_preferred ? 0 : 1,
            'supplier_active' => $supplierActive,
            'excluded' => (bool) $excluded['excluded'],
            'exclusion_rule' => $excluded['label'],
            'eligible' => $eligible,
            'rejection_reason' => $eligible ? null : $this->rejectionReason($supplierActive, $inStock, (bool) $excluded['excluded']),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     */
    protected function noEligibleReason(Collection $candidates): string
    {
        if ($candidates->isEmpty()) {
            return 'No supplier offers exist.';
        }

        if ($candidates->every(fn (array $candidate): bool => ! $candidate['supplier_active'])) {
            return 'No active supplier offers are available.';
        }

        if ($candidates->every(fn (array $candidate): bool => $candidate['quantity'] <= 0)) {
            return 'No supplier has stock; product should become Out Of Stock.';
        }

        if ($candidates->every(fn (array $candidate): bool => $candidate['excluded'])) {
            return 'All supplier offers are excluded by rules.';
        }

        return 'No eligible supplier offer is available.';
    }

    protected function rejectionReason(bool $supplierActive, bool $inStock, bool $excluded): string
    {
        if (! $supplierActive) {
            return 'Supplier is inactive.';
        }

        if ($excluded) {
            return 'Offer is excluded by rule.';
        }

        if (! $inStock) {
            return 'Offer has zero stock.';
        }

        return 'Offer is not eligible.';
    }
}
