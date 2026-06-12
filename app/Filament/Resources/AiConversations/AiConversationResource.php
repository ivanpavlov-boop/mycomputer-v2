<?php

namespace App\Filament\Resources\AiConversations;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AiConversations\Pages\EditAiConversation;
use App\Filament\Resources\AiConversations\Pages\ListAiConversations;
use App\Models\AiConversation;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AiConversationResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AiConversation::class;

    protected static ?string $permission = 'manage settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'AI Conversations';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->maxLength(255),
            TextInput::make('user.email')->label('User')->disabled(),
            TextInput::make('session_id')->disabled(),
            Textarea::make('messages_preview')
                ->label('Messages')
                ->disabled()
                ->formatStateUsing(fn (?AiConversation $record): string => $record?->messages->map(fn ($message) => strtoupper($message->role).': '.$message->content)->implode("\n\n") ?? '')
                ->rows(12),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable()->limit(50),
            TextColumn::make('user.email')->searchable()->sortable(),
            TextColumn::make('session_id')->toggleable()->limit(24),
            TextColumn::make('messages_count')->counts('messages')->label('Messages')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiConversations::route('/'),
            'edit' => EditAiConversation::route('/{record}/edit'),
        ];
    }
}
