<?php

namespace App\Filament\Resources\EmailAutomations;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\EmailAutomations\Pages\CreateEmailAutomation;
use App\Filament\Resources\EmailAutomations\Pages\EditEmailAutomation;
use App\Filament\Resources\EmailAutomations\Pages\ListEmailAutomations;
use App\Models\EmailAutomation;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class EmailAutomationResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = EmailAutomation::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Email Automations';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Select::make('trigger')->options(array_combine(EmailAutomation::TRIGGERS, EmailAutomation::TRIGGERS))->required(),
            Toggle::make('enabled')->default(true),
            KeyValue::make('configuration'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('trigger')->badge()->sortable(),
            IconColumn::make('enabled')->boolean()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailAutomations::route('/'),
            'create' => CreateEmailAutomation::route('/create'),
            'edit' => EditEmailAutomation::route('/{record}/edit'),
        ];
    }
}
