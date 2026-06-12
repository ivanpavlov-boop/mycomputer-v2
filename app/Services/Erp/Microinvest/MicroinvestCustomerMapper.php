<?php

namespace App\Services\Erp\Microinvest;

use App\Models\Customer;
use App\Models\User;

class MicroinvestCustomerMapper
{
    public function map(Customer|User $customer): array
    {
        $name = trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''));

        return [
            'customer_type' => filled($customer->company_name) ? 'company' : 'individual',
            'name' => $name ?: ($customer->name ?? null),
            'first_name' => $customer->first_name ?? null,
            'last_name' => $customer->last_name ?? null,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company_name' => $customer->company_name,
            'vat_number' => $customer->vat_number,
            'billing_address' => $customer instanceof Customer ? $customer->billing_address : null,
            'shipping_address' => $customer instanceof Customer ? $customer->shipping_address : null,
        ];
    }
}
