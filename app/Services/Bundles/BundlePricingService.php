<?php

namespace App\Services\Bundles;

use App\Models\Product;
use App\Models\ProductBundle;

class BundlePricingService
{
    public function calculate(ProductBundle $bundle, array $selectedItems = []): array
    {
        $items = $this->selectedProducts($bundle, $selectedItems);
        $original = collect($items)->sum(fn (array $item): float => $item['unit_price'] * $item['quantity'] + $item['price_adjustment']);

        $price = match ($bundle->pricing_type) {
            'fixed_price' => (float) $bundle->fixed_price,
            'discount_percentage' => $original - ($original * ((float) $bundle->discount_value / 100)),
            'discount_fixed' => $original - (float) $bundle->discount_value,
            default => $original,
        };

        $price = round(max(0, $price), 2);

        return [
            'original_price' => round($original, 2),
            'unit_price' => $price,
            'savings' => round(max(0, $original - $price), 2),
            'selected_items' => $items,
        ];
    }

    public function selectedProducts(ProductBundle $bundle, array $selectedItems = []): array
    {
        $bundle->loadMissing(['items.product', 'options.product']);
        $items = [];

        foreach ($bundle->items as $item) {
            if (! $item->product) {
                continue;
            }

            $items[] = $this->line($item->product, $item->component_group, $item->quantity);
        }

        foreach ($this->selectedOptions($bundle, $selectedItems) as $option) {
            $items[] = $this->line($option->product, $option->component_group, 1, (float) ($option->price_adjustment ?? 0));
        }

        return $items;
    }

    public function selectedOptions(ProductBundle $bundle, array $selectedItems = [])
    {
        $selected = collect($selectedItems);
        $options = collect();

        foreach ($bundle->options->groupBy('component_group') as $group => $groupOptions) {
            $selectedProductId = $selected->firstWhere('component_group', $group)['product_id'] ?? null;
            $option = $selectedProductId
                ? $groupOptions->firstWhere('product_id', (int) $selectedProductId)
                : $groupOptions->firstWhere('is_default', true);

            if ($option) {
                $options->push($option);
            }
        }

        return $options;
    }

    private function line(Product $product, ?string $group, int $quantity, float $adjustment = 0): array
    {
        $price = (float) ($product->promo_price ?? $product->price);

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'component_group' => $group,
            'quantity' => $quantity,
            'unit_price' => $price,
            'price_adjustment' => $adjustment,
        ];
    }
}
