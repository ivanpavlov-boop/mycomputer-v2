<?php

namespace App\Filament\Resources\SeoPages;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\SeoPages\Pages\CreateSeoPage;
use App\Filament\Resources\SeoPages\Pages\EditSeoPage;
use App\Filament\Resources\SeoPages\Pages\ListSeoPages;
use App\Models\SeoPage;
use App\Support\Content\ResponsiveBlockDefaults;
use BackedEnum;
use Filament\Forms\Components\Builder as FormBuilder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class SeoPageResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = SeoPage::class;

    protected static ?string $permission = 'manage pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'SEO Pages';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page')->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')->required()->maxLength(220),
                    TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(220),
                    Select::make('type')->options(array_combine(SeoPage::TYPES, SeoPage::TYPES))->required(),
                    Select::make('status')->options(array_combine(SeoPage::STATUSES, SeoPage::STATUSES))->required()->default('draft'),
                    DateTimePicker::make('published_at'),
                    Select::make('related_category_id')->relationship('relatedCategory', 'name')->searchable()->preload(),
                    Select::make('related_brand_id')->relationship('relatedBrand', 'name')->searchable()->preload(),
                ]),
                FormBuilder::make('content')
                    ->label('CMS Builder')
                    ->addActionLabel('Add responsive block')
                    ->blocks(self::contentBlocks())
                    ->collapsible()
                    ->cloneable()
                    ->reorderable()
                    ->formatStateUsing(fn ($state): array => self::formatContentState($state))
                    ->dehydrateStateUsing(fn ($state): string => json_encode(['blocks' => $state ?? []], JSON_UNESCAPED_UNICODE))
                    ->required()
                    ->columnSpanFull(),
            ]),
            Section::make('Relations')->schema([
                Select::make('relatedProducts')->relationship('relatedProducts', 'name')->multiple()->searchable(),
                Select::make('relatedCategories')->relationship('relatedCategories', 'name')->multiple()->searchable()->preload(),
                Select::make('relatedBrands')->relationship('relatedBrands', 'name')->multiple()->searchable()->preload(),
            ])->collapsed(),
            Section::make('SEO')->schema([
                TextInput::make('meta_title')->maxLength(255),
                Textarea::make('meta_description')->rows(3),
                TextInput::make('meta_keywords')->maxLength(255),
                TextInput::make('canonical_url')->url(),
                TextInput::make('og_title')->maxLength(255),
                Textarea::make('og_description')->rows(3),
                FileUpload::make('og_image')->image()->directory('seo/og'),
                TextInput::make('schema_type')->maxLength(80),
                Textarea::make('schema_data')->helperText('JSON object')->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null)->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(50),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(SeoPage::TYPES, SeoPage::TYPES)),
                SelectFilter::make('status')->options(array_combine(SeoPage::STATUSES, SeoPage::STATUSES)),
                TrashedFilter::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeoPages::route('/'),
            'create' => CreateSeoPage::route('/create'),
            'edit' => EditSeoPage::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): EloquentBuilder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    private static function contentBlocks(): array
    {
        return [
            Block::make('rich_text')
                ->label('Rich Text')
                ->schema([
                    RichEditor::make('body')->required()->columnSpanFull(),
                    ...self::responsiveControls(),
                ]),
            Block::make('hero')
                ->label('Hero / Banner')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('heading')->required(),
                        TextInput::make('subtitle'),
                        TextInput::make('button_label'),
                        TextInput::make('button_url'),
                    ]),
                    Textarea::make('text')->rows(3)->columnSpanFull(),
                    ...self::responsiveImageFields(),
                    ...self::responsiveControls(),
                ]),
            Block::make('image_text')
                ->label('Image + Text')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('heading')->required(),
                        TextInput::make('subtitle'),
                    ]),
                    RichEditor::make('body')->columnSpanFull(),
                    ...self::responsiveImageFields(),
                    ...self::responsiveControls(),
                ]),
            Block::make('products_grid')
                ->label('Products Grid')
                ->schema([
                    TextInput::make('heading'),
                    Textarea::make('text')->rows(2),
                    ...self::responsiveControls(),
                ]),
            Block::make('categories_grid')
                ->label('Categories Grid')
                ->schema([
                    TextInput::make('heading'),
                    Textarea::make('text')->rows(2),
                    ...self::responsiveControls(),
                ]),
            Block::make('brand_campaign')
                ->label('Brand Campaign')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('heading')->required(),
                        TextInput::make('subtitle'),
                        TextInput::make('button_label'),
                        TextInput::make('button_url'),
                    ]),
                    Textarea::make('text')->rows(3)->columnSpanFull(),
                    ...self::responsiveImageFields(),
                    ...self::responsiveControls(),
                ]),
        ];
    }

    private static function responsiveImageFields(): array
    {
        return [
            Grid::make(3)->schema([
                FileUpload::make('desktop_image')->label('Desktop Image')->image()->directory('cms/desktop'),
                FileUpload::make('tablet_image')->label('Tablet Image')->image()->directory('cms/tablet'),
                FileUpload::make('mobile_image')->label('Mobile Image')->image()->directory('cms/mobile'),
            ]),
        ];
    }

    private static function responsiveControls(): array
    {
        return [
            Tabs::make('Responsive Preview And Settings')
                ->tabs([
                    self::deviceTab('desktop', 'Desktop 1200px+'),
                    self::deviceTab('tablet', 'Tablet 768px-1199px'),
                    self::deviceTab('mobile', 'Mobile below 768px'),
                ])
                ->columnSpanFull(),
        ];
    }

    private static function deviceTab(string $device, string $label): Tab
    {
        $columnOptions = match ($device) {
            'desktop' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6],
            'tablet' => [1 => 1, 2 => 2, 3 => 3, 4 => 4],
            default => [1 => 1, 2 => 2],
        };

        return Tab::make($label)->schema([
            Toggle::make("responsive.$device.visible")
                ->label("Show on $label")
                ->default(true),
            Grid::make(4)->schema([
                Select::make("responsive.$device.layout.width")
                    ->label('Width')
                    ->options(['full' => 'Full', 'container' => 'Container', 'auto' => 'Auto'])
                    ->default('full'),
                TextInput::make("responsive.$device.layout.max_width")
                    ->label('Max width')
                    ->placeholder('1200px'),
                Select::make("responsive.$device.layout.columns")
                    ->label('Columns')
                    ->options($columnOptions)
                    ->default($device === 'desktop' ? 4 : ($device === 'tablet' ? 2 : 1)),
                Select::make("responsive.$device.layout.spacing")
                    ->label('Spacing')
                    ->options(['none' => 'None', 'xs' => 'XS', 'sm' => 'SM', 'md' => 'MD', 'lg' => 'LG', 'xl' => 'XL'])
                    ->default($device === 'desktop' ? 'lg' : 'sm'),
                Select::make("responsive.$device.layout.alignment")
                    ->label('Alignment')
                    ->options(['left' => 'Left', 'center' => 'Center', 'right' => 'Right'])
                    ->default('left'),
            ]),
            Grid::make(3)->schema([
                Select::make("responsive.$device.typography.heading_size")->label('Heading size')->options(self::typographyOptions())->default($device === 'desktop' ? '4xl' : '2xl'),
                Select::make("responsive.$device.typography.subtitle_size")->label('Subtitle size')->options(self::typographyOptions())->default($device === 'desktop' ? 'xl' : 'md'),
                Select::make("responsive.$device.typography.text_size")->label('Text size')->options(self::typographyOptions())->default('md'),
                TextInput::make("responsive.$device.typography.custom_heading_size")->label('Custom heading')->placeholder('42px'),
                TextInput::make("responsive.$device.typography.custom_subtitle_size")->label('Custom subtitle')->placeholder('22px'),
                TextInput::make("responsive.$device.typography.custom_text_size")->label('Custom text')->placeholder('16px'),
            ]),
            Grid::make(3)->schema([
                Select::make("responsive.$device.buttons.layout")
                    ->label('Button layout')
                    ->options(['inline' => 'Inline', 'stacked' => 'Stacked'])
                    ->default($device === 'mobile' ? 'stacked' : 'inline'),
                Select::make("responsive.$device.buttons.alignment")
                    ->label('Button alignment')
                    ->options(['left' => 'Left', 'center' => 'Center', 'right' => 'Right'])
                    ->default($device === 'mobile' ? 'center' : 'left'),
                Toggle::make("responsive.$device.buttons.full_width")->label('Full width buttons')->default($device === 'mobile'),
            ]),
            Grid::make(4)->schema([
                TextInput::make("responsive.$device.spacing.padding.top")->label('Padding top')->placeholder('32px'),
                TextInput::make("responsive.$device.spacing.padding.right")->label('Padding right')->placeholder('24px'),
                TextInput::make("responsive.$device.spacing.padding.bottom")->label('Padding bottom')->placeholder('32px'),
                TextInput::make("responsive.$device.spacing.padding.left")->label('Padding left')->placeholder('24px'),
                TextInput::make("responsive.$device.spacing.margin.top")->label('Margin top')->placeholder('0'),
                TextInput::make("responsive.$device.spacing.margin.right")->label('Margin right')->placeholder('auto'),
                TextInput::make("responsive.$device.spacing.margin.bottom")->label('Margin bottom')->placeholder('0'),
                TextInput::make("responsive.$device.spacing.margin.left")->label('Margin left')->placeholder('auto'),
            ]),
            Grid::make(3)->schema([
                TextInput::make("responsive.$device.height")->label('Hero/banner height')->placeholder($device === 'desktop' ? '700px' : ($device === 'tablet' ? '500px' : '320px')),
                TextInput::make("responsive.$device.carousel.slides_per_view")->numeric()->label('Slides per view')->default($device === 'desktop' ? 5 : ($device === 'tablet' ? 3 : 1)),
                Toggle::make("responsive.$device.ordering.media_first")
                    ->label('Image before text')
                    ->default($device !== 'mobile'),
            ]),
        ]);
    }

    private static function typographyOptions(): array
    {
        return array_combine(ResponsiveBlockDefaults::TYPOGRAPHY_SIZES, ResponsiveBlockDefaults::TYPOGRAPHY_SIZES);
    }

    private static function formatContentState(mixed $state): array
    {
        if (is_array($state)) {
            return $state['blocks'] ?? $state;
        }

        if (! is_string($state) || $state === '') {
            return [];
        }

        $decoded = json_decode($state, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded['blocks'] ?? $decoded;
        }

        return [[
            'type' => 'rich_text',
            'data' => [
                'body' => $state,
                'responsive' => ResponsiveBlockDefaults::defaultSettings(),
            ],
        ]];
    }
}
