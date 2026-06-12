<?php

namespace App\Filament\Resources\CanonicalAttributes\Pages;

use App\Filament\Resources\CanonicalAttributes\CanonicalAttributeResource;
use Filament\Resources\Pages\EditRecord;

class EditCanonicalAttribute extends EditRecord
{
    protected static string $resource = CanonicalAttributeResource::class;
}
