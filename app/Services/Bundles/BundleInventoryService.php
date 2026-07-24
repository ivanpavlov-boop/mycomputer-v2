<?php

namespace App\Services\Bundles;

use App\Models\ProductBundle;
use App\Services\Cart\CartReadinessService;
use Illuminate\Validation\ValidationException;

class BundleInventoryService
{
    public function __construct(private readonly CartReadinessService $readiness) {}

    public function assertAvailable(ProductBundle $bundle, array $selectedItems = [], int $bundleQuantity = 1): void
    {
        $result = $this->readiness->assessBundle($bundle, $selectedItems, $bundleQuantity);

        if ($result->hasIssue('bundle_unavailable')) {
            throw ValidationException::withMessages(['bundle_id' => 'Bundle is not available.']);
        }

        if ($result->hasIssue('bundle_selection_invalid')) {
            throw ValidationException::withMessages(['selected_items' => 'Selected bundle option is not valid.']);
        }

        if ($result->hasIssue('bundle_product_unavailable')) {
            throw ValidationException::withMessages(['selected_items' => 'Bundle contains unavailable product.']);
        }

        if ($result->hasIssue('bundle_insufficient_stock')) {
            throw ValidationException::withMessages(['selected_items' => 'Insufficient stock for bundle products.']);
        }
    }
}
