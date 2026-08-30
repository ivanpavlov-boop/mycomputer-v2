<?php

namespace App\Contracts\Suppliers;

interface SupplierSourceIdentityGenerator
{
    public function bytes(): string;
}
