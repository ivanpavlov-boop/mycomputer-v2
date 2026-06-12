<?php

namespace App\Filament\Resources\EmailLogs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\EmailLogs\Pages\EditEmailLog;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Models\EmailLog;
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

class EmailLogResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = EmailLog::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Email Logs';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->disabled(),
            TextInput::make('provider')->disabled(),
            TextInput::make('type')->disabled(),
            TextInput::make('subject')->disabled(),
            TextInput::make('status')->disabled(),
            Textarea::make('payload')->disabled()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(16),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->sortable(),
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('subject')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('sent_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(array_combine(EmailLog::STATUSES, EmailLog::STATUSES)),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailLogs::route('/'),
            'edit' => EditEmailLog::route('/{record}/edit'),
        ];
    }
}
