<?php

namespace App\Http\Resources;

use App\Support\Localization\Locales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
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
            'website' => $this->website,
            'logo_path' => $this->logo_path,
            'description' => $this->description,
            'locale' => $locale,
            'localized' => [
                'description' => $this->localizedField('description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'meta_title' => $this->localizedField('meta_title', $locale, fallbackToPrimary: $locale === Locales::default()),
                'meta_description' => $this->localizedField('meta_description', $locale, fallbackToPrimary: $locale === Locales::default()),
                'has_translation' => $locale === Locales::default() || $this->hasLocalizedField('description', $locale),
            ],
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
        ];
    }
}
