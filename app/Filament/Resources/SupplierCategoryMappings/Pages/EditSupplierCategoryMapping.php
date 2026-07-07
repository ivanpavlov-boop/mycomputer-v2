<?php

namespace App\Filament\Resources\SupplierCategoryMappings\Pages;

use App\Filament\Resources\SupplierCategoryMappings\SupplierCategoryMappingResource;
use App\Models\SupplierCategoryMapping;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;

class EditSupplierCategoryMapping extends EditRecord
{
    protected static string $resource = SupplierCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Одобри')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record))
                ->disabled(fn (): bool => ! SupplierCategoryMappingResource::canQuickApprove($this->record))
                ->requiresConfirmation()
                ->modalHeading('Одобряване на supplier mapping')
                ->modalDescription('Одобрява само този review запис. Не създава категории, не мести продукти и не прилага Catalog Sync.')
                ->modalSubmitActionLabel('Одобри')
                ->successNotificationTitle('Mapping-ът е одобрен.')
                ->action(fn (): bool => $this->redirectToIndexIfSuccessful(
                    SupplierCategoryMappingResource::approveMapping($this->record),
                )),
            Action::make('reject')
                ->label('Отхвърли')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record))
                ->schema([
                    Textarea::make('notes')
                        ->label('Бележка')
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->modalHeading('Отхвърляне на supplier mapping')
                ->modalDescription('Отхвърля само този review запис. Не променя продукти, категории или Catalog Sync.')
                ->modalSubmitActionLabel('Отхвърли')
                ->successNotificationTitle('Mapping-ът е отхвърлен.')
                ->action(fn (array $data): bool => $this->redirectToIndexIfSuccessful(
                    SupplierCategoryMappingResource::markMapping($this->record, SupplierCategoryMapping::STATUS_REJECTED, $data['notes'] ?? null),
                )),
            Action::make('ignore')
                ->label('Игнорирай')
                ->icon('heroicon-o-no-symbol')
                ->color('gray')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record))
                ->schema([
                    Textarea::make('notes')
                        ->label('Бележка')
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->modalHeading('Игнориране на supplier mapping')
                ->modalDescription('Игнорира само този review запис. Не променя продукти, категории или Catalog Sync.')
                ->modalSubmitActionLabel('Игнорирай')
                ->successNotificationTitle('Mapping-ът е игнориран.')
                ->action(fn (array $data): bool => $this->redirectToIndexIfSuccessful(
                    SupplierCategoryMappingResource::markMapping($this->record, SupplierCategoryMapping::STATUS_IGNORED, $data['notes'] ?? null),
                )),
            Action::make('reset_pending')
                ->label('Върни за преглед')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record) && $this->record->status !== SupplierCategoryMapping::STATUS_PENDING_REVIEW)
                ->requiresConfirmation()
                ->modalHeading('Връщане за преглед')
                ->modalDescription('Връща само review статуса. Не променя продукти, категории или sync данни.')
                ->modalSubmitActionLabel('Върни за преглед')
                ->successNotificationTitle('Mapping-ът е върнат за преглед.')
                ->action(fn (): bool => $this->redirectToIndexIfSuccessful(
                    SupplierCategoryMappingResource::resetMapping($this->record),
                )),
            DeleteAction::make()->label('Изтрий'),
        ];
    }

    private function redirectToIndexIfSuccessful(bool $success): bool
    {
        if ($success) {
            $this->redirect(SupplierCategoryMappingResource::getUrl('index'));
        }

        return $success;
    }
}
