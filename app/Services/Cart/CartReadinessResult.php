<?php

namespace App\Services\Cart;

use App\Models\Cart;

final readonly class CartReadinessResult
{
    /**
     * @param  array<int, CartLineReadiness>  $regularLines
     * @param  array<int, CartLineReadiness>  $bundleLines
     */
    public function __construct(
        public Cart $cart,
        public bool $canCheckout,
        public array $issues,
        public array $regularLines,
        public array $bundleLines,
    ) {}

    public function toArray(): array
    {
        $codes = $this->allIssueCodes();

        return [
            'can_checkout' => $this->canCheckout,
            'issues_count' => count($codes),
            'has_product_issues' => collect($codes)->contains(
                fn (string $code): bool => str_starts_with($code, 'product_')
                    || in_array($code, ['bundle_unavailable', 'bundle_selection_invalid', 'bundle_product_unavailable'], true),
            ),
            'has_stock_issues' => collect($codes)->contains(
                fn (string $code): bool => in_array($code, ['insufficient_stock', 'bundle_insufficient_stock'], true),
            ),
        ];
    }

    public function details(): array
    {
        return $this->toArray() + [
            'issues' => $this->issues,
            'items' => $this->cart->items
                ->map(fn ($item): array => [
                    'cart_item_id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'readiness' => $this->regularLines[$item->id]->toArray(),
                ])
                ->values()
                ->all(),
            'bundle_items' => $this->cart->bundleItems
                ->map(fn ($item): array => [
                    'cart_bundle_item_id' => (int) $item->id,
                    'bundle_id' => (int) $item->product_bundle_id,
                    'readiness' => $this->bundleLines[$item->id]->toArray(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function allIssueCodes(): array
    {
        return collect($this->issues)
            ->merge(collect($this->regularLines)->flatMap->issues)
            ->merge(collect($this->bundleLines)->flatMap->issues)
            ->pluck('code')
            ->values()
            ->all();
    }
}
