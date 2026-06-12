<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class BlogPostDetailResource extends BlogPostResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'content' => $this->content,
            'related_products' => ProductCardResource::collection($this->whenLoaded('relatedProducts')),
            'related_categories' => CategoryResource::collection($this->whenLoaded('relatedCategories')),
            'related_brands' => BrandResource::collection($this->whenLoaded('relatedBrands')),
            'structured_data' => [
                '@type' => 'Article',
                'headline' => $this->title,
                'description' => $this->meta_description ?? $this->excerpt,
                'image' => $this->featured_image,
                'datePublished' => $this->published_at?->toAtomString(),
                'author' => $this->author?->name,
            ],
        ];
    }
}
