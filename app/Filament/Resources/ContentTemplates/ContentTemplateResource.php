<?php

namespace App\Filament\Resources\ContentTemplates;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ContentTemplates\Pages\CreateContentTemplate;
use App\Filament\Resources\ContentTemplates\Pages\EditContentTemplate;
use App\Filament\Resources\ContentTemplates\Pages\ListContentTemplates;
use App\Models\ContentTemplate;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ContentTemplateResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ContentTemplate::class;

    protected static ?string $permission = 'manage templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Content Templates';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Textarea::make('description')->rows(3),
                Textarea::make('template_data')->required()->rows(12)->dehydrateStateUsing(fn ($state) => json_decode($state, true))->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentTemplates::route('/'),
            'create' => CreateContentTemplate::route('/create'),
            'edit' => EditContentTemplate::route('/{record}/edit'),
        ];
    }
}
