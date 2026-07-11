<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SupplierImportExecutionLock
{
    private ?Lock $lock = null;

    public function acquire(Supplier $supplier): bool
    {
        try {
            $this->lock = Cache::lock('supplier_import:'.$supplier->getKey(), 3600);

            return $this->lock->get();
        } catch (Throwable) {
            $this->lock = null;

            return false;
        }
    }

    public function release(): void
    {
        try {
            $this->lock?->release();
        } catch (Throwable) {
            // A completed transaction must not be turned into a second failure by lock cleanup.
        } finally {
            $this->lock = null;
        }
    }
}
