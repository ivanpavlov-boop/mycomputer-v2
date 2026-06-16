<?php

namespace App\Services\Suppliers;

use App\Models\Category;
use App\Models\SupplierExclusionRule;
use App\Models\SupplierProduct;
use Illuminate\Support\Str;

class SupplierExclusionService
{
    /**
     * @return array{excluded: bool, rule: SupplierExclusionRule|null, reason: string|null, label: string|null}
     */
    public function evaluate(SupplierProduct $supplierProduct): array
    {
        $supplierProduct->loadMissing('supplier');

        $rule = SupplierExclusionRule::query()
            ->active()
            ->with(['supplier', 'category', 'brand'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->first(fn (SupplierExclusionRule $rule): bool => $this->matches($rule, $supplierProduct));

        return [
            'excluded' => $rule !== null,
            'rule' => $rule,
            'reason' => $rule?->reason ?: ($rule ? 'Excluded by rule' : null),
            'label' => $rule ? $this->label($rule) : null,
        ];
    }

    public function isExcluded(SupplierProduct $supplierProduct): bool
    {
        return $this->evaluate($supplierProduct)['excluded'];
    }

    protected function matches(SupplierExclusionRule $rule, SupplierProduct $supplierProduct): bool
    {
        if ($rule->supplier_id !== null && (int) $rule->supplier_id !== (int) $supplierProduct->supplier_id) {
            return false;
        }

        if ($rule->brand_id !== null && Str::slug((string) $supplierProduct->brand_name) !== $rule->brand?->slug) {
            return false;
        }

        if ($rule->category_id !== null && ! $this->matchesCategory($rule->category, (string) $supplierProduct->category_name)) {
            return false;
        }

        if (filled($rule->sku) && ! $this->same($rule->sku, $supplierProduct->supplier_sku)) {
            return false;
        }

        if (filled($rule->ean) && ! $this->same($rule->ean, $supplierProduct->ean)) {
            return false;
        }

        if (filled($rule->mpn) && ! $this->same($rule->mpn, $supplierProduct->mpn)) {
            return false;
        }

        if (filled($rule->product_name_contains) && ! Str::contains(Str::lower((string) $supplierProduct->name), Str::lower($rule->product_name_contains))) {
            return false;
        }

        if ($rule->exclude_zero_stock && (int) ($supplierProduct->quantity ?? 0) > 0) {
            return false;
        }

        if ($rule->exclude_missing_ean && filled($supplierProduct->ean)) {
            return false;
        }

        if ($rule->exclude_eol && ! $this->isEol($supplierProduct)) {
            return false;
        }

        if ($rule->min_price !== null && (float) ($supplierProduct->price ?? 0) < (float) $rule->min_price) {
            return false;
        }

        if ($rule->max_price !== null && (float) ($supplierProduct->price ?? 0) > (float) $rule->max_price) {
            return false;
        }

        return $this->hasAtLeastOneScope($rule);
    }

    protected function matchesCategory(?Category $category, string $categoryName): bool
    {
        if (! $category) {
            return false;
        }

        $categorySlug = $category->slug;
        $segments = preg_split('/\s*(?:>|\/|\||,)\s*/', $categoryName) ?: [];

        return collect($segments)
            ->map(fn (string $segment): string => Str::slug(trim($segment)))
            ->contains($categorySlug);
    }

    protected function isEol(SupplierProduct $supplierProduct): bool
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $supplierProduct->category_name,
            $supplierProduct->external_availability_status,
            $supplierProduct->external_availability_label,
            $supplierProduct->raw_data['category'] ?? null,
            $supplierProduct->raw_data['Category'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value))));

        return Str::contains($haystack, ['eol', 'end of life', 'discontinued']);
    }

    protected function hasAtLeastOneScope(SupplierExclusionRule $rule): bool
    {
        return $rule->supplier_id !== null
            || $rule->category_id !== null
            || $rule->brand_id !== null
            || filled($rule->sku)
            || filled($rule->ean)
            || filled($rule->mpn)
            || filled($rule->product_name_contains)
            || $rule->exclude_zero_stock
            || $rule->exclude_eol
            || $rule->exclude_missing_ean
            || $rule->min_price !== null
            || $rule->max_price !== null;
    }

    protected function same(?string $expected, ?string $actual): bool
    {
        return filled($expected) && filled($actual) && Str::lower(trim($expected)) === Str::lower(trim($actual));
    }

    protected function label(SupplierExclusionRule $rule): string
    {
        $parts = array_filter([
            $rule->supplier?->company_name,
            $rule->category ? 'Category '.$rule->category->name : null,
            $rule->brand ? 'Brand '.$rule->brand->name : null,
            $rule->sku ? 'SKU '.$rule->sku : null,
            $rule->ean ? 'EAN '.$rule->ean : null,
            $rule->mpn ? 'MPN '.$rule->mpn : null,
            $rule->product_name_contains ? 'Name contains '.$rule->product_name_contains : null,
            $rule->exclude_zero_stock ? 'Zero stock' : null,
            $rule->exclude_eol ? 'EOL' : null,
            $rule->exclude_missing_ean ? 'Missing EAN' : null,
            $rule->min_price !== null ? 'Min price '.$rule->min_price : null,
            $rule->max_price !== null ? 'Max price '.$rule->max_price : null,
        ]);

        return implode(' + ', $parts) ?: $rule->name;
    }
}
