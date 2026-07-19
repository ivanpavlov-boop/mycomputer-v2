<?php

namespace App\Services\Bundles;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Services\Availability\AvailabilityStatusService;
use Illuminate\Validation\ValidationException;

class BundleInventoryService
{
    public function __construct(private readonly AvailabilityStatusService $availability) {}

    public function assertAvailable(ProductBundle $bundle, array $selectedItems = [], int $bundleQuantity = 1): void
    {
        $bundle->loadMissing(['items.product', 'options.product']);

        if ($bundle->status !== 'active' || ($bundle->starts_at && $bundle->starts_at->isFuture()) || ($bundle->ends_at && $bundle->ends_at->isPast())) {
            throw ValidationException::withMessages(['bundle_id' => 'Bundle is not available.']);
        }

        foreach ($selectedItems as $selectedItem) {
            $componentGroup = $selectedItem['component_group'] ?? null;
            $productId = (int) ($selectedItem['product_id'] ?? 0);
            $hasConfigurableOptions = $bundle->options->contains(fn ($option): bool => $option->component_group === $componentGroup);

            if (! $hasConfigurableOptions) {
                continue;
            }

            $isValidOption = $bundle->options->contains(fn ($option): bool => $option->component_group === $componentGroup && (int) $option->product_id === $productId);

            if (! $isValidOption) {
                throw ValidationException::withMessages(['selected_items' => 'Selected bundle option is not valid.']);
            }
        }

        foreach ($bundle->items->where('is_required', true)->whereNull('product_id') as $requiredGroup) {
            $hasSelection = collect($selectedItems)->contains(fn (array $item): bool => ($item['component_group'] ?? null) === $requiredGroup->component_group);
            $hasDefault = $bundle->options->contains(fn ($option): bool => $option->component_group === $requiredGroup->component_group && $option->is_default);

            if (! $hasSelection && ! $hasDefault) {
                throw ValidationException::withMessages(['selected_items' => "Required bundle option {$requiredGroup->component_group} is missing."]);
            }
        }

        $lines = app(BundlePricingService::class)->selectedProducts($bundle, $selectedItems);

        if ($lines === []) {
            throw ValidationException::withMessages(['bundle_id' => 'Bundle has no valid products.']);
        }

        foreach ($lines as $line) {
            $product = Product::query()->find($line['product_id']);
            if (! $product || ! $product->isPubliclyVisible() || ! $this->availability->allowsPurchase($product)) {
                throw ValidationException::withMessages(['selected_items' => 'Bundle contains unavailable product.']);
            }

            $required = $line['quantity'] * $bundleQuantity;
            if ($this->availability->requiresStock($product) && $product->quantity < $required) {
                throw ValidationException::withMessages(['selected_items' => "Insufficient stock for {$product->name}."]);
            }
        }
    }
}
