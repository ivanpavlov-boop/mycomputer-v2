<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCartBundleRequest;
use App\Http\Requests\Api\V1\UpdateCartBundleRequest;
use App\Http\Resources\CartResource;
use App\Models\CartBundleItem;
use App\Models\ProductBundle;
use App\Services\Bundles\BundleCartService;
use App\Services\Cart\CartContextResolver;
use Illuminate\Http\Request;

class CartBundleController extends Controller
{
    public function __construct(private readonly CartContextResolver $carts, private readonly BundleCartService $bundles) {}

    public function store(StoreCartBundleRequest $request): CartResource
    {
        $cart = $this->carts->resolve($request);
        $bundle = ProductBundle::query()->with(['items.product', 'options.product'])->findOrFail($request->integer('bundle_id'));
        $this->bundles->add($cart, $bundle, $request->validated('selected_items') ?? [], $request->integer('quantity'));

        return CartResource::make($cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'bundleItems.bundle']));
    }

    public function update(UpdateCartBundleRequest $request, CartBundleItem $bundle): CartResource
    {
        $cart = $this->carts->resolve($request);
        abort_unless($bundle->cart_id === $cart->id, 404);
        $this->bundles->update($bundle, $request->validated('selected_items') ?? [], $request->integer('quantity'));

        return CartResource::make($cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'bundleItems.bundle']));
    }

    public function destroy(Request $request, CartBundleItem $bundle): CartResource
    {
        $cart = $this->carts->resolve($request);
        abort_unless($bundle->cart_id === $cart->id, 404);
        $this->bundles->remove($bundle);

        return CartResource::make($cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'bundleItems.bundle']));
    }
}
