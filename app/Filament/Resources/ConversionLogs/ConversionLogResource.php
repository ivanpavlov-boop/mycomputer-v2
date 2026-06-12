<?php

namespace App\Filament\Resources\ConversionLogs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ConversionLogs\Pages\ListConversionLogs;
use App\Models\ConversionLog;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ConversionLogResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ConversionLog::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Conversion Logs';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('order.order_number')->label('Order')->disabled(),
            TextInput::make('provider')->disabled(),
            TextInput::make('event_name')->disabled(),
            TextInput::make('status')->disabled(),
            Textarea::make('payload')->disabled()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(12),
            Textarea::make('response')->disabled()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(8),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->badge()->sortable(),
                TextColumn::make('event_name')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('order.order_number')->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')->options(array_combine(ConversionLog::PROVIDERS, ConversionLog::PROVIDERS)),
                SelectFilter::make('status')->options(array_combine(ConversionLog::STATUSES, ConversionLog::STATUSES)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversionLogs::route('/'),
        ];
    }
}
