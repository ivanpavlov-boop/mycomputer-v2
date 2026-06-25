<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        UserResource::validateRoleSafety($this->record, $data);

        return $data;
    }

    protected function afterSave(): void
    {
        UserResource::syncPrimaryRole($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            UserResource::deleteUserAction(),
        ];
    }
}
