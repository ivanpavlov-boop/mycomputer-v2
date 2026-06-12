<?php

namespace App\Filament\Resources\AvailabilityStatusMappings;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AvailabilityStatusMappings\Pages\CreateAvailabilityStatusMapping;
use App\Filament\Resources\AvailabilityStatusMappings\Pages\EditAvailabilityStatusMapping;
use App\Filament\Resources\AvailabilityStatusMappings\Pages\ListAvailabilityStatusMappings;
use App\Models\AvailabilityStatusMapping;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class AvailabilityStatusMappingResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AvailabilityStatusMapping::class;

    protected static ?string $permission = 'manage availability statuses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Availability Mappings';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Select::make('source_type')->options(array_combine(AvailabilityStatusMapping::SOURCE_TYPES, AvailabilityStatusMapping::SOURCE_TYPES))->required(),
                TextInput::make('source_code')->helperText('Supplier code, ERP code or leave empty for generic source mapping.'),
                TextInput::make('external_status')->required()->maxLength(255),
                TextInput::make('external_status_label')->maxLength(255),
                Select::make('availability_status_id')->relationship('availabilityStatus', 'name')->required()->searchable()->preload(),
                TextInput::make('priority')->numeric()->default(100),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_type')->badge()->sortable(),
                TextColumn::make('source_code')->searchable()->sortable(),
                TextColumn::make('external_status')->searchable()->sortable(),
                TextColumn::make('external_status_label')->toggleable(),
                TextColumn::make('availabilityStatus.name')->badge()->sortable(),
                TextColumn::make('priority')->numeric()->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('source_type')->options(array_combine(AvailabilityStatusMapping::SOURCE_TYPES, AvailabilityStatusMapping::SOURCE_TYPES)),
                SelectFilter::make('availability_status_id')->relationship('availabilityStatus', 'name'),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')->action(fn (Collection $records) => $records->each->update(['is_active' => true])),
                    BulkAction::make('disable')->action(fn (Collection $records) => $records->each->update(['is_active' => false])),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('priority');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAvailabilityStatusMappings::route('/'),
            'create' => CreateAvailabilityStatusMapping::route('/create'),
            'edit' => EditAvailabilityStatusMapping::route('/{record}/edit'),
        ];
    }
}
