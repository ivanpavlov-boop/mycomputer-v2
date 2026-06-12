<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddWishlistItemRequest;
use App\Http\Requests\Api\V1\StoreWishlistRequest;
use App\Http\Requests\Api\V1\ToggleWishlistItemRequest;
use App\Http\Requests\Api\V1\UpdateWishlistRequest;
use App\Http\Resources\WishlistItemResource;
use App\Http\Resources\WishlistResource;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\Wishlists\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlists) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->wishlists->defaultFor($request->user());

        return WishlistResource::collection(
            $request->user()
                ->wishlists()
                ->withCount('items')
                ->with(['items.product.brand', 'items.product.category', 'items.product.images'])
                ->orderByDesc('is_default')
                ->latest()
                ->get()
        );
    }

    public function store(StoreWishlistRequest $request): WishlistResource
    {
        return WishlistResource::make(
            $this->wishlists->create($request->user(), $request->validated())->loadCount('items')
        );
    }

    public function update(UpdateWishlistRequest $request, Wishlist $wishlist): WishlistResource
    {
        $this->authorizeWishlist($request, $wishlist);

        return WishlistResource::make($this->wishlists->update($wishlist, $request->validated())->loadCount('items'));
    }

    public function destroy(Request $request, Wishlist $wishlist): JsonResponse
    {
        $this->authorizeWishlist($request, $wishlist);
        abort_if($wishlist->is_default, 422, 'Default wishlist cannot be deleted.');

        $wishlist->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function items(Request $request, Wishlist $wishlist): AnonymousResourceCollection
    {
        $this->authorizeWishlist($request, $wishlist);

        return WishlistItemResource::collection(
            $wishlist->items()
                ->with(['product.brand', 'product.category', 'product.images'])
                ->latest()
                ->get()
        );
    }

    public function addItem(AddWishlistItemRequest $request, Wishlist $wishlist): WishlistResource
    {
        $this->authorizeWishlist($request, $wishlist);
        $product = Product::query()->findOrFail($request->integer('product_id'));

        return WishlistResource::make($this->wishlists->add($wishlist, $product)->loadCount('items'));
    }

    public function removeItem(Request $request, Wishlist $wishlist, Product $product): WishlistResource
    {
        $this->authorizeWishlist($request, $wishlist);

        return WishlistResource::make($this->wishlists->remove($wishlist, $product)->loadCount('items'));
    }

    public function toggle(ToggleWishlistItemRequest $request): JsonResponse
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));
        $result = $this->wishlists->toggle($request->user(), $product);

        return response()->json([
            'data' => [
                'added' => $result['added'],
                'wishlist' => WishlistResource::make($result['wishlist']->loadCount('items'))->resolve(),
            ],
        ]);
    }

    private function authorizeWishlist(Request $request, Wishlist $wishlist): void
    {
        abort_unless($wishlist->user_id === $request->user()->id, 404);
    }
}
