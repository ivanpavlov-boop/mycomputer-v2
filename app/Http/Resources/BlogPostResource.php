<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'featured_image' => $this->featured_image,
            'published_at' => $this->published_at,
            'reading_time' => $this->reading_time,
            'views_count' => $this->views_count,
            'category' => BlogCategoryResource::make($this->whenLoaded('category')),
            'tags' => BlogTagResource::collection($this->whenLoaded('tags')),
            'author' => UserResource::make($this->whenLoaded('author')),
            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'canonical_url' => $this->canonical_url,
                'og_title' => $this->og_title,
                'og_description' => $this->og_description,
                'og_image' => $this->og_image,
            ],
        ];
    }
}
