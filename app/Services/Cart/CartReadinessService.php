<?php

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Exceptions\CartProductUnavailableException;
use App\Exceptions\CartQuantityUnavailableException;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Services\Availability\AvailabilityStatusService;
use Illuminate\Support\Collection;

class CartReadinessService
{
    private const PRODUCT_ISSUE_ORDER = [
        'product_missing',
        'product_deleted',
        'product_inactive',
        'product_unpublished',
        'product_status_inactive',
        'product_slug_missing',
        'product_category_unavailable',
        'product_purchase_disabled',
        'insufficient_stock',
    ];

    private const ISSUE_MESSAGES = [
        'cart_inactive' => 'Cart is not active.',
        'cart_no_paid_items' => 'Cart does not contain purchasable items.',
        'product_missing' => 'Product is no longer available.',
        'product_deleted' => 'Product is no longer available.',
        'product_inactive' => 'Product is no longer available.',
        'product_unpublished' => 'Product is no longer available.',
        'product_status_inactive' => 'Product is no longer available.',
        'product_slug_missing' => 'Product is no longer available.',
        'product_category_unavailable' => 'Product is no longer available.',
        'product_purchase_disabled' => 'Product is not available for purchase.',
        'insufficient_stock' => 'Requested quantity exceeds current availability.',
        'bundle_unavailable' => 'Bundle is no longer available.',
        'bundle_selection_invalid' => 'Bundle selection is no longer available.',
        'bundle_product_unavailable' => 'Bundle contains an unavailable product.',
        'bundle_insufficient_stock' => 'Bundle quantity exceeds current availability.',
    ];

    public function __construct(
        private readonly AvailabilityStatusService $availability,
    ) {}

    public function assess(Cart $cart): CartReadinessResult
    {
        $cart->loadMissing(['items', 'bundleItems']);

        $bundles = $this->loadBundles($cart->bundleItems->pluck('product_bundle_id')->all());
        $productIds = $cart->items->pluck('product_id');

        foreach ($cart->bundleItems as $bundleItem) {
            $bundle = $bundles->get((int) $bundleItem->product_bundle_id);

            if ($bundle !== null) {
                $productIds = $productIds->merge(
                    collect($this->bundleReferences($bundle, $bundleItem->selected_items ?? [])['lines'])
                        ->pluck('product_id'),
                );
            }
        }

        $products = $this->loadProducts($productIds->all());
        $regularLines = [];
        $bundleLines = [];

        foreach ($cart->items as $item) {
            $readiness = $this->assessPreparedProduct(
                $products->get((int) $item->product_id),
                (int) $item->product_id,
                (int) $item->quantity,
            );
            $regularLines[$item->id] = $readiness;
            $item->setRelation('readiness', $readiness->toArray());
        }

        foreach ($cart->bundleItems as $item) {
            $readiness = $this->assessPreparedBundle(
                $bundles->get((int) $item->product_bundle_id),
                $item->selected_items ?? [],
                (int) $item->quantity,
                $products,
            );
            $bundleLines[$item->id] = $readiness;
            $item->setRelation('readiness', $readiness->toArray());
        }

        $issues = [];
        $isActive = $cart->status === CartStatus::Active->value
            && ($cart->expires_at === null || $cart->expires_at->isFuture());
        $hasPaidContent = $cart->items->contains(fn ($item): bool => ! $item->is_gift)
            || $cart->bundleItems->isNotEmpty();

        if (! $isActive) {
            $issues[] = $this->issue('cart_inactive');
        }

        if (! $hasPaidContent) {
            $issues[] = $this->issue('cart_no_paid_items');
        }

        $canCheckout = $issues === []
            && collect($regularLines)->every->canCheckout
            && collect($bundleLines)->every->canCheckout;

        $result = new CartReadinessResult(
            cart: $cart,
            canCheckout: $canCheckout,
            issues: $issues,
            regularLines: $regularLines,
            bundleLines: $bundleLines,
        );
        $cart->setRelation('readiness', $result->toArray());

        return $result;
    }

    public function assessProduct(?Product $product, int $productId, int $requestedQuantity): CartLineReadiness
    {
        $prepared = $product !== null
            && $product->relationLoaded('category')
            && $product->relationLoaded('availabilityStatus')
                ? $product
                : $this->loadProducts([$productId])->get($productId);

        return $this->assessPreparedProduct($prepared, $productId, $requestedQuantity);
    }

    public function assertProductCanBeCartQuantity(Product $product, int $requestedQuantity): Product
    {
        $readiness = $this->assessProduct($product, (int) $product->getKey(), $requestedQuantity);
        $this->throwForUnavailableProduct($readiness, (int) $product->getKey(), $requestedQuantity);

        return $product;
    }

    public function assertProductIdCanBeCartQuantity(int $productId, int $requestedQuantity): Product
    {
        $product = $this->loadProducts([$productId])->get($productId);
        $readiness = $this->assessPreparedProduct($product, $productId, $requestedQuantity);
        $this->throwForUnavailableProduct($readiness, $productId, $requestedQuantity);

        return $product;
    }

    /**
     * @param  array<int, int>  $requestedQuantities
     * @return Collection<int, Product>
     */
    public function assertProductQuantities(array $requestedQuantities): Collection
    {
        ksort($requestedQuantities);
        $products = $this->loadProducts(array_keys($requestedQuantities));

        foreach ($requestedQuantities as $productId => $quantity) {
            $readiness = $this->assessPreparedProduct(
                $products->get((int) $productId),
                (int) $productId,
                (int) $quantity,
            );
            $this->throwForUnavailableProduct($readiness, (int) $productId, (int) $quantity);
        }

        return $products;
    }

    public function assessBundle(
        ?ProductBundle $bundle,
        array $selectedItems,
        int $bundleQuantity,
    ): CartLineReadiness {
        $prepared = $bundle === null
            ? null
            : $this->loadBundles([$bundle->getKey()])->get($bundle->getKey());
        $references = $prepared === null
            ? ['issues' => [], 'lines' => []]
            : $this->bundleReferences($prepared, $selectedItems);
        $products = $this->loadProducts(collect($references['lines'])->pluck('product_id')->all());

        return $this->assessPreparedBundle(
            $prepared,
            $selectedItems,
            $bundleQuantity,
            $products,
        );
    }

    private function assessPreparedProduct(
        ?Product $product,
        int $productId,
        int $requestedQuantity,
    ): CartLineReadiness {
        $requestedQuantity = max(0, $requestedQuantity);

        if ($product === null) {
            return new CartLineReadiness(
                isEligible: false,
                canCheckout: false,
                issues: [$this->issue('product_missing')],
                stock: $this->unknownStock($requestedQuantity),
            );
        }

        if ($product->trashed()) {
            return new CartLineReadiness(
                isEligible: false,
                canCheckout: false,
                issues: [$this->issue('product_deleted')],
                stock: $this->unknownStock($requestedQuantity),
            );
        }

        $issues = [];

        if (! $product->active) {
            $issues[] = $this->issue('product_inactive');
        }

        if ($product->published_at === null || $product->workflow_status !== Product::WORKFLOW_PUBLISHED) {
            $issues[] = $this->issue('product_unpublished');
        }

        if ($product->product_status !== 'active') {
            $issues[] = $this->issue('product_status_inactive');
        }

        if (blank($product->slug)) {
            $issues[] = $this->issue('product_slug_missing');
        }

        $category = $product->getRelation('category');
        if ($category === null || $category->trashed() || ! $category->is_active) {
            $issues[] = $this->issue('product_category_unavailable');
        }

        if (! $this->availability->allowsPurchase($product)) {
            $issues[] = $this->issue('product_purchase_disabled');
        }

        $tracked = $this->availability->requiresStock($product);
        $availableQuantity = $tracked ? max(0, (int) $product->quantity) : null;
        $maxPurchasableQuantity = $tracked
            ? min(CartService::MAX_QUANTITY, $availableQuantity)
            : CartService::MAX_QUANTITY;
        $isSufficient = $requestedQuantity >= 1 && $requestedQuantity <= $maxPurchasableQuantity;

        if (! $isSufficient) {
            $issues[] = $this->issue('insufficient_stock');
        }

        $issues = $this->orderedProductIssues($issues);
        $isEligible = $product->isPubliclyVisible()
            && $this->availability->allowsPurchase($product);

        return new CartLineReadiness(
            isEligible: $isEligible,
            canCheckout: $isEligible && $isSufficient,
            issues: $issues,
            stock: [
                'tracked' => $tracked,
                'requested_quantity' => $requestedQuantity,
                'available_quantity' => $availableQuantity,
                'max_purchasable_quantity' => $maxPurchasableQuantity,
                'is_sufficient' => $isSufficient,
            ],
        );
    }

    private function assessPreparedBundle(
        ?ProductBundle $bundle,
        array $selectedItems,
        int $bundleQuantity,
        Collection $products,
    ): CartLineReadiness {
        $bundleQuantity = max(0, $bundleQuantity);

        if ($bundle === null || $bundle->trashed()) {
            return new CartLineReadiness(
                isEligible: false,
                canCheckout: false,
                issues: [$this->issue('bundle_unavailable')],
                stock: $this->unknownStock($bundleQuantity),
            );
        }

        $issues = [];
        $isAvailable = $bundle->status === 'active'
            && ($bundle->starts_at === null || $bundle->starts_at->lte(now()))
            && ($bundle->ends_at === null || $bundle->ends_at->gte(now()));

        if (! $isAvailable) {
            $issues[] = $this->issue('bundle_unavailable');
        }

        $references = $this->bundleReferences($bundle, $selectedItems);
        $issues = array_merge($issues, $references['issues']);
        $components = [];
        $hasUnavailableProduct = false;
        $hasInsufficientStock = false;
        $maxBundleQuantity = CartService::MAX_QUANTITY;

        foreach ($references['lines'] as $line) {
            $componentQuantity = max(1, (int) $line['quantity']);
            $requiredQuantity = $componentQuantity * $bundleQuantity;
            $productId = (int) $line['product_id'];
            $productReadiness = $this->assessPreparedProduct(
                $products->get($productId),
                $productId,
                $requiredQuantity,
            );
            $tracked = $productReadiness->stock['tracked'];
            $available = $productReadiness->stock['available_quantity'];
            $componentMaxBundleQuantity = $tracked === true
                ? min(CartService::MAX_QUANTITY, intdiv((int) $available, $componentQuantity))
                : CartService::MAX_QUANTITY;

            $hasUnavailableProduct = $hasUnavailableProduct || ! $productReadiness->isEligible;
            $hasInsufficientStock = $hasInsufficientStock
                || $productReadiness->hasIssue('insufficient_stock');
            $maxBundleQuantity = min($maxBundleQuantity, $componentMaxBundleQuantity);
            $components[] = [
                'product_id' => $productId,
                'tracked' => $tracked,
                'component_quantity' => $componentQuantity,
                'requested_quantity' => $requiredQuantity,
                'available_quantity' => $available,
                'max_bundle_quantity' => $componentMaxBundleQuantity,
                'is_sufficient' => $productReadiness->stock['is_sufficient'],
            ];
        }

        if ($hasUnavailableProduct || $references['lines'] === []) {
            $issues[] = $this->issue('bundle_product_unavailable');
        }

        if ($hasInsufficientStock || $bundleQuantity < 1 || $bundleQuantity > CartService::MAX_QUANTITY) {
            $issues[] = $this->issue('bundle_insufficient_stock');
        }

        $issues = collect($issues)->unique('code')->values()->all();
        $hasEligibilityIssue = collect($issues)->contains(
            fn (array $issue): bool => $issue['code'] !== 'bundle_insufficient_stock',
        );
        $isSufficient = ! collect($issues)->contains(
            fn (array $issue): bool => $issue['code'] === 'bundle_insufficient_stock',
        );

        return new CartLineReadiness(
            isEligible: ! $hasEligibilityIssue,
            canCheckout: $issues === [],
            issues: $issues,
            stock: [
                'tracked' => collect($components)->contains('tracked', true),
                'requested_quantity' => $bundleQuantity,
                'available_quantity' => null,
                'max_purchasable_quantity' => $maxBundleQuantity,
                'is_sufficient' => $isSufficient,
                'components' => $components,
            ],
        );
    }

    private function bundleReferences(ProductBundle $bundle, array $selectedItems): array
    {
        $selected = collect($selectedItems);
        $issues = [];
        $lines = [];

        foreach ($selected as $selectedItem) {
            $group = $selectedItem['component_group'] ?? null;
            $groupOptions = $bundle->options->where('component_group', $group);

            if (
                $groupOptions->isNotEmpty()
                && ! $groupOptions->contains(
                    fn ($option): bool => (int) $option->product_id === (int) ($selectedItem['product_id'] ?? 0),
                )
            ) {
                $issues[] = $this->issue('bundle_selection_invalid');
            }
        }

        foreach ($bundle->items as $item) {
            if ($item->product_id !== null) {
                $lines[] = [
                    'product_id' => (int) $item->product_id,
                    'quantity' => max(1, (int) $item->quantity),
                ];

                continue;
            }

            if (! $item->is_required) {
                continue;
            }

            $selection = $selected->firstWhere('component_group', $item->component_group);
            $hasValidSelection = $selection !== null
                && $bundle->options->contains(
                    fn ($option): bool => $option->component_group === $item->component_group
                        && (int) $option->product_id === (int) ($selection['product_id'] ?? 0),
                );
            $hasDefault = $bundle->options->contains(
                fn ($option): bool => $option->component_group === $item->component_group
                    && $option->is_default,
            );

            if (! $hasValidSelection && ! $hasDefault) {
                $issues[] = $this->issue('bundle_selection_invalid');
            }
        }

        foreach ($bundle->options->groupBy('component_group') as $group => $options) {
            $selection = $selected->firstWhere('component_group', $group);
            $option = $selection !== null
                ? $options->firstWhere('product_id', (int) ($selection['product_id'] ?? 0))
                : $options->firstWhere('is_default', true);

            if ($option !== null) {
                $lines[] = [
                    'product_id' => (int) $option->product_id,
                    'quantity' => 1,
                ];
            }
        }

        return [
            'issues' => collect($issues)->unique('code')->values()->all(),
            'lines' => $lines,
        ];
    }

    private function throwForUnavailableProduct(
        CartLineReadiness $readiness,
        int $productId,
        int $requestedQuantity,
    ): void {
        if (! $readiness->isEligible) {
            throw new CartProductUnavailableException(
                productId: $productId,
                issues: $readiness->issues,
            );
        }

        if (! $readiness->stock['is_sufficient']) {
            throw new CartQuantityUnavailableException(
                productId: $productId,
                requestedQuantity: $requestedQuantity,
                availableQuantity: $readiness->stock['available_quantity'],
                maxPurchasableQuantity: $readiness->stock['max_purchasable_quantity'],
                issues: $readiness->issues,
            );
        }
    }

    private function orderedProductIssues(array $issues): array
    {
        return collect($issues)
            ->sortBy(fn (array $issue): int => array_search($issue['code'], self::PRODUCT_ISSUE_ORDER, true))
            ->values()
            ->all();
    }

    private function issue(string $code): array
    {
        return [
            'code' => $code,
            'message' => self::ISSUE_MESSAGES[$code],
        ];
    }

    private function unknownStock(int $requestedQuantity): array
    {
        return [
            'tracked' => null,
            'requested_quantity' => $requestedQuantity,
            'available_quantity' => null,
            'max_purchasable_quantity' => null,
            'is_sufficient' => false,
        ];
    }

    /**
     * @return Collection<int, Product>
     */
    private function loadProducts(array $productIds): Collection
    {
        $productIds = collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->withTrashed()
            ->with([
                'category' => fn ($query) => $query->withTrashed(),
                'availabilityStatus',
            ])
            ->whereKey($productIds)
            ->get()
            ->keyBy(fn (Product $product): int => (int) $product->getKey());
    }

    /**
     * @return Collection<int, ProductBundle>
     */
    private function loadBundles(array $bundleIds): Collection
    {
        $bundleIds = collect($bundleIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($bundleIds->isEmpty()) {
            return collect();
        }

        return ProductBundle::query()
            ->withTrashed()
            ->with(['items', 'options'])
            ->whereKey($bundleIds)
            ->get()
            ->keyBy(fn (ProductBundle $bundle): int => (int) $bundle->getKey());
    }
}
