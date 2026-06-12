<?php

namespace App\Services\Availability;

use App\Models\AvailabilityStatus;
use App\Models\Product;

class AvailabilityStatusService
{
    public const LIMITED_STOCK_THRESHOLD = 3;

    public function default(): ?AvailabilityStatus
    {
        return AvailabilityStatus::query()
            ->active()
            ->where('is_default', true)
            ->ordered()
            ->first()
            ?? AvailabilityStatus::query()->active()->ordered()->first();
    }

    public function byCode(string $code): ?AvailabilityStatus
    {
        return AvailabilityStatus::query()->active()->where('code', $code)->first();
    }

    public function automaticForQuantity(?int $quantity): ?AvailabilityStatus
    {
        $quantity = (int) ($quantity ?? 0);

        $code = match (true) {
            $quantity <= 0 => 'out_of_stock',
            $quantity <= self::LIMITED_STOCK_THRESHOLD => 'limited_stock',
            default => 'in_stock',
        };

        return $this->byCode($code) ?? $this->default();
    }

    public function assign(Product $product, ?AvailabilityStatus $status = null, bool $manual = false): Product
    {
        if ($product->manual_override && ! $manual) {
            return $product;
        }

        $status ??= $this->automaticForQuantity((int) $product->quantity);
        $product->forceFill([
            'availability_status_id' => $status?->id,
            'stock_status' => $status?->code ?? $product->stock_status,
            'manual_override' => $manual ? true : $product->manual_override,
        ])->save();

        return $product->refresh();
    }

    public function allowsPurchase(Product $product): bool
    {
        $product->loadMissing('availabilityStatus');

        return (bool) ($product->availabilityStatus?->allow_purchase ?? $product->stock_status !== 'out_of_stock');
    }

    public function requiresStock(Product $product): bool
    {
        $product->loadMissing('availabilityStatus');

        return (bool) ($product->availabilityStatus?->show_stock_quantity ?? true);
    }

    public function schemaAvailability(Product $product): string
    {
        $code = $product->availabilityStatus?->code ?? $product->stock_status;

        return match ($code) {
            'out_of_stock', 'discontinued' => 'https://schema.org/OutOfStock',
            'preorder' => 'https://schema.org/PreOrder',
            default => 'https://schema.org/InStock',
        };
    }
}
