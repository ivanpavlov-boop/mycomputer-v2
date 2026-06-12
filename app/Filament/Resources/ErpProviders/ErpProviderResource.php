<?php

namespace App\Filament\Resources\ErpProviders;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ErpProviders\Pages\CreateErpProvider;
use App\Filament\Resources\ErpProviders\Pages\EditErpProvider;
use App\Filament\Resources\ErpProviders\Pages\ListErpProviders;
use App\Models\ErpProvider;
use App\Services\Erp\ErpService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ErpProviderResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ErpProvider::class;

    protected static ?string $permission = 'manage erp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'ERP Providers';

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('code')->options(array_combine(ErpProvider::CODES, ErpProvider::CODES))->required()->unique(ignoreRecord: true),
            Select::make('status')->options(array_combine(ErpProvider::STATUSES, ErpProvider::STATUSES))->required()->default('inactive'),
            KeyValue::make('credentials')->helperText('Encrypted at rest. Do not store unnecessary secrets.')->columnSpanFull(),
            KeyValue::make('settings')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('sync_jobs_count')->counts('syncJobs')->label('Sync jobs'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(array_combine(ErpProvider::STATUSES, ErpProvider::STATUSES))])
            ->recordActions([
                EditAction::make(),
                Action::make('testConnection')
                    ->label('Test')
                    ->icon(Heroicon::OutlinedSignal)
                    ->action(function (ErpProvider $record): void {
                        $response = app(ErpService::class)->provider($record)->testConnection();
                        Notification::make()
                            ->title(($response['success'] ?? false) ? 'ERP connection OK' : 'ERP connection unavailable')
                            ->body($response['message'] ?? null)
                            ->send();
                    }),
                Action::make('enable')->icon(Heroicon::OutlinedCheckCircle)->action(fn (ErpProvider $record) => $record->update(['status' => 'active'])),
                Action::make('disable')->icon(Heroicon::OutlinedXCircle)->action(fn (ErpProvider $record) => $record->update(['status' => 'inactive'])),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErpProviders::route('/'),
            'create' => CreateErpProvider::route('/create'),
            'edit' => EditErpProvider::route('/{record}/edit'),
        ];
    }
}
