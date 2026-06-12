<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\ProductSyncLog;
use App\Models\SupplierProduct;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Attributes\CatalogAttributeWriter;
use App\Services\Attributes\SupplierAttributeExtractionService;
use Illuminate\Support\Str;

class ProductSyncService
{
    public function __construct(
        private readonly AvailabilityStatusMapper $availabilityMapper,
        private readonly CatalogAttributeWriter $catalogAttributeWriter,
        private readonly SupplierAttributeExtractionService $attributeExtraction,
    ) {}

    public function sync(SupplierProduct $supplierProduct, ?string $strategy = null): ProductSyncLog
    {
        $supplierProduct->loadMissing('supplier');

        $strategy ??= $supplierProduct->supplier?->sync_strategy ?: 'lowest_price';
        $identifiers = $this->identifiers($supplierProduct);

        if ($identifiers === []) {
            return $this->skip($supplierProduct, $strategy, 'Supplier product has no SKU, EAN or MPN identifiers.');
        }

        if ($this->hasDuplicateSupplierRows($supplierProduct)) {
            return $this->mark($supplierProduct, $strategy, 'duplicate', 'duplicate', 'Duplicate supplier staging rows found for the same identifiers.', [
                'identifiers' => $identifiers,
            ]);
        }

        [$product, $matchType, $conflictCount] = $this->matchProduct($supplierProduct);

        if ($conflictCount > 1) {
            return $this->mark($supplierProduct, $strategy, 'conflict', 'conflict', 'Multiple catalog products match this supplier product.', [
                'identifiers' => $identifiers,
                'match_count' => $conflictCount,
            ]);
        }

        $before = $product?->only(['id', 'sku', 'ean', 'mpn', 'price', 'quantity', 'supplier_id', 'supplier_sku']);

        if (! $product) {
            $product = $this->createProduct($supplierProduct);
            $matchType = 'created';
            $action = 'created';
        } else {
            $action = 'updated';
        }

        $offer = $this->upsertSupplierOffer($product, $supplierProduct);
        $selectedOffer = $this->selectOffer($product, $strategy);

        $availability = $this->availabilityMapper->mapWithFallback(
            'supplier',
            $supplierProduct->supplier?->company_name,
            $supplierProduct->external_availability_status,
            $selectedOffer->quantity,
        );

        $updates = [
            'supplier_id' => $selectedOffer->supplier_id,
            'supplier_sku' => $selectedOffer->supplier_sku,
            'purchase_price' => $selectedOffer->price,
            'price' => $selectedOffer->price ?? $product->price,
            'quantity' => $selectedOffer->quantity,
            'availability_status_id' => $product->manual_override ? $product->availability_status_id : $availability?->id,
            'stock_status' => $product->manual_override ? $product->stock_status : ($availability?->code ?? ($selectedOffer->quantity > 0 ? 'in_stock' : 'out_of_stock')),
            'external_availability_status' => $supplierProduct->external_availability_status,
            'external_availability_label' => $supplierProduct->external_availability_label,
            'source_payload' => [
                'sync_strategy' => $strategy,
                'selected_supplier_offer_id' => $selectedOffer->id,
                'last_supplier_product_id' => $supplierProduct->id,
            ],
        ];

        $product->update($updates);

        ProductSupplierOffer::query()
            ->where('product_id', $product->id)
            ->update(['is_preferred' => false]);

        $selectedOffer->update(['is_preferred' => true]);

        $supplierProduct->update([
            'product_id' => $product->id,
            'status' => 'synced',
            'synced_at' => now(),
            'mapping_notes' => 'Synced into catalog product. Product was not deleted or replaced.',
        ]);

        $supplierProduct = $this->extractRawAttributesIfNeeded($supplierProduct->fresh(['attributes', 'supplier']));
        $syncedAttributes = $this->syncAttributes($supplierProduct, $product);

        return ProductSyncLog::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_product_id' => $supplierProduct->id,
            'match_type' => $matchType,
            'strategy' => $strategy,
            'action' => $action,
            'status' => 'synced',
            'message' => "Supplier product {$action} catalog product using {$strategy} strategy.",
            'before_data' => $before,
            'after_data' => $product->fresh()->only(['id', 'sku', 'ean', 'mpn', 'price', 'quantity', 'supplier_id', 'supplier_sku']),
            'context' => [
                'supplier_offer_id' => $offer->id,
                'selected_supplier_offer_id' => $selectedOffer->id,
                'identifiers' => $identifiers,
                'availability_status_id' => $availability?->id,
                'external_availability_status' => $supplierProduct->external_availability_status,
                'synced_attributes' => $syncedAttributes,
            ],
        ]);
    }

    /**
     * @return array{0: Product|null, 1: string|null, 2: int}
     */
    protected function matchProduct(SupplierProduct $supplierProduct): array
    {
        $matches = collect();
        $matchType = null;

        foreach ([
            'sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
        ] as $field => $value) {
            if (blank($value)) {
                continue;
            }

            $fieldMatches = Product::query()->where($field, $value)->get();

            if ($fieldMatches->isNotEmpty() && $matchType === null) {
                $matchType = $field;
            }

            $matches = $matches->merge($fieldMatches);
        }

        $matches = $matches->unique('id')->values();

        return [$matches->first(), $matchType, $matches->count()];
    }

    protected function createProduct(SupplierProduct $supplierProduct): Product
    {
        $name = $supplierProduct->name ?: $supplierProduct->supplier_sku ?: 'Supplier Product '.$supplierProduct->id;
        $sku = $supplierProduct->supplier_sku ?: $supplierProduct->ean ?: $supplierProduct->mpn ?: 'SP-'.$supplierProduct->id;

        $availability = $this->availabilityMapper->mapWithFallback(
            'supplier',
            $supplierProduct->supplier?->company_name,
            $supplierProduct->external_availability_status,
            $supplierProduct->quantity,
        );

        return Product::query()->create([
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'sku' => $this->uniqueSku($sku),
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'short_description' => null,
            'description' => null,
            'purchase_price' => $supplierProduct->price,
            'price' => $supplierProduct->price ?? 0,
            'quantity' => $supplierProduct->quantity ?? 0,
            'reserved_quantity' => 0,
            'availability_status_id' => $availability?->id,
            'stock_status' => $availability?->code ?? (($supplierProduct->quantity ?? 0) > 0 ? 'in_stock' : 'out_of_stock'),
            'product_status' => 'draft',
            'external_availability_status' => $supplierProduct->external_availability_status,
            'external_availability_label' => $supplierProduct->external_availability_label,
            'active' => false,
            'new_product' => true,
            'source_payload' => [
                'created_from_supplier_product_id' => $supplierProduct->id,
                'needs_enrichment' => true,
                'needs_category_mapping' => true,
                'needs_brand_mapping' => true,
            ],
        ]);
    }

    protected function upsertSupplierOffer(Product $product, SupplierProduct $supplierProduct): ProductSupplierOffer
    {
        return ProductSupplierOffer::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'supplier_id' => $supplierProduct->supplier_id,
                'supplier_sku' => $supplierProduct->supplier_sku,
            ],
            [
                'supplier_product_id' => $supplierProduct->id,
                'price' => $supplierProduct->price,
                'quantity' => $supplierProduct->quantity ?? 0,
                'currency' => $supplierProduct->currency,
                'supplier_priority' => $supplierProduct->supplier?->priority ?? 100,
                'last_seen_at' => now(),
            ],
        );
    }

    protected function syncAttributes(SupplierProduct $supplierProduct, Product $product): int
    {
        $count = 0;

        foreach ($supplierProduct->attributes()->where('status', 'mapped')->get() as $attribute) {
            $attribute->update(['product_id' => $product->id]);

            if ($this->catalogAttributeWriter->writeMappedSupplierAttribute($attribute, $product)) {
                $count++;
            }
        }

        return $count;
    }

    protected function extractRawAttributesIfNeeded(SupplierProduct $supplierProduct): SupplierProduct
    {
        if ($supplierProduct->attributes()->exists()) {
            return $supplierProduct;
        }

        $attributes = $this->attributeExtraction->extractFromArray($supplierProduct->raw_data ?? []);

        if ($attributes !== []) {
            $this->attributeExtraction->stage(
                $supplierProduct,
                $attributes,
                'supplier',
                $supplierProduct->supplier?->company_name,
            );
        }

        return $supplierProduct->fresh(['attributes', 'supplier']);
    }

    protected function selectOffer(Product $product, string $strategy): ProductSupplierOffer
    {
        $query = $product->supplierOffers()->where('quantity', '>', 0);

        if (! $query->exists()) {
            $query = $product->supplierOffers();
        }

        return match ($strategy) {
            'preferred_supplier' => $query->orderBy('supplier_priority')->orderBy('price')->firstOrFail(),
            default => $query->orderBy('price')->orderBy('supplier_priority')->firstOrFail(),
        };
    }

    protected function hasDuplicateSupplierRows(SupplierProduct $supplierProduct): bool
    {
        $query = SupplierProduct::query()
            ->where('id', '!=', $supplierProduct->id)
            ->where('supplier_id', $supplierProduct->supplier_id)
            ->whereIn('status', ['new', 'synced']);

        $query->where(function ($nested) use ($supplierProduct): void {
            foreach (array_filter([
                'supplier_sku' => $supplierProduct->supplier_sku,
                'ean' => $supplierProduct->ean,
                'mpn' => $supplierProduct->mpn,
            ], fn ($value): bool => filled($value)) as $field => $value) {
                $nested->orWhere($field, $value);
            }
        });

        return $query->exists();
    }

    protected function skip(SupplierProduct $supplierProduct, string $strategy, string $message): ProductSyncLog
    {
        return $this->mark($supplierProduct, $strategy, 'skipped', 'skipped', $message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function mark(SupplierProduct $supplierProduct, string $strategy, string $action, string $status, string $message, array $context = []): ProductSyncLog
    {
        $supplierProduct->update([
            'status' => $status,
            'synced_at' => now(),
            'mapping_notes' => $message,
        ]);

        return ProductSyncLog::query()->create([
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_product_id' => $supplierProduct->id,
            'match_type' => null,
            'strategy' => $strategy,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'context' => $context,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function identifiers(SupplierProduct $supplierProduct): array
    {
        return array_filter([
            'sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
        ], fn ($value): bool => filled($value));
    }

    protected function uniqueSku(string $sku): string
    {
        $base = Str::upper(Str::slug($sku, '-'));
        $candidate = $base;
        $counter = 2;

        while (Product::query()->where('sku', $candidate)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $candidate = $base;
        $counter = 2;

        while (Product::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
