<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

class PcBuilderService
{
    public function __construct(private readonly BuildPriceService $prices) {}

    public function create(array $data, ?User $user = null, ?string $sessionId = null): PcBuild
    {
        return PcBuild::query()->create([
            'user_id' => $user?->id,
            'session_id' => $user ? null : ($sessionId ?: (string) Str::uuid()),
            'name' => $data['name'] ?? 'New PC Build',
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'draft',
        ])->fresh(['items.product']);
    }

    public function update(PcBuild $build, array $data): PcBuild
    {
        $build->update($data);

        return $this->prices->recalculate($build);
    }

    public function addItem(PcBuild $build, Product $product, string $componentType, int $quantity = 1): PcBuild
    {
        abort_unless($product->active && $product->published_at !== null, 422, 'Product is not available.');
        abort_unless(in_array($componentType, PcBuildItem::COMPONENT_TYPES, true), 422, 'Invalid component type.');

        $build->items()->updateOrCreate(
            ['product_id' => $product->id, 'component_type' => $componentType],
            ['quantity' => max(1, $quantity)],
        );

        return $this->prices->recalculate($build);
    }

    public function removeItem(PcBuild $build, PcBuildItem $item): PcBuild
    {
        abort_unless($item->pc_build_id === $build->id, 404);
        $item->delete();

        return $this->prices->recalculate($build);
    }

    public function ownedQuery(?User $user = null, ?string $sessionId = null)
    {
        return PcBuild::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->when(! $user, fn ($query) => $query->where('session_id', $sessionId));
    }
}
