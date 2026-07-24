<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Localization\Locales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = Locales::fromRequest($request);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'ean' => $this->ean,
            'mpn' => $this->mpn,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'locale' => $locale,
            'localized' => [
                'name' => $this->localizedField('name', $locale, fallbackToPrimary: $locale === Locales::default()),
                'slug' => $this->localizedField('slug', $locale, fallbackToPrimary: $locale === Locales::default()),
                'short_description' => $this->localizedField('short_description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'description' => $this->localizedField('description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'meta_title' => $this->localizedField('meta_title', $locale, fallbackToPrimary: $locale === Locales::default()),
                'meta_description' => $this->localizedField('meta_description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'has_translation' => $locale === Locales::default() || $this->hasLocalizedField('name', $locale),
            ],
            'weight' => $this->weight,
            'currency' => Product::CATALOG_CURRENCY,
            'price' => $this->price,
            'regular_price' => $this->regular_price ?? $this->price,
            'sale_price' => $this->sale_price ?? $this->promo_price,
            'active_sale_price' => $this->activeSalePrice(),
            'effective_price' => $this->effectivePrice(),
            'promo_price' => $this->activeSalePrice(),
            'sale_price_starts_at' => $this->sale_price_starts_at ?? $this->promo_start,
            'sale_price_ends_at' => $this->sale_price_ends_at ?? $this->promo_end,
            'quantity' => $this->quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'stock_status' => $this->stock_status,
            'warranty_months' => $this->warranty_months,
            'featured' => $this->featured,
            'new_product' => $this->new_product,
            'bestseller' => $this->bestseller,
            'brand' => BrandResource::make($this->whenLoaded('brand')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'path' => $image->path,
                'alt_text' => $image->alt_text,
                'is_primary' => $image->is_primary,
            ])),
            'attributes' => $this->whenLoaded('attributes', fn () => $this->attributes->map(fn ($attribute) => [
                'group' => $attribute->attribute?->group?->name,
                'name' => $attribute->attribute?->name,
                'slug' => $attribute->attribute?->slug,
                'value' => $attribute->value?->value ?? $attribute->custom_value,
                'unit' => $attribute->attribute?->unit,
                'is_filterable' => $attribute->is_filterable,
            ])),
            'specifications' => $this->specifications ?? [],
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'published_at' => $this->published_at,
        ];
    }
}
