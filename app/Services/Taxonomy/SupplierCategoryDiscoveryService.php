<?php

namespace App\Services\Taxonomy;

use App\Models\CanonicalProductFamily;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SupplierCategoryDiscoveryService
{
    public function __construct(private readonly SupplierCategoryFamilyInferenceService $inference) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(?string $supplier = null, bool $onlyUnmapped = false, bool $includeEmpty = false): Collection
    {
        $query = SupplierProduct::query()
            ->selectRaw('supplier_id, category_name, COUNT(*) as product_count')
            ->with('supplier')
            ->groupBy('supplier_id', 'category_name')
            ->orderByDesc('product_count')
            ->orderBy('category_name');

        if (! $includeEmpty) {
            $query
                ->whereNotNull('category_name')
                ->where('category_name', '!=', '');
        }

        if (filled($supplier)) {
            $this->applySupplierFilter($query, (string) $supplier);
        }

        $familiesByCode = CanonicalProductFamily::query()
            ->get()
            ->keyBy('code');

        return $query
            ->get()
            ->map(function (SupplierProduct $row) use ($familiesByCode): array {
                $categoryName = filled($row->category_name) ? (string) $row->category_name : '(empty)';
                $supplier = $row->supplier;
                $identity = [
                    'supplier_id' => $row->supplier_id,
                    'supplier_key' => $supplier?->slug,
                    'supplier_name' => $supplier?->company_name,
                    'supplier_category_name' => $categoryName,
                    'supplier_category_slug' => Str::slug($categoryName),
                    'supplier_category_path' => null,
                    'supplier_category_external_id' => null,
                ];
                $hash = SupplierCategoryMapping::makeHash($identity);
                $mapping = SupplierCategoryMapping::query()
                    ->with('canonicalProductFamily')
                    ->where('supplier_category_hash', $hash)
                    ->first();
                $inference = $this->inference->infer([
                    $categoryName,
                    $identity['supplier_category_slug'],
                    $identity['supplier_category_path'],
                ]);
                $family = $mapping?->canonicalProductFamily
                    ?? $familiesByCode->get($inference['family_code'])
                    ?? $familiesByCode->get('unknown');

                return [
                    'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null,
                    'supplier_key' => $identity['supplier_key'],
                    'supplier_name' => $identity['supplier_name'] ?? 'Unknown supplier',
                    'supplier_category_name' => $categoryName,
                    'supplier_category_slug' => $identity['supplier_category_slug'],
                    'supplier_category_path' => $identity['supplier_category_path'],
                    'supplier_category_external_id' => null,
                    'supplier_category_hash' => $hash,
                    'product_count' => (int) $row->product_count,
                    'mapping_id' => $mapping?->id,
                    'mapping_status' => $mapping?->status,
                    'canonical_product_family_id' => $family?->id,
                    'suggested_canonical_family' => $family?->code ?? $inference['family_code'],
                    'confidence' => $mapping?->confidence ?? $inference['confidence'],
                    'match_reason' => $mapping?->match_reason ?? $inference['match_reason'],
                    'next_action' => $this->nextAction($mapping),
                ];
            })
            ->when($onlyUnmapped, fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => $row['mapping_id'] === null)
                ->values());
    }

    /**
     * @param  Builder<SupplierProduct>  $query
     */
    private function applySupplierFilter($query, string $supplier): void
    {
        if (is_numeric($supplier)) {
            $query->where('supplier_id', (int) $supplier);

            return;
        }

        $normalized = Str::lower(trim($supplier));

        $query->whereHas('supplier', function ($supplierQuery) use ($normalized): void {
            $supplierQuery
                ->whereRaw('LOWER(slug) = ?', [$normalized])
                ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
        });
    }

    private function nextAction(?SupplierCategoryMapping $mapping): string
    {
        if (! $mapping) {
            return 'create pending mapping';
        }

        return match ($mapping->status) {
            SupplierCategoryMapping::STATUS_APPROVED => 'approved',
            SupplierCategoryMapping::STATUS_IGNORED => 'ignored',
            SupplierCategoryMapping::STATUS_REJECTED => 'needs manual classification',
            default => 'review existing mapping',
        };
    }
}
