<?php

namespace App\Filament\Resources\EmailLogs\Pages;

use App\Filament\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\EditRecord;

class EditEmailLog extends EditRecord
{
    protected static string $resource = EmailLogResource::class;
}
