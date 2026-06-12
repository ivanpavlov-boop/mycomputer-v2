<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductCompareList;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CompareService
{
    public const MAX_PRODUCTS = 4;

    public function resolve(?User $user = null, ?string $sessionId = null): ProductCompareList
    {
        if ($user) {
            return $user->compareLists()
                ->firstOrCreate(['name' => 'Основно сравнение'])
                ->load(['items.product.brand', 'items.product.category', 'items.product.images', 'items.product.attributes.attribute.group', 'items.product.attributes.value', 'items.product.attributes.canonicalAttribute', 'items.product.attributes.canonicalAttributeValue']);
        }

        $sessionId = filled($sessionId) ? $sessionId : (string) Str::uuid();

        return ProductCompareList::query()
            ->firstOrCreate(['session_id' => $sessionId], ['name' => 'Гост сравнение'])
            ->load(['items.product.brand', 'items.product.category', 'items.product.images', 'items.product.attributes.attribute.group', 'items.product.attributes.value', 'items.product.attributes.canonicalAttribute', 'items.product.attributes.canonicalAttributeValue']);
    }

    public function add(ProductCompareList $list, Product $product): ProductCompareList
    {
        $this->assertPublicProduct($product);

        if ($list->items()->where('product_id', $product->id)->exists()) {
            return $this->reload($list);
        }

        abort_if($list->items()->count() >= self::MAX_PRODUCTS, 422, 'Compare list can contain up to 4 products.');

        $list->items()->create([
            'product_id' => $product->id,
            'sort_order' => (int) $list->items()->max('sort_order') + 1,
        ]);

        return $this->reload($list);
    }

    public function remove(ProductCompareList $list, Product $product): ProductCompareList
    {
        $list->items()->where('product_id', $product->id)->delete();

        return $this->reload($list);
    }

    public function clear(ProductCompareList $list): ProductCompareList
    {
        $list->items()->delete();

        return $this->reload($list);
    }

    public function merge(User $user, ?string $sessionId): ProductCompareList
    {
        $userList = $this->resolve($user);

        if (! filled($sessionId)) {
            return $userList;
        }

        $guestList = ProductCompareList::query()
            ->whereNull('user_id')
            ->where('session_id', $sessionId)
            ->with('items.product')
            ->first();

        if (! $guestList) {
            return $userList;
        }

        foreach ($guestList->items as $item) {
            if ($item->product && $userList->items()->count() < self::MAX_PRODUCTS) {
                $this->add($userList, $item->product);
            }
        }

        $guestList->delete();

        return $this->reload($userList);
    }

    public function comparison(ProductCompareList $list): array
    {
        $products = $this->publicProducts($list);

        return $this->buildComparison($products);
    }

    public function buildComparison(Collection $products): array
    {
        $attributeMatrix = $products->mapWithKeys(fn (Product $product): array => [
            $product->id => $product->attributes->mapWithKeys(fn ($assignment): array => [
                $assignment->canonicalAttribute?->code ?? $assignment->attribute?->slug => $assignment->canonicalAttributeValue?->display_value ?? $assignment->value?->value ?? $assignment->custom_value,
            ])->filter()->all(),
        ]);

        $allAttributeKeys = $attributeMatrix->flatMap(fn (array $attributes): array => array_keys($attributes))->unique()->values();
        $shared = [];
        $differences = [];

        foreach ($allAttributeKeys as $key) {
            $values = $attributeMatrix->map(fn (array $attributes) => $attributes[$key] ?? null)->filter();

            if ($values->count() === $products->count() && $values->unique()->count() === 1) {
                $shared[$key] = $values->first();
            } else {
                $differences[$key] = $values->all();
            }
        }

        return [
            'products' => $products,
            'shared_attributes' => $shared,
            'differences' => $differences,
            'prices' => $products->mapWithKeys(fn (Product $product): array => [$product->id => $product->promo_price ?? $product->price]),
            'stock_statuses' => $products->mapWithKeys(fn (Product $product): array => [$product->id => $product->stock_status]),
        ];
    }

    private function publicProducts(ProductCompareList $list): Collection
    {
        return $list->items
            ->pluck('product')
            ->filter(fn (?Product $product): bool => $product !== null && $product->active && $product->published_at !== null)
            ->values();
    }

    private function reload(ProductCompareList $list): ProductCompareList
    {
        return $list->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'items.product.attributes.attribute.group', 'items.product.attributes.value', 'items.product.attributes.canonicalAttribute', 'items.product.attributes.canonicalAttributeValue']);
    }

    private function assertPublicProduct(Product $product): void
    {
        abort_unless($product->active && $product->published_at !== null, 422, 'Product is not available.');
    }
}
