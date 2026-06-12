<?php

namespace App\Filament\Resources\PaymentProviders\Pages;

use App\Filament\Resources\PaymentProviders\PaymentProviderResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentProvider extends CreateRecord
{
    protected static string $resource = PaymentProviderResource::class;
}
