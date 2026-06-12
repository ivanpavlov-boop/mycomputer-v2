<?php

namespace App\Http\Resources;

use App\Support\Content\ResponsiveBlockDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeoPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'type' => $this->type,
            'content' => ResponsiveBlockDefaults::normalizeContent($this->content),
            'responsive_profiles' => ResponsiveBlockDefaults::profiles(),
            'preview_modes' => array_keys(ResponsiveBlockDefaults::profiles()),
            'published_at' => $this->published_at,
            'related_category' => CategoryResource::make($this->whenLoaded('relatedCategory')),
            'related_brand' => BrandResource::make($this->whenLoaded('relatedBrand')),
            'related_products' => ProductCardResource::collection($this->whenLoaded('relatedProducts')),
            'related_categories' => CategoryResource::collection($this->whenLoaded('relatedCategories')),
            'related_brands' => BrandResource::collection($this->whenLoaded('relatedBrands')),
            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'meta_keywords' => $this->meta_keywords,
                'canonical_url' => $this->canonical_url,
                'og_title' => $this->og_title,
                'og_description' => $this->og_description,
                'og_image' => $this->og_image,
            ],
            'schema_type' => $this->schema_type,
            'schema_data' => $this->schema_data,
        ];
    }
}
