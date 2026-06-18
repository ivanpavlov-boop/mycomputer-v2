<?php

namespace App\Filament\Resources\SupplierExclusionRules;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\SupplierExclusionRules\Pages\CreateSupplierExclusionRule;
use App\Filament\Resources\SupplierExclusionRules\Pages\EditSupplierExclusionRule;
use App\Filament\Resources\SupplierExclusionRules\Pages\ListSupplierExclusionRules;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\SupplierExclusionRule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SupplierExclusionRuleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = SupplierExclusionRule::class;

    protected static ?string $permission = 'manage suppliers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Supplier Exclusion Rules';

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rule')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                        Toggle::make('is_active')->default(true),
                        TextInput::make('priority')->numeric()->default(100)->minValue(0),
                    ]),
                    Textarea::make('reason')->rows(3)->columnSpanFull(),
                ]),
            Section::make('Scope')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                            ->searchable(),
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('brand_id')
                            ->label('Brand')
                            ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        TextInput::make('sku')->label('Supplier SKU')->maxLength(255),
                        TextInput::make('ean')->maxLength(255),
                        TextInput::make('mpn')->maxLength(255),
                        TextInput::make('product_name_contains')->maxLength(255),
                        TextInput::make('min_price')->numeric()->minValue(0),
                        TextInput::make('max_price')->numeric()->minValue(0),
                    ]),
                    Grid::make(3)->schema([
                        Toggle::make('exclude_zero_stock'),
                        Toggle::make('exclude_eol')->label('Exclude EOL products'),
                        Toggle::make('exclude_missing_ean'),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->sortable()->searchable(),
                TextColumn::make('category.name')->label('Category')->sortable()->searchable(),
                TextColumn::make('brand.name')->label('Brand')->sortable()->searchable(),
                TextColumn::make('sku')->label('SKU')->toggleable(),
                TextColumn::make('ean')->toggleable(),
                TextColumn::make('mpn')->toggleable(),
                TextColumn::make('priority')->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ]),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierExclusionRules::route('/'),
            'create' => CreateSupplierExclusionRule::route('/create'),
            'edit' => EditSupplierExclusionRule::route('/{record}/edit'),
        ];
    }
}
