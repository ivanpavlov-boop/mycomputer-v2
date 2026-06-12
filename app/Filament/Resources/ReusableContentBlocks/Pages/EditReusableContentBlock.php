<?php

namespace App\Filament\Resources\ReusableContentBlocks\Pages;

use App\Filament\Resources\ReusableContentBlocks\ReusableContentBlockResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Pages\EditRecord;

class EditReusableContentBlock extends EditRecord
{
    protected static string $resource = ReusableContentBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [ReplicateAction::make(), DeleteAction::make()];
    }
}
