<?php

namespace App\Filament\Resources\EmailSubscribers;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\EmailSubscribers\Pages\CreateEmailSubscriber;
use App\Filament\Resources\EmailSubscribers\Pages\EditEmailSubscriber;
use App\Filament\Resources\EmailSubscribers\Pages\ListEmailSubscribers;
use App\Models\EmailSubscriber;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class EmailSubscriberResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = EmailSubscriber::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Email Subscribers';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required(),
            TextInput::make('first_name'),
            TextInput::make('last_name'),
            Select::make('source')->options(array_combine(EmailSubscriber::SOURCES, EmailSubscriber::SOURCES))->required(),
            Select::make('status')->options(array_combine(EmailSubscriber::STATUSES, EmailSubscriber::STATUSES))->required(),
            DateTimePicker::make('subscribed_at'),
            DateTimePicker::make('unsubscribed_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('first_name')->searchable(),
                TextColumn::make('last_name')->searchable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                IconColumn::make('gdpr_consent')->boolean(),
                TextColumn::make('subscribed_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')->options(array_combine(EmailSubscriber::SOURCES, EmailSubscriber::SOURCES)),
                SelectFilter::make('status')->options(array_combine(EmailSubscriber::STATUSES, EmailSubscriber::STATUSES)),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailSubscribers::route('/'),
            'create' => CreateEmailSubscriber::route('/create'),
            'edit' => EditEmailSubscriber::route('/{record}/edit'),
        ];
    }
}
