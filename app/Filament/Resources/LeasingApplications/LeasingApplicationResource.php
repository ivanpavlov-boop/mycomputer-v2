<?php

namespace App\Filament\Resources\LeasingApplications;

use App\Filament\Resources\LeasingApplications\Pages\ListLeasingApplications;
use App\Filament\Resources\LeasingApplications\Pages\ViewLeasingApplication;
use App\Models\LeasingApplication;
use App\Models\LeasingApplicationActivity;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class LeasingApplicationResource extends Resource
{
    protected static ?string $model = LeasingApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Лизингови заявки';

    protected static string|UnitEnum|null $navigationGroup = 'Продажби';

    protected static ?string $modelLabel = 'лизингова заявка';

    protected static ?string $pluralModelLabel = 'лизингови заявки';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('reference')->label('Референция')->copyable(),
                        TextEntry::make('order.order_number')->label('Поръчка')->copyable(),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => LeasingApplication::statusLabel($state))
                            ->color(fn (?string $state): string => LeasingApplication::statusColor($state)),
                        TextEntry::make('assignedTo.name')->label('Отговорник')->placeholder('Няма'),
                        TextEntry::make('order.customer_name')->label('Клиент'),
                        TextEntry::make('order.customer_phone')->label('Телефон'),
                        TextEntry::make('order.customer_email')->label('E-mail'),
                        TextEntry::make('order.grand_total')->label('Обща сума')->money('EUR'),
                        TextEntry::make('requested_term_months')->label('Желан срок')->suffix(' месеца'),
                        TextEntry::make('requested_down_payment')->label('Първоначална вноска')->money('EUR'),
                        TextEntry::make('preferred_contact_method')
                            ->label('Начин за контакт')
                            ->formatStateUsing(fn (?string $state): string => static::contactMethodLabel($state)),
                        TextEntry::make('preferred_contact_time')
                            ->label('Време за контакт')
                            ->formatStateUsing(fn (?string $state): string => static::contactTimeLabel($state))
                            ->placeholder('Без предпочитание'),
                        TextEntry::make('contact_consent_at')->label('Съгласие на')->dateTime(),
                        TextEntry::make('consent_version')->label('Версия на съгласието'),
                        TextEntry::make('submitted_at')->label('Подадена на')->dateTime(),
                        TextEntry::make('updated_at')->label('Обновена на')->dateTime(),
                    ]),
                    TextEntry::make('customer_note')
                        ->label('Коментар от клиента')
                        ->placeholder('Няма')
                        ->columnSpanFull(),
                ]),
            Section::make('Продукти')
                ->schema([
                    TextEntry::make('order_products')
                        ->label('Продукти в поръчката')
                        ->state(fn (LeasingApplication $record): array => $record->order->items
                            ->map(fn ($item): string => "{$item->product_name} × {$item->quantity} · {$item->total_price} EUR")
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->columnSpanFull(),
                ]),
            Section::make('Вътрешна обработка')
                ->description('Не въвеждайте ЕГН, данни от лична карта, банкови данни или финансови документи.')
                ->schema([
                    TextEntry::make('activity_history')
                        ->label('История')
                        ->state(fn (LeasingApplication $record): array => $record->activities
                            ->map(fn (LeasingApplicationActivity $activity): string => static::activityLabel($activity))
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('Няма записани действия')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Референция')->searchable()->sortable(),
                TextColumn::make('order.order_number')->label('Поръчка')->searchable()->sortable(),
                TextColumn::make('order.customer_name')->label('Клиент')->searchable(),
                TextColumn::make('order.customer_phone')->label('Телефон')->searchable(),
                TextColumn::make('order.customer_email')->label('E-mail')->searchable(),
                TextColumn::make('order.grand_total')->label('Обща сума')->money('EUR')->sortable(),
                TextColumn::make('requested_term_months')->label('Срок')->suffix(' м.')->sortable(),
                TextColumn::make('requested_down_payment')->label('Първоначална вноска')->money('EUR')->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => LeasingApplication::statusLabel($state))
                    ->color(fn (?string $state): string => LeasingApplication::statusColor($state))
                    ->sortable(),
                TextColumn::make('assignedTo.name')->label('Отговорник')->placeholder('Няма')->sortable(),
                TextColumn::make('submitted_at')->label('Подадена')->dateTime()->sortable(),
                TextColumn::make('updated_at')->label('Обновена')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(LeasingApplication::STATUS_LABELS),
                SelectFilter::make('assigned_to_user_id')
                    ->label('Отговорник')
                    ->options(fn (): array => static::assignableUsers()),
                Filter::make('unassigned')
                    ->label('Без отговорник')
                    ->query(fn (Builder $query): Builder => $query->whereNull('assigned_to_user_id')),
                Filter::make('submitted_at')
                    ->label('Дата на подаване')
                    ->form([
                        DatePicker::make('from')->label('От'),
                        DatePicker::make('until')->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('submitted_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('submitted_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()->label('Преглед'),
            ])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'order.items',
            'assignedTo',
            'activities.actor',
        ]);
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', LeasingApplication::class);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('view', $record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeasingApplications::route('/'),
            'view' => ViewLeasingApplication::route('/{record}'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function assignableUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->isActiveAdminAccount()
                && ($user->isSuperAdmin() || $user->can('manage orders')))
            ->sortBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function contactMethodLabel(?string $value): string
    {
        return match ($value) {
            'phone' => 'Телефон',
            'email' => 'E-mail',
            'either' => 'Телефон или e-mail',
            default => 'Неизвестно',
        };
    }

    private static function contactTimeLabel(?string $value): string
    {
        return match ($value) {
            'anytime' => 'Без предпочитание',
            'morning' => 'Сутрин',
            'afternoon' => 'Следобед',
            'evening' => 'Вечер',
            null, '' => 'Без предпочитание',
            default => 'Неизвестно',
        };
    }

    private static function activityLabel(LeasingApplicationActivity $activity): string
    {
        $actor = $activity->actor?->name ?? 'Система';
        $date = $activity->created_at?->format('d.m.Y H:i') ?? '-';

        return match ($activity->event_type) {
            LeasingApplicationActivity::EVENT_SUBMITTED => "{$date} · Подадена заявка",
            LeasingApplicationActivity::EVENT_STATUS_CHANGED => "{$date} · {$actor} промени статуса от "
                .LeasingApplication::statusLabel($activity->from_status).' на '
                .LeasingApplication::statusLabel($activity->to_status),
            LeasingApplicationActivity::EVENT_ASSIGNED => "{$date} · {$actor} назначи отговорник",
            LeasingApplicationActivity::EVENT_NOTE_ADDED => "{$date} · {$actor}: {$activity->note}",
            default => "{$date} · Неизвестно действие",
        };
    }
}
