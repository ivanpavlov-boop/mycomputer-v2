<?php

namespace App\Http\Resources;

use App\Services\Content\CmsHtmlSanitizer;
use App\Support\Content\ResponsiveBlockDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $blocks = $this->resource['blocks'];

        return [
            'id' => $this->resource['page']->id,
            'title' => $this->resource['page']->title,
            'slug' => $this->resource['page']->slug,
            'page_type' => $this->resource['page']->page_type,
            'published_at' => $this->resource['page']->published_at,
            'template' => $this->resource['page']->template ? [
                'id' => $this->resource['page']->template->id,
                'name' => $this->resource['page']->template->name,
                'slug' => $this->resource['page']->template->slug,
            ] : null,
            'seo' => [
                'meta_title' => $this->resource['page']->meta_title,
                'meta_description' => $this->resource['page']->meta_description,
                'canonical_url' => $this->resource['page']->canonical_url,
                'og_title' => $this->resource['page']->og_title,
                'og_description' => $this->resource['page']->og_description,
                'og_image' => $this->resource['page']->og_image,
            ],
            'responsive_profiles' => ResponsiveBlockDefaults::profiles(),
            'preview_modes' => array_keys(ResponsiveBlockDefaults::profiles()),
            'schema' => $this->faqSchema($blocks),
            'blocks' => ContentBlockResource::collection($blocks)->resolve(),
        ];
    }

    private function faqSchema(array $blocks): ?array
    {
        $entities = collect($blocks)
            ->where('type', 'faq')
            ->flatMap(fn (array $block): array => $this->faqItems($block['data'] ?? []))
            ->values();

        if ($entities->isEmpty()) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities->all(),
        ];
    }

    private function faqItems(array $data): array
    {
        $items = $data['items'] ?? $data['questions'] ?? [];
        $sanitizer = app(CmsHtmlSanitizer::class);

        if (($data['question'] ?? null) && ($data['answer'] ?? null)) {
            $items[] = ['question' => $data['question'], 'answer' => $data['answer']];
        }

        return collect($items)
            ->filter(fn ($item): bool => is_array($item) && filled($item['question'] ?? null) && filled($item['answer'] ?? null))
            ->map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => (string) $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => trim(strip_tags($sanitizer->sanitize((string) $item['answer']))),
                ],
            ])
            ->all();
    }
}
