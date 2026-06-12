<?php

namespace App\Services\Content;

use App\Models\ContentBlock;
use App\Support\Content\ResponsiveBlockDefaults;

class BlockRenderer
{
    public function __construct(
        private readonly BlockDataResolver $resolver,
        private readonly CmsHtmlSanitizer $sanitizer,
    ) {}

    public function render(ContentBlock $block): array
    {
        $reusable = $block->reusableBlock;
        $content = $this->sanitizeContent(
            $block->block_type,
            array_replace_recursive($reusable?->content ?? [], $block->content ?? [])
        );
        $settings = array_replace_recursive($reusable?->settings ?? [], $block->settings ?? []);
        $responsive = ResponsiveBlockDefaults::mergeResponsiveSettings(array_replace_recursive($reusable?->responsive_settings ?? [], $block->responsive_settings ?? []));

        return [
            'id' => $block->id,
            'type' => $block->block_type,
            'title' => $block->title,
            'settings' => $settings,
            'data' => $content,
            'responsive' => $responsive,
            'images' => ResponsiveBlockDefaults::responsiveImages($content),
            'resolved' => $this->resolver->resolve($block),
            'analytics' => ['view_event' => 'block_viewed', 'click_event' => 'block_clicked'],
        ];
    }

    private function sanitizeContent(string $blockType, array $content): array
    {
        if ($blockType === 'rich_text' && isset($content['body'])) {
            $content['body'] = $this->sanitizer->sanitize($content['body']);
        }

        if ($blockType === 'image_text' && isset($content['body'])) {
            $content['body'] = $this->sanitizer->sanitize($content['body']);
        }

        if ($blockType === 'custom_html') {
            foreach (['html', 'body'] as $key) {
                if (isset($content[$key])) {
                    $content[$key] = $this->sanitizer->sanitize($content[$key]);
                }
            }
        }

        return $content;
    }
}
