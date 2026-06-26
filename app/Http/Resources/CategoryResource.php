<?php

namespace App\Http\Resources;

use App\Support\Localization\Locales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'locale' => $locale,
            'localized' => [
                'name' => $this->localizedField('name', $locale, fallbackToPrimary: $locale === Locales::default()),
                'slug' => $this->localizedField('slug', $locale, fallbackToPrimary: $locale === Locales::default()),
                'description' => $this->localizedField('description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'meta_title' => $this->localizedField('meta_title', $locale, fallbackToPrimary: $locale === Locales::default()),
                'meta_description' => $this->localizedField('meta_description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'has_translation' => $locale === Locales::default() || $this->hasLocalizedField('name', $locale),
            ],
            'image' => $this->image_path,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'children' => CategoryResource::collection($this->whenLoaded('childrenRecursive')),
        ];
    }
}
