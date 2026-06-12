<?php

namespace App\Services\B2B;

use App\Models\QuoteRequest;

class QuoteNumberService
{
    public function generate(): string
    {
        do {
            $number = 'Q'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (QuoteRequest::query()->where('quote_number', $number)->exists());

        return $number;
    }
}
