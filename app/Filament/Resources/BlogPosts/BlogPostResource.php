<?php

namespace App\Filament\Resources\BlogPosts;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\BlogPosts\Pages\CreateBlogPost;
use App\Filament\Resources\BlogPosts\Pages\EditBlogPost;
use App\Filament\Resources\BlogPosts\Pages\ListBlogPosts;
use App\Models\BlogPost;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class BlogPostResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = BlogPost::class;

    protected static ?string $permission = 'manage blog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Blog Posts';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')->required()->maxLength(220),
                    TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(220),
                    Select::make('blog_category_id')->relationship('category', 'name')->searchable()->preload(),
                    Select::make('author_id')->relationship('author', 'email')->searchable()->preload(),
                    Select::make('status')->options(array_combine(BlogPost::STATUSES, BlogPost::STATUSES))->required()->default('draft'),
                    DateTimePicker::make('published_at'),
                    FileUpload::make('featured_image')->image()->directory('blog/posts'),
                    TextInput::make('reading_time')->numeric()->disabled()->dehydrated(false),
                ]),
                Textarea::make('excerpt')->rows(3)->columnSpanFull(),
                RichEditor::make('content')->required()->columnSpanFull(),
            ]),
            Section::make('Relations')->schema([
                Select::make('tags')->relationship('tags', 'name')->multiple()->searchable()->preload(),
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
                FileUpload::make('og_image')->image()->directory('blog/og'),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(50),
                TextColumn::make('category.name')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('published_at')->dateTime()->sortable(),
                TextColumn::make('reading_time')->label('Min')->sortable(),
                TextColumn::make('views_count')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(BlogPost::STATUSES, BlogPost::STATUSES)),
                SelectFilter::make('blog_category_id')->relationship('category', 'name')->searchable()->preload(),
                TrashedFilter::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogPosts::route('/'),
            'create' => CreateBlogPost::route('/create'),
            'edit' => EditBlogPost::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
