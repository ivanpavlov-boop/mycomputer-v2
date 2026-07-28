<?php

namespace App\Http\Resources;

use App\Services\Payments\PaymentActionPresentationService;
use Illuminate\Http\Request;

class AccountOrderDetailResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'payment' => [
                'presentation' => app(PaymentActionPresentationService::class)
                    ->forAccountOrder($this->resource, $request->user()),
            ],
        ];
    }
}
