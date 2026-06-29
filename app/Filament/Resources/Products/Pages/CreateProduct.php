<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return 'Създай продукт';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $source = $data['source'] ?? Product::SOURCE_MANUAL;
        $data['created_by'] = auth()->id();
        $quantity = (int) ($data['quantity'] ?? 0);
        $stockStatus = $data['stock_status'] ?? null;

        if (blank($stockStatus) || ($stockStatus === Product::STOCK_STATUS_IN_STOCK && $quantity <= 0)) {
            $data['stock_status'] = Product::defaultStockStatusForQuantity($quantity);
        }

        if ($source === Product::SOURCE_SUPPLIER_IMPORT) {
            $data['workflow_status'] = Product::WORKFLOW_PUBLISHED;
            $data['product_status'] = 'active';
            $data['active'] = true;
            $data['published_at'] ??= now();
            $data['published_by'] = auth()->id();

            return $data;
        }

        $data['workflow_status'] = Product::WORKFLOW_DRAFT;
        $data['product_status'] = 'draft';
        $data['active'] = false;
        $data['published_at'] = null;

        return $data;
    }
}
