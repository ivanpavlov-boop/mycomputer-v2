<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Services\Availability\AvailabilityStatusService;
use App\Services\Products\PublicProductSpecificationService;
use App\Services\Reviews\ReviewStatsService;
use App\Support\Localization\Locales;
use App\Support\Seo\HreflangLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reviewSummary = app(ReviewStatsService::class)->summary($this->resource);
        $availability = app(AvailabilityStatusService::class);
        $locale = Locales::fromRequest($request);
        $specificationGroups = app(PublicProductSpecificationService::class)->groups($this->resource, $locale);

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
            'promo_price' => $this->activeSalePrice(),
            'sale_price_starts_at' => $this->sale_price_starts_at ?? $this->promo_start,
            'sale_price_ends_at' => $this->sale_price_ends_at ?? $this->promo_end,
            'quantity' => $this->quantity,
            'stock_status' => $this->stock_status,
            'availability' => $this->availabilityPayload(),
            'warranty_months' => $this->warranty_months,
            'featured' => $this->featured,
            'new_product' => $this->new_product,
            'bestseller' => $this->bestseller,
            'average_rating' => $reviewSummary['average_rating'],
            'reviews_count' => $reviewSummary['reviews_count'],
            'rating_distribution' => $reviewSummary['rating_distribution'],
            'verified_reviews_count' => $reviewSummary['verified_reviews_count'],
            'brand' => BrandResource::make($this->whenLoaded('brand')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'attributes' => $this->legacyAttributeGroups($specificationGroups),
            'specification_groups' => $specificationGroups,
            'related_products' => ProductCardResource::collection($this->whenLoaded('relatedProducts')),
            'accessory_products' => ProductCardResource::collection($this->whenLoaded('accessoryProducts')),
            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'meta_keywords' => $this->meta_keywords,
                'hreflang' => HreflangLinks::forPath('/p/'.$this->slug, [
                    'bg' => '/p/'.$this->localizedField('slug', 'bg'),
                    'en' => $this->hasLocalizedField('slug', 'en') ? '/p/'.$this->localizedField('slug', 'en', fallbackToPrimary: false) : null,
                ]),
            ],
            'structured_data' => [
                '@type' => 'Product',
                'name' => $this->name,
                'sku' => $this->sku,
                'mpn' => $this->mpn,
                'gtin13' => $this->ean,
                'brand' => $this->brand?->name,
                'offers' => [
                    '@type' => 'Offer',
                    'priceCurrency' => Product::CATALOG_CURRENCY,
                    'price' => $this->activeSalePrice() ?? $this->price,
                    'availability' => $availability->schemaAvailability($this->resource),
                ],
                'aggregateRating' => $reviewSummary['reviews_count'] > 0 ? [
                    '@type' => 'AggregateRating',
                    'ratingValue' => $reviewSummary['average_rating'],
                    'reviewCount' => $reviewSummary['reviews_count'],
                ] : null,
            ],
        ];
    }

    private function availabilityPayload(): array
    {
        return [
            'code' => $this->availabilityStatus?->code ?? $this->stock_status,
            'name' => $this->availabilityStatus?->name ?? $this->stock_status,
            'color' => $this->availabilityStatus?->color ?? 'green',
            'icon' => $this->availabilityStatus?->icon,
            'badge_style' => $this->availabilityStatus?->badge_style ?? 'soft',
            'allow_purchase' => (bool) ($this->availabilityStatus?->allow_purchase ?? $this->stock_status !== 'out_of_stock'),
            'show_stock_quantity' => (bool) ($this->availabilityStatus?->show_stock_quantity ?? false),
            'message' => $this->availability_message,
            'expected_date' => $this->expected_date?->toDateString(),
            'supplier_lead_time_days' => $this->supplier_lead_time_days,
        ];
    }

    /**
     * Keep the existing detail-only attributes field as a safe compatibility
     * projection while specification_groups becomes the public presentation contract.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function legacyAttributeGroups(array $groups): array
    {
        return collect($groups)
            ->map(fn (array $group): array => [
                'group' => [
                    'name' => $group['label'],
                    'slug' => $group['key'],
                ],
                'attributes' => collect($group['items'])
                    ->map(fn (array $item): array => [
                        'attribute' => [
                            'name' => $item['label'],
                            'slug' => $item['key'],
                            'unit' => null,
                        ],
                        'value' => [
                            'value' => $item['display_value'],
                        ],
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
