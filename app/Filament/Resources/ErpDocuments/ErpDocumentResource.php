<?php

namespace App\Filament\Resources\ErpDocuments;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ErpDocuments\Pages\CreateErpDocument;
use App\Filament\Resources\ErpDocuments\Pages\EditErpDocument;
use App\Filament\Resources\ErpDocuments\Pages\ListErpDocuments;
use App\Models\ErpDocument;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ErpDocumentResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ErpDocument::class;

    protected static ?string $permission = 'view erp logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'ERP Documents';

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider_id')->relationship('provider', 'name')->searchable()->preload(),
            Select::make('order_id')->relationship('order', 'order_number')->searchable()->preload(),
            Select::make('document_type')->options(array_combine(ErpDocument::TYPES, ErpDocument::TYPES))->required(),
            Select::make('status')->options(array_combine(ErpDocument::STATUSES, ErpDocument::STATUSES))->required(),
            TextInput::make('external_id'),
            TextInput::make('document_number'),
            DatePicker::make('document_date'),
            TextInput::make('file_path'),
            KeyValue::make('payload')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider.name')->placeholder('None')->sortable(),
                TextColumn::make('order.order_number')->searchable(),
                TextColumn::make('document_type')->badge()->sortable(),
                TextColumn::make('document_number')->searchable(),
                TextColumn::make('external_id')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('document_date')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('document_type')->options(array_combine(ErpDocument::TYPES, ErpDocument::TYPES)),
                SelectFilter::make('status')->options(array_combine(ErpDocument::STATUSES, ErpDocument::STATUSES)),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErpDocuments::route('/'),
            'create' => CreateErpDocument::route('/create'),
            'edit' => EditErpDocument::route('/{record}/edit'),
        ];
    }
}
