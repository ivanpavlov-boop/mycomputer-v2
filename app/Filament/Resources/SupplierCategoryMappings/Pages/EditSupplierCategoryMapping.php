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
                ->label('РћРґРѕР±СЂРё')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record))
                ->disabled(fn (): bool => ! SupplierCategoryMappingResource::canQuickApprove($this->record))
                ->requiresConfirmation()
                ->modalHeading('РћРґРѕР±СЂСЏРІР°РЅРµ РЅР° supplier mapping')
                ->modalDescription('РћРґРѕР±СЂСЏРІР° СЃР°РјРѕ С‚РѕР·Рё review Р·Р°РїРёСЃ. РќРµ СЃСЉР·РґР°РІР° РєР°С‚РµРіРѕСЂРёРё, РЅРµ РјРµСЃС‚Рё РїСЂРѕРґСѓРєС‚Рё Рё РЅРµ РїСЂРёР»Р°РіР° Catalog Sync.')
                ->modalSubmitActionLabel('РћРґРѕР±СЂРё')
                ->successRedirectUrl(SupplierCategoryMappingResource::getUrl('index'))
                ->action(fn (): bool => SupplierCategoryMappingResource::approveMapping($this->record)),
            Action::make('reject')
                ->label('РћС‚С…РІСЉСЂР»Рё')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record))
                ->schema([
                    Textarea::make('notes')
                        ->label('Р‘РµР»РµР¶РєР°')
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->modalHeading('РћС‚С…РІСЉСЂР»СЏРЅРµ РЅР° supplier mapping')
                ->modalSubmitActionLabel('РћС‚С…РІСЉСЂР»Рё')
                ->successRedirectUrl(SupplierCategoryMappingResource::getUrl('index'))
                ->action(fn (array $data): bool => SupplierCategoryMappingResource::markMapping($this->record, SupplierCategoryMapping::STATUS_REJECTED, $data['notes'] ?? null)),
            Action::make('ignore')
                ->label('РРіРЅРѕСЂРёСЂР°Р№')
                ->icon('heroicon-o-no-symbol')
                ->color('gray')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record))
                ->schema([
                    Textarea::make('notes')
                        ->label('Р‘РµР»РµР¶РєР°')
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->modalHeading('РРіРЅРѕСЂРёСЂР°РЅРµ РЅР° supplier mapping')
                ->modalSubmitActionLabel('РРіРЅРѕСЂРёСЂР°Р№')
                ->successRedirectUrl(SupplierCategoryMappingResource::getUrl('index'))
                ->action(fn (array $data): bool => SupplierCategoryMappingResource::markMapping($this->record, SupplierCategoryMapping::STATUS_IGNORED, $data['notes'] ?? null)),
            Action::make('reset_pending')
                ->label('Р’СЉСЂРЅРё Р·Р° РїСЂРµРіР»РµРґ')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => SupplierCategoryMappingResource::canEdit($this->record) && $this->record->status !== SupplierCategoryMapping::STATUS_PENDING_REVIEW)
                ->requiresConfirmation()
                ->modalHeading('Р’СЂСЉС‰Р°РЅРµ Р·Р° РїСЂРµРіР»РµРґ')
                ->modalDescription('Р’СЂСЉС‰Р° СЃР°РјРѕ review СЃС‚Р°С‚СѓСЃР°. РќРµ РїСЂРѕРјРµРЅСЏ РїСЂРѕРґСѓРєС‚Рё, РєР°С‚РµРіРѕСЂРёРё РёР»Рё sync РґР°РЅРЅРё.')
                ->modalSubmitActionLabel('Р’СЉСЂРЅРё Р·Р° РїСЂРµРіР»РµРґ')
                ->successRedirectUrl(SupplierCategoryMappingResource::getUrl('index'))
                ->action(fn (): bool => SupplierCategoryMappingResource::resetMapping($this->record)),
            DeleteAction::make()->label('Изтрий'),
        ];
    }
}
