<?php

namespace App\Filament\Resources\QuoteRequests\Pages;

use App\Filament\Resources\QuoteRequests\QuoteRequestResource;
use App\Services\B2B\QuoteNumberService;
use Filament\Resources\Pages\CreateRecord;

class CreateQuoteRequest extends CreateRecord
{
    protected static string $resource = QuoteRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + [
            'quote_number' => app(QuoteNumberService::class)->generate(),
            'source' => 'admin',
            'status' => 'draft',
        ];
    }
}
