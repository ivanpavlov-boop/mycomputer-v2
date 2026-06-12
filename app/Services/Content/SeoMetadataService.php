<?php

namespace App\Services\Content;

class SeoMetadataService
{
    public function sanitizeHtml(?string $html): string
    {
        $html = (string) $html;

        return trim(strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><a><blockquote><table><thead><tbody><tr><th><td><img>'));
    }

    public function meta(array $data): array
    {
        return [
            'meta_title' => $data['meta_title'] ?? $data['title'] ?? null,
            'meta_description' => $data['meta_description'] ?? $data['excerpt'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'og_title' => $data['og_title'] ?? $data['meta_title'] ?? $data['title'] ?? null,
            'og_description' => $data['og_description'] ?? $data['meta_description'] ?? $data['excerpt'] ?? null,
            'og_image' => $data['og_image'] ?? $data['featured_image'] ?? null,
        ];
    }
}
