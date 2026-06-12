<?php

namespace App\Filament\Resources\PcBuilds;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\PcBuilds\Pages\EditPcBuild;
use App\Filament\Resources\PcBuilds\Pages\ListPcBuilds;
use App\Models\PcBuild;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PcBuildResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = PcBuild::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?string $navigationLabel = 'PC Builds';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Build')->schema([
                Select::make('user_id')->relationship('user', 'email')->searchable()->preload(),
                TextInput::make('session_id')->disabled(),
                TextInput::make('name')->required()->maxLength(160),
                Textarea::make('description')->rows(3),
                TextInput::make('total_price')->numeric()->prefix('BGN')->disabled()->dehydrated(),
                Select::make('status')->options(array_combine(PcBuild::STATUSES, PcBuild::STATUSES))->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('user.email')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_price')->money('BGN')->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Components')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(PcBuild::STATUSES, PcBuild::STATUSES)),
                SelectFilter::make('user_id')->relationship('user', 'email')->searchable()->preload(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPcBuilds::route('/'),
            'edit' => EditPcBuild::route('/{record}/edit'),
        ];
    }
}
