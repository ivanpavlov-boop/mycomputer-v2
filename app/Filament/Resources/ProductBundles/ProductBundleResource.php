<?php

namespace App\Filament\Resources\ProductBundles;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductBundles\Pages\CreateProductBundle;
use App\Filament\Resources\ProductBundles\Pages\EditProductBundle;
use App\Filament\Resources\ProductBundles\Pages\ListProductBundles;
use App\Models\ProductBundle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProductBundleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductBundle::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Product Bundles';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bundle')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                    Select::make('status')->options(array_combine(ProductBundle::STATUSES, ProductBundle::STATUSES))->required()->default('draft'),
                    Select::make('type')->options(array_combine(ProductBundle::TYPES, ProductBundle::TYPES))->required()->default('fixed_bundle'),
                    TextInput::make('sort_order')->numeric()->default(0),
                    FileUpload::make('image_path')->image()->directory('bundles'),
                    Textarea::make('short_description')->columnSpanFull(),
                    Textarea::make('description')->columnSpanFull(),
                ])->columns(2),
            Section::make('Pricing')
                ->schema([
                    Select::make('pricing_type')->options(array_combine(ProductBundle::PRICING_TYPES, ProductBundle::PRICING_TYPES))->required()->default('sum_items'),
                    TextInput::make('fixed_price')->numeric()->prefix('EUR'),
                    TextInput::make('discount_value')->numeric(),
                    DateTimePicker::make('starts_at'),
                    DateTimePicker::make('ends_at'),
                ])->columns(2),
            Section::make('Fixed Items')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->schema([
                            Select::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                            TextInput::make('component_group')->maxLength(255),
                            TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required(),
                            TextInput::make('min_quantity')->numeric()->minValue(1),
                            TextInput::make('max_quantity')->numeric()->minValue(1),
                            Select::make('is_required')->options([1 => 'Required', 0 => 'Optional'])->default(1)->required(),
                            TextInput::make('sort_order')->numeric()->default(0),
                        ])->columns(3)->columnSpanFull(),
                ]),
            Section::make('Configurable Options')
                ->schema([
                    Repeater::make('options')
                        ->relationship()
                        ->schema([
                            TextInput::make('component_group')->required()->maxLength(255),
                            Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->required(),
                            TextInput::make('price_adjustment')->numeric()->prefix('EUR')->default(0),
                            Select::make('is_default')->options([1 => 'Default', 0 => 'Alternative'])->default(0)->required(),
                            TextInput::make('sort_order')->numeric()->default(0),
                        ])->columns(3)->columnSpanFull(),
                ]),
            Section::make('SEO')
                ->schema([
                    TextInput::make('meta_title')->maxLength(255),
                    Textarea::make('meta_description')->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->disk('public'),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('pricing_type')->badge()->sortable(),
                TextColumn::make('order_items_count')->counts('orderItems')->label('Sales')->sortable(),
                TextColumn::make('sort_order')->numeric()->sortable(),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(ProductBundle::TYPES, ProductBundle::TYPES)),
                SelectFilter::make('status')->options(array_combine(ProductBundle::STATUSES, ProductBundle::STATUSES)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->action(function (ProductBundle $record): void {
                        $copy = $record->replicate(['slug']);
                        $copy->name = $record->name.' Copy';
                        $copy->slug = $record->slug.'-copy-'.now()->timestamp;
                        $copy->status = 'draft';
                        $copy->save();
                        foreach ($record->items as $item) {
                            $copy->items()->create($item->only(['product_id', 'component_group', 'is_required', 'quantity', 'min_quantity', 'max_quantity', 'sort_order']));
                        }
                        foreach ($record->options as $option) {
                            $copy->options()->create($option->only(['component_group', 'product_id', 'price_adjustment', 'is_default', 'sort_order']));
                        }
                    }),
                Action::make('activate')->icon(Heroicon::OutlinedCheckCircle)->action(fn (ProductBundle $record) => $record->update(['status' => 'active'])),
                Action::make('deactivate')->icon(Heroicon::OutlinedXCircle)->action(fn (ProductBundle $record) => $record->update(['status' => 'inactive'])),
                RestoreAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function canDelete(Model $record): bool
    {
        return $record->cartItems()->doesntExist()
            && $record->orderItems()->doesntExist()
            && parent::canDelete($record);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductBundles::route('/'),
            'create' => CreateProductBundle::route('/create'),
            'edit' => EditProductBundle::route('/{record}/edit'),
        ];
    }
}
