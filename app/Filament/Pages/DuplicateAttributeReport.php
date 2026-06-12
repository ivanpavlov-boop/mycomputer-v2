<?php

namespace App\Filament\Pages;

use App\Services\Attributes\DuplicateAttributeDetectionService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class DuplicateAttributeReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Duplicate Attribute Report';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    protected string $view = 'filament.pages.duplicate-attribute-report';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage attribute mappings');
    }

    public function getDuplicateAttributes(): Collection
    {
        return app(DuplicateAttributeDetectionService::class)->duplicateAttributes();
    }

    public function getDuplicateValues(): Collection
    {
        return app(DuplicateAttributeDetectionService::class)->duplicateValues();
    }
}
