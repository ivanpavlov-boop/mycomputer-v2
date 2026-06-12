<?php

namespace App\Filament\Pages;

use App\Models\SupplierProductAttribute;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class UnmappedAttributesReview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Unmapped Attributes';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    protected string $view = 'filament.pages.unmapped-attributes-review';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage attribute mappings');
    }

    public function getRows(): Collection
    {
        return SupplierProductAttribute::query()
            ->with('supplier')
            ->whereIn('status', ['unmapped', 'needs_review'])
            ->selectRaw('supplier_id, raw_name, raw_value, raw_unit, source_type, status, count(*) as rows_count, max(created_at) as last_seen_at')
            ->groupBy('supplier_id', 'raw_name', 'raw_value', 'raw_unit', 'source_type', 'status')
            ->orderByDesc('rows_count')
            ->limit(100)
            ->get();
    }
}
