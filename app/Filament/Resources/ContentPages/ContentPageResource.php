<?php

namespace App\Filament\Resources\ContentPages;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ContentPages\Pages\CreateContentPage;
use App\Filament\Resources\ContentPages\Pages\EditContentPage;
use App\Filament\Resources\ContentPages\Pages\ListContentPages;
use App\Models\ContentPage;
use App\Services\Content\BlockRegistry;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ContentPageResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ContentPage::class;

    protected static ?string $permission = 'manage content pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Content Pages';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page')->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')->required()->maxLength(255),
                    TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                    Select::make('page_type')->options(array_combine(ContentPage::TYPES, ContentPage::TYPES))->required(),
                    Select::make('status')->options(array_combine(ContentPage::STATUSES, ContentPage::STATUSES))->default('draft')->required(),
                    Select::make('template_id')->relationship('template', 'name')->searchable()->preload(),
                    DateTimePicker::make('published_at'),
                ]),
            ]),
            Section::make('Blocks')->schema([
                Repeater::make('blocks')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('block_type')->options(fn (): array => self::blockTypeOptions())->required()->searchable(),
                            TextInput::make('title'),
                            Select::make('reusable_block_id')->relationship('reusableBlock', 'name')->searchable()->preload(),
                            Toggle::make('is_active')->default(true),
                            DateTimePicker::make('starts_at'),
                            DateTimePicker::make('ends_at'),
                        ]),
                        Textarea::make('content')->helperText('JSON block content')->rows(5)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
                        Textarea::make('settings')->helperText('JSON settings: source, limit, category_id, brand_id')->rows(4)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
                        Textarea::make('responsive_settings')->helperText('JSON responsive settings, merged with ResponsiveBlockDefaults')->rows(4)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
                        Textarea::make('visibility_rules')->helperText('JSON visibility rules: guest_only, logged_in_only, url_parameter')->rows(3)->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
                    ])
                    ->collapsible()
                    ->cloneable()
                    ->reorderable()
                    ->columnSpanFull(),
            ]),
            Section::make('SEO')->schema([
                TextInput::make('meta_title'),
                Textarea::make('meta_description')->rows(3),
                TextInput::make('canonical_url')->url(),
                TextInput::make('og_title'),
                Textarea::make('og_description')->rows(3),
                TextInput::make('og_image'),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('page_type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('published_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('page_type')->options(array_combine(ContentPage::TYPES, ContentPage::TYPES)),
            SelectFilter::make('status')->options(array_combine(ContentPage::STATUSES, ContentPage::STATUSES)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentPages::route('/'),
            'create' => CreateContentPage::route('/create'),
            'edit' => EditContentPage::route('/{record}/edit'),
        ];
    }

    private static function blockTypeOptions(): array
    {
        $options = app(BlockRegistry::class)->options();

        if (! Auth::user()?->hasRole('admin')) {
            unset($options['Custom Html']);
        }

        return $options;
    }
}
