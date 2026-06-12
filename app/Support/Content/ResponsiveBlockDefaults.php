<?php

namespace App\Support\Content;

class ResponsiveBlockDefaults
{
    public const DEVICES = ['desktop', 'tablet', 'mobile'];

    public const TYPOGRAPHY_SIZES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', 'custom'];

    public static function profiles(): array
    {
        return [
            'desktop' => ['label' => 'Desktop', 'min_width' => 1200, 'max_width' => null],
            'tablet' => ['label' => 'Tablet', 'min_width' => 768, 'max_width' => 1199],
            'mobile' => ['label' => 'Mobile', 'min_width' => null, 'max_width' => 767],
        ];
    }

    public static function defaultSettings(): array
    {
        return [
            'desktop' => self::deviceDefaults(columns: 4, spacing: 'lg', heading: '4xl', subtitle: 'xl', text: 'md', height: '700px'),
            'tablet' => self::deviceDefaults(columns: 2, spacing: 'md', heading: '3xl', subtitle: 'lg', text: 'md', height: '500px'),
            'mobile' => self::deviceDefaults(columns: 1, spacing: 'sm', heading: '2xl', subtitle: 'md', text: 'sm', height: '320px'),
        ];
    }

    public static function normalizeContent(mixed $content): string|array
    {
        if (is_string($content)) {
            $decoded = json_decode($content, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::normalizeBlocks($decoded['blocks'] ?? $decoded);
            }

            return $content;
        }

        if (is_array($content)) {
            return self::normalizeBlocks($content['blocks'] ?? $content);
        }

        return '';
    }

    public static function normalizeBlocks(array $blocks): array
    {
        return collect($blocks)
            ->filter(fn ($block): bool => is_array($block))
            ->values()
            ->map(fn (array $block, int $index): array => self::normalizeBlock($block, $index))
            ->all();
    }

    public static function normalizeBlock(array $block, int $index = 0): array
    {
        $data = $block['data'] ?? $block;
        $type = $block['type'] ?? $data['type'] ?? 'rich_text';
        $responsive = self::mergeResponsiveSettings($data['responsive'] ?? $block['responsive'] ?? []);

        return [
            'id' => $block['id'] ?? $data['id'] ?? 'block-'.$index,
            'type' => $type,
            'data' => array_diff_key($data, array_flip(['responsive', 'type'])),
            'responsive' => $responsive,
            'images' => self::responsiveImages($data),
            'preview' => [
                'modes' => array_keys(self::profiles()),
                'default_mode' => 'desktop',
            ],
        ];
    }

    public static function mergeResponsiveSettings(array $settings): array
    {
        $defaults = self::defaultSettings();

        foreach (self::DEVICES as $device) {
            $settings[$device] = array_replace_recursive($defaults[$device], $settings[$device] ?? []);
        }

        return $settings;
    }

    public static function responsiveImages(array $data): array
    {
        $desktop = $data['desktop_image'] ?? $data['image'] ?? null;
        $tablet = $data['tablet_image'] ?? $desktop;
        $mobile = $data['mobile_image'] ?? $tablet;

        return [
            'desktop' => $desktop,
            'tablet' => $tablet,
            'mobile' => $mobile,
        ];
    }

    public static function isVisibleOnAnyDevice(array $settings): bool
    {
        $settings = self::mergeResponsiveSettings($settings);

        foreach (self::DEVICES as $device) {
            if (($settings[$device]['visible'] ?? true) === true) {
                return true;
            }
        }

        return false;
    }

    private static function deviceDefaults(
        int $columns,
        string $spacing,
        string $heading,
        string $subtitle,
        string $text,
        string $height,
    ): array {
        return [
            'visible' => true,
            'layout' => [
                'width' => 'full',
                'max_width' => '1200px',
                'columns' => $columns,
                'spacing' => $spacing,
                'alignment' => 'left',
            ],
            'typography' => [
                'heading_size' => $heading,
                'subtitle_size' => $subtitle,
                'text_size' => $text,
                'custom_heading_size' => null,
                'custom_subtitle_size' => null,
                'custom_text_size' => null,
            ],
            'buttons' => [
                'layout' => 'inline',
                'alignment' => 'left',
                'full_width' => false,
            ],
            'spacing' => [
                'padding' => ['top' => null, 'right' => null, 'bottom' => null, 'left' => null],
                'margin' => ['top' => null, 'right' => null, 'bottom' => null, 'left' => null],
            ],
            'height' => $height,
            'carousel' => [
                'slides_per_view' => min($columns + 1, 5),
            ],
            'ordering' => [
                'media_first' => true,
            ],
        ];
    }
}
