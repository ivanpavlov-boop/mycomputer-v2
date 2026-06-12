<?php

namespace App\Services\Bundles;

use App\Models\Cart;
use App\Models\CartBundleItem;
use App\Models\Order;
use App\Models\ProductBundle;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Support\Facades\DB;

class BundleCartService
{
    public function __construct(
        private readonly BundlePricingService $pricing,
        private readonly BundleInventoryService $inventory,
        private readonly MarketingEventService $events,
    ) {}

    public function add(Cart $cart, ProductBundle $bundle, array $selectedItems, int $quantity): CartBundleItem
    {
        $this->inventory->assertAvailable($bundle, $selectedItems, $quantity);
        $pricing = $this->pricing->calculate($bundle, $selectedItems);

        $item = $cart->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => $pricing['selected_items'],
            'quantity' => $quantity,
            'unit_price' => $pricing['unit_price'],
            'total_price' => $pricing['unit_price'] * $quantity,
        ]);

        $this->events->log('bundle_added_to_cart', 'internal', [
            'bundle_id' => $bundle->id,
            'bundle_name' => $bundle->name,
            'quantity' => $quantity,
            'total_price' => $pricing['unit_price'] * $quantity,
            'savings' => $pricing['savings'],
        ], $cart->user, $cart->session_id);

        return $item->fresh('bundle');
    }

    public function update(CartBundleItem $item, array $selectedItems, int $quantity): CartBundleItem
    {
        $bundle = $item->bundle()->with(['items.product', 'options.product'])->firstOrFail();
        $this->inventory->assertAvailable($bundle, $selectedItems, $quantity);
        $pricing = $this->pricing->calculate($bundle, $selectedItems);

        $item->update([
            'selected_items' => $pricing['selected_items'],
            'quantity' => $quantity,
            'unit_price' => $pricing['unit_price'],
            'total_price' => $pricing['unit_price'] * $quantity,
        ]);

        return $item->fresh('bundle');
    }

    public function remove(CartBundleItem $item): void
    {
        $item->delete();
    }

    public function convertToOrder(Cart $cart, Order $order): void
    {
        DB::transaction(function () use ($cart, $order): void {
            foreach ($cart->bundleItems()->with('bundle')->get() as $bundleItem) {
                $order->bundleItems()->create([
                    'product_bundle_id' => $bundleItem->product_bundle_id,
                    'bundle_name' => $bundleItem->bundle?->name ?? 'Bundle',
                    'selected_items' => $bundleItem->selected_items,
                    'quantity' => $bundleItem->quantity,
                    'unit_price' => $bundleItem->unit_price,
                    'total_price' => $bundleItem->total_price,
                ]);

                foreach ($bundleItem->selected_items as $line) {
                    $order->items()->create([
                        'product_id' => $line['product_id'],
                        'product_name' => $line['name'],
                        'sku' => $line['sku'],
                        'quantity' => $line['quantity'] * $bundleItem->quantity,
                        'unit_price' => 0,
                        'total_price' => 0,
                    ]);
                }

                $this->events->log('bundle_purchased', 'internal', [
                    'bundle_id' => $bundleItem->product_bundle_id,
                    'bundle_name' => $bundleItem->bundle?->name ?? 'Bundle',
                    'order_id' => $order->id,
                    'total_price' => $bundleItem->total_price,
                ], $cart->user, $cart->session_id);
            }
        });
    }
}
