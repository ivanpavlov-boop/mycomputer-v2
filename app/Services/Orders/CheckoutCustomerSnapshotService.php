<?php

namespace App\Services\Orders;

use App\Models\Customer;

class CheckoutCustomerSnapshotService
{
    public function create(array $validatedCheckoutData): Customer
    {
        return Customer::query()->create([
            'first_name' => $validatedCheckoutData['first_name'],
            'last_name' => $validatedCheckoutData['last_name'],
            'email' => $validatedCheckoutData['email'],
            'phone' => $validatedCheckoutData['phone'],
            'company_name' => $validatedCheckoutData['company_name'] ?? null,
            'vat_number' => $validatedCheckoutData['vat_number'] ?? null,
            'billing_address' => $validatedCheckoutData['billing_address'],
            'shipping_address' => $validatedCheckoutData['shipping_address'],
        ]);
    }
}
