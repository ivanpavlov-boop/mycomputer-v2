<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class PublicProductPriceFilterService
{
    /**
     * @return array{price_filter: array<string, mixed>|null}
     */
    public function describe(Builder $scope, mixed $selectedMinimum = null, mixed $selectedMaximum = null): array
    {
        $query = clone $scope;
        $query->setEagerLoads([])->reorder();
        $query->whereNotNull('products.price')->where('products.price', '>=', 0);

        if ($query->getConnection()->getDriverName() === 'sqlite') {
            $query->whereRaw("typeof(products.price) IN ('integer', 'real')");
        }

        $bounds = $query
            ->selectRaw('MIN(products.price) as minimum_price, MAX(products.price) as maximum_price')
            ->first();
        $minimum = $this->numericValue($bounds?->minimum_price);
        $maximum = $this->numericValue($bounds?->maximum_price);

        if ($minimum === null || $maximum === null || $minimum >= $maximum) {
            return ['price_filter' => null];
        }

        return [
            'price_filter' => [
                'key' => 'price',
                'label' => 'Цена',
                'control' => 'range_slider',
                'currency' => Product::CATALOG_CURRENCY,
                'min' => $minimum,
                'max' => $maximum,
                'step' => 0.01,
                'selected_min' => $this->numericValue($selectedMinimum),
                'selected_max' => $this->numericValue($selectedMaximum),
            ],
        ];
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
