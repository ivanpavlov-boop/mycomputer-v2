<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductWorkflowService;
use App\Support\ProductFormFieldAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;

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
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->isActiveAdminAccount() || ! $actor->canEditProductContent()) {
            throw new AuthorizationException('Необходим е активен администратор с право да създава продуктово съдържание.');
        }

        $data = ProductFormFieldAccess::sanitize(
            Arr::except($data, ProductWorkflowService::PROTECTED_FORM_FIELDS),
            $actor,
        );

        $data['source'] = Product::SOURCE_MANUAL;
        $data['created_by'] = $actor->id;
        $quantity = (int) ($data['quantity'] ?? 0);
        $stockStatus = $data['stock_status'] ?? null;

        if (blank($stockStatus) || ($stockStatus === Product::STOCK_STATUS_IN_STOCK && $quantity <= 0)) {
            $data['stock_status'] = Product::defaultStockStatusForQuantity($quantity);
        }

        $data['workflow_status'] = Product::WORKFLOW_DRAFT;
        $data['product_status'] = 'draft';
        $data['active'] = false;
        $data['published_at'] = null;

        return $data;
    }
}
