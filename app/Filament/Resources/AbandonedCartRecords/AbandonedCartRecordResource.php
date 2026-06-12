<?php

namespace App\Filament\Resources\AbandonedCartRecords;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AbandonedCartRecords\Pages\ListAbandonedCartRecords;
use App\Jobs\ProcessAbandonedCartEmailJob;
use App\Models\AbandonedCartRecord;
use App\Services\Email\EmailMarketingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AbandonedCartRecordResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AbandonedCartRecord::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Abandoned Carts';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('cart_snapshot')
                ->disabled()
                ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->rows(18),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('user.email')->label('User')->searchable(),
                TextColumn::make('cart_total')->money('BGN')->sortable(),
                TextColumn::make('items_count')->numeric()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('emails_sent')->numeric()->sortable(),
                TextColumn::make('first_email_sent_at')->dateTime()->sortable(),
                TextColumn::make('second_email_sent_at')->dateTime()->sortable(),
                TextColumn::make('third_email_sent_at')->dateTime()->sortable(),
                TextColumn::make('recovered_at')->dateTime()->sortable(),
                TextColumn::make('recovered_order_id')->label('Order')->sortable(),
                TextColumn::make('recovered_revenue')->money('BGN')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(AbandonedCartRecord::STATUSES, AbandonedCartRecord::STATUSES)),
                Filter::make('recovered')->query(fn (Builder $query): Builder => $query->whereNotNull('recovered_at')),
                Filter::make('pending_emails')->query(fn (Builder $query): Builder => $query
                    ->whereNotIn('status', ['recovered', 'expired', 'suppressed'])
                    ->where('emails_sent', '<', 3)),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('view_snapshot')
                    ->label('Snapshot')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading('Cart snapshot')
                    ->modalContent(fn (AbandonedCartRecord $record): View => view('filament.resources.abandoned-cart-records.snapshot', ['record' => $record]))
                    ->modalSubmitAction(false),
                Action::make('resend')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->requiresConfirmation()
                    ->action(function (AbandonedCartRecord $record): void {
                        ProcessAbandonedCartEmailJob::dispatch($record->id);
                        Notification::make()->title('Recovery email queued')->success()->send();
                    }),
                Action::make('suppress')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->requiresConfirmation()
                    ->action(function (AbandonedCartRecord $record, EmailMarketingService $service): void {
                        $service->suppress($record);
                        Notification::make()->title('Record suppressed')->success()->send();
                    }),
                Action::make('expire')
                    ->icon(Heroicon::OutlinedClock)
                    ->requiresConfirmation()
                    ->action(function (AbandonedCartRecord $record, EmailMarketingService $service): void {
                        $service->markExpired($record);
                        Notification::make()->title('Record marked expired')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAbandonedCartRecords::route('/'),
        ];
    }
}
