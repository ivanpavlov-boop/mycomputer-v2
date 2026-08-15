<?php

namespace App\Services\Suppliers\Onboarding;

final class OperationalSupplierOfferIdentityHasher
{
    public const HASH_NAMESPACE = 'supplier-offer-lifecycle-operational-preview-v1';

    public function supplierSku(string $supplierKey, string $supplierSku): string
    {
        return $this->hash('supplier_sku', strtolower(trim($supplierKey)).'|'.trim($supplierSku));
    }

    public function product(int|string $productId): string
    {
        return $this->hash('product', (string) $productId);
    }

    public function sample(string $bucket, string $value): string
    {
        return $this->hash($bucket, $value);
    }

    private function hash(string $bucket, string $value): string
    {
        return hash('sha256', self::HASH_NAMESPACE.'|'.$bucket.'|'.$value);
    }
}
