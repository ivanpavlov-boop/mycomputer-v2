<?php

namespace App\Filament\Resources\ReusableContentBlocks;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ReusableContentBlocks\Pages\CreateReusableContentBlock;
use App\Filament\Resources\ReusableContentBlocks\Pages\EditReusableContentBlock;
use App\Filament\Resources\ReusableContentBlocks\Pages\ListReusableContentBlocks;
use App\Models\ReusableContentBlock;
use App\Services\Content\BlockRegistry;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ReusableContentBlockResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ReusableContentBlock::class;

    protected static ?string $permission = 'manage reusable blocks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static ?string $navigationLabel = 'Reusable Blocks';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->required(),
                Select::make('block_type')->options(app(BlockRegistry::class)->options())->required()->searchable(),
                Textarea::make('content')->rows(8)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
                Textarea::make('settings')->rows(5)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
                Textarea::make('responsive_settings')->rows(5)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('block_type')->badge()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReusableContentBlocks::route('/'),
            'create' => CreateReusableContentBlock::route('/create'),
            'edit' => EditReusableContentBlock::route('/{record}/edit'),
        ];
    }
}
