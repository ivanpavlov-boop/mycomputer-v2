<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;
use App\Models\Product;

class BuildRecommendationService
{
    public function presets(): array
    {
        return [
            ['name' => 'Gaming PC', 'budget' => '1500-3000 EUR', 'focus' => ['gpu', 'cpu', 'ram']],
            ['name' => 'Office PC', 'budget' => 'up to 1500 EUR', 'focus' => ['cpu', 'storage']],
            ['name' => 'Workstation', 'budget' => '3000-6000 EUR', 'focus' => ['cpu', 'ram', 'gpu']],
            ['name' => 'Programming PC', 'budget' => '1500-3000 EUR', 'focus' => ['cpu', 'ram', 'storage']],
            ['name' => 'Student PC', 'budget' => 'up to 1500 EUR', 'focus' => ['cpu', 'storage']],
            ['name' => 'Streaming PC', 'budget' => '3000-6000 EUR', 'focus' => ['cpu', 'gpu']],
            ['name' => 'CAD / Architecture PC', 'budget' => '3000-6000 EUR', 'focus' => ['cpu', 'gpu', 'ram']],
            ['name' => 'Video Editing PC', 'budget' => '3000-6000 EUR', 'focus' => ['cpu', 'ram', 'storage']],
        ];
    }

    public function forBuild(PcBuild $build): array
    {
        $existing = $build->items()->pluck('component_type')->all();
        $missing = collect(['cpu', 'motherboard', 'ram', 'storage', 'psu', 'case'])
            ->reject(fn (string $type) => in_array($type, $existing, true))
            ->values()
            ->all();

        return [
            'missing_required_components' => $missing,
            'missing_components' => $missing,
            'suggested_products' => Product::query()->published()->with(['brand', 'category', 'images'])->where('stock_status', '!=', 'out_of_stock')->limit(8)->get(),
            'presets' => $this->presets(),
        ];
    }
}
