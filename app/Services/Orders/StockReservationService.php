<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Availability\AvailabilityStatusService;
use App\Services\Bundles\BundleInventoryService;

class StockReservationService
{
    public function __construct(
        private readonly BundleInventoryService $bundles,
        private readonly AvailabilityStatusService $availability,
    ) {}

    public function assertAvailable(Cart $cart): void
    {
        $cart->loadMissing(['items.product', 'bundleItems.bundle.items.product', 'bundleItems.bundle.options.product']);

        abort_if($cart->items->isEmpty() && $cart->bundleItems->isEmpty(), 422, 'Cart is empty.');

        foreach ($cart->items as $item) {
            $product = $item->product;
            abort_unless($product && $product->isPubliclyVisible(), 422, 'Product is no longer available.');
            abort_unless($this->availability->allowsPurchase($product), 422, 'Product is not available for purchase.');
            abort_if($this->availability->requiresStock($product) && $product->quantity < $item->quantity, 422, 'Insufficient stock for '.$product->name.'.');
        }

        foreach ($cart->bundleItems as $bundleItem) {
            $this->bundles->assertAvailable($bundleItem->bundle, $bundleItem->selected_items ?? [], $bundleItem->quantity);
        }
    }

    public function reduce(Cart $cart): void
    {
        $cart->loadMissing(['items.product', 'bundleItems.bundle']);

        $cart->items->each(function (CartItem $item): void {
            $product = $item->product()->lockForUpdate()->firstOrFail();
            if ($this->availability->requiresStock($product)) {
                abort_if($product->quantity < $item->quantity, 422, 'Insufficient stock for '.$product->name.'.');
                $product->decrement('quantity', $item->quantity);
            }

            $this->availability->assign($product->fresh());
        });

        foreach ($cart->bundleItems as $bundleItem) {
            foreach ($bundleItem->selected_items as $line) {
                $product = Product::query()->lockForUpdate()->findOrFail($line['product_id']);
                $requiredQuantity = (int) $line['quantity'] * $bundleItem->quantity;
                if ($this->availability->requiresStock($product)) {
                    abort_if($product->quantity < $requiredQuantity, 422, 'Insufficient stock for '.$product->name.'.');
                    $product->decrement('quantity', $requiredQuantity);
                }

                $this->availability->assign($product->fresh());
            }
        }
    }
}
