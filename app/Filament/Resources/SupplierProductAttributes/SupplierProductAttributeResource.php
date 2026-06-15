<?php

namespace App\Filament\Resources\SupplierProductAttributes;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\SupplierProductAttributes\Pages\EditSupplierProductAttribute;
use App\Filament\Resources\SupplierProductAttributes\Pages\ListSupplierProductAttributes;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProductAttribute;
use App\Services\Attributes\AttributeMappingReviewService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class SupplierProductAttributeResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = SupplierProductAttribute::class;

    protected static ?string $permission = 'manage attribute mappings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Supplier Attribute Staging';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Raw supplier attribute')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                        TextInput::make('source_type')->disabled(),
                        TextInput::make('status')->disabled(),
                        TextInput::make('raw_name')->disabled(),
                        TextInput::make('raw_value')->disabled(),
                        TextInput::make('raw_unit')->disabled(),
                    ]),
                ]),
            Section::make('Canonical mapping')
                ->schema([
                    Select::make('canonical_attribute_id')
                        ->relationship('canonicalAttribute', 'name')
                        ->searchable()
                        ->preload(),
                    Select::make('canonical_attribute_value_id')
                        ->relationship('canonicalAttributeValue', 'display_value')
                        ->searchable()
                        ->preload(),
                    Grid::make(3)->schema([
                        TextInput::make('normalized_name')->maxLength(255),
                        TextInput::make('normalized_value')->maxLength(255),
                        TextInput::make('confidence')->numeric()->minValue(0)->maxValue(100),
                        Select::make('status')->options(array_combine(SupplierProductAttribute::STATUSES, SupplierProductAttribute::STATUSES))->required(),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('raw_name')->searchable()->sortable(),
                TextColumn::make('raw_value')->searchable()->limit(40),
                TextColumn::make('raw_unit')->toggleable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->searchable()->toggleable(),
                TextColumn::make('source_type')->badge()->sortable(),
                TextColumn::make('canonicalAttribute.code')->label('Canonical')->searchable()->sortable(),
                TextColumn::make('canonicalAttributeValue.display_value')->label('Value')->searchable()->toggleable(),
                TextColumn::make('confidence')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(SupplierProductAttribute::STATUSES, SupplierProductAttribute::STATUSES)),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                SelectFilter::make('source_type')->options([
                    'xml' => 'XML',
                    'csv' => 'CSV',
                    'erp' => 'ERP',
                    'api' => 'API',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->form([
                        Select::make('canonical_attribute_id')
                            ->label('Attribute')
                            ->options(fn (): array => CanonicalAttribute::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Select::make('canonical_attribute_value_id')
                            ->label('Value')
                            ->options(fn (): array => CanonicalAttributeValue::query()->orderBy('display_value')->pluck('display_value', 'id')->all())
                            ->searchable(),
                        Checkbox::make('create_aliases')->default(true),
                    ])
                    ->action(function (SupplierProductAttribute $record, array $data): void {
                        app(AttributeMappingReviewService::class)->approve(
                            $record,
                            CanonicalAttribute::query()->findOrFail($data['canonical_attribute_id']),
                            filled($data['canonical_attribute_value_id'] ?? null) ? CanonicalAttributeValue::query()->find($data['canonical_attribute_value_id']) : null,
                            (bool) ($data['create_aliases'] ?? true),
                        );
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ignore')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (SupplierProductAttribute $record) => app(AttributeMappingReviewService::class)->ignore($record))),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierProductAttributes::route('/'),
            'edit' => EditSupplierProductAttribute::route('/{record}/edit'),
        ];
    }
}
