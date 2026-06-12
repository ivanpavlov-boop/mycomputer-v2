<?php

namespace App\Filament\Resources\AvailabilityStatuses;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AvailabilityStatuses\Pages\CreateAvailabilityStatus;
use App\Filament\Resources\AvailabilityStatuses\Pages\EditAvailabilityStatus;
use App\Filament\Resources\AvailabilityStatuses\Pages\ListAvailabilityStatuses;
use App\Models\AvailabilityStatus;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use UnitEnum;

class AvailabilityStatusResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AvailabilityStatus::class;

    protected static ?string $permission = 'manage availability statuses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Availability Statuses';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Status')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('code', Str::slug($state ?? '', '_'))),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(80)
                            ->regex('/^[a-z0-9_\\-]+$/')
                            ->unique(ignoreRecord: true),
                        TextInput::make('sort_order')->numeric()->default(0),
                    ]),
                    Textarea::make('description')->rows(3)->columnSpanFull(),
                ]),
            Section::make('Display')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('color')->required()->helperText('Use a named color or custom HEX value.'),
                        Select::make('icon')->options(array_combine(AvailabilityStatus::ICON_OPTIONS, AvailabilityStatus::ICON_OPTIONS))->searchable(),
                        Select::make('badge_style')->options(array_combine(AvailabilityStatus::BADGE_STYLES, AvailabilityStatus::BADGE_STYLES))->default('soft'),
                    ]),
                ]),
            Section::make('Behavior')
                ->schema([
                    Toggle::make('allow_purchase')->default(false),
                    Toggle::make('show_stock_quantity')->default(false),
                    Toggle::make('is_default')->default(false),
                    Toggle::make('is_active')->default(true),
                ])
                ->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->numeric()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable(),
                TextColumn::make('color')->badge(),
                TextColumn::make('icon')->badge()->toggleable(),
                TextColumn::make('badge_style')->badge()->toggleable(),
                IconColumn::make('allow_purchase')->boolean(),
                IconColumn::make('show_stock_quantity')->boolean(),
                IconColumn::make('is_default')->boolean(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('products_count')->counts('products')->label('Products')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('allow_purchase'),
                TernaryFilter::make('is_active'),
                TernaryFilter::make('is_default'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')->action(fn (Collection $records) => $records->each->update(['is_active' => true])),
                    BulkAction::make('disable')->action(fn (Collection $records) => $records->each->update(['is_active' => false])),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAvailabilityStatuses::route('/'),
            'create' => CreateAvailabilityStatus::route('/create'),
            'edit' => EditAvailabilityStatus::route('/{record}/edit'),
        ];
    }
}
