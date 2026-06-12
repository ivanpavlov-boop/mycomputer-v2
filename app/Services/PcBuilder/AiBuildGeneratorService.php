<?php

namespace App\Services\PcBuilder;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\ProductRecommendationService;

class AiBuildGeneratorService
{
    public function __construct(
        private readonly ProductRecommendationService $recommendations,
        private readonly PcBuilderService $builder,
    ) {}

    public function generate(string $query, ?User $user = null, ?string $sessionId = null)
    {
        $build = $this->builder->create(['name' => 'AI PC Build: '.$query], $user, $sessionId);
        $recommendation = $this->recommendations->recommend($query, $user, $sessionId);

        foreach (collect($recommendation['products'])->take(6) as $productPayload) {
            $product = Product::query()->find($productPayload['id']);
            if ($product) {
                $this->builder->addItem($build, $product, $this->guessType($product->name));
            }
        }

        return $build->fresh(['items.product.brand', 'items.product.category', 'items.product.images']);
    }

    private function guessType(string $name): string
    {
        $text = mb_strtolower($name);

        return match (true) {
            str_contains($text, 'cpu'), str_contains($text, 'processor'), str_contains($text, 'ryzen'), str_contains($text, 'intel') => 'cpu',
            str_contains($text, 'motherboard'), str_contains($text, 'дън') => 'motherboard',
            str_contains($text, 'ram'), str_contains($text, 'ddr') => 'ram',
            str_contains($text, 'gpu'), str_contains($text, 'rtx'), str_contains($text, 'radeon') => 'gpu',
            str_contains($text, 'psu'), str_contains($text, 'power') => 'psu',
            str_contains($text, 'case') => 'case',
            str_contains($text, 'ssd'), str_contains($text, 'hdd') => 'storage',
            default => 'accessories',
        };
    }
}
