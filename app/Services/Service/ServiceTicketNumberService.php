<?php

namespace App\Services\Service;

use App\Models\ServiceTicket;

class ServiceTicketNumberService
{
    public function generate(): string
    {
        do {
            $number = 'SRV-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (ServiceTicket::query()->where('ticket_number', $number)->exists());

        return $number;
    }
}
