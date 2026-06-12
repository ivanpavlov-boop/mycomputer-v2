<?php

namespace App\Services\Content;

use App\Models\ContentBlock;
use App\Support\Content\ResponsiveBlockDefaults;
use Illuminate\Http\Request;

class BlockVisibilityService
{
    public function isVisible(ContentBlock $block, ?Request $request = null): bool
    {
        if (! $block->is_active) {
            return false;
        }

        if ($block->starts_at && $block->starts_at->isFuture()) {
            return false;
        }

        if ($block->ends_at && $block->ends_at->isPast()) {
            return false;
        }

        $responsive = array_replace_recursive($block->reusableBlock?->responsive_settings ?? [], $block->responsive_settings ?? []);

        if (! ResponsiveBlockDefaults::isVisibleOnAnyDevice($responsive)) {
            return false;
        }

        $rules = $block->visibility_rules ?? [];

        if (($rules['guest_only'] ?? false) && $request?->user()) {
            return false;
        }

        if (($rules['logged_in_only'] ?? false) && ! $request?->user()) {
            return false;
        }

        if (($rules['url_parameter'] ?? null) && $request) {
            $parameter = $rules['url_parameter'];
            $expected = $rules['url_parameter_value'] ?? null;

            if (! $request->query->has($parameter)) {
                return false;
            }

            if ($expected !== null && (string) $request->query($parameter) !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
