<?php

namespace App\Filament\Resources\MarketingEvents;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\MarketingEvents\Pages\ListMarketingEvents;
use App\Models\MarketingEvent;
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

class MarketingEventResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = MarketingEvent::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Marketing Events';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('event_name')->disabled(),
            TextInput::make('source')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('user.email')->label('User')->disabled(),
            TextInput::make('session_id')->disabled(),
            Textarea::make('payload')->disabled()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(16),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event_name')->searchable()->sortable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('user.email')->searchable(),
                TextColumn::make('session_id')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')->options(array_combine(MarketingEvent::SOURCES, MarketingEvent::SOURCES)),
                SelectFilter::make('status')->options(array_combine(MarketingEvent::STATUSES, MarketingEvent::STATUSES)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingEvents::route('/'),
        ];
    }
}
