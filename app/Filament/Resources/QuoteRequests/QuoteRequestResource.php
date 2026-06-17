<?php

namespace App\Filament\Resources\QuoteRequests;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\QuoteRequests\Pages\CreateQuoteRequest;
use App\Filament\Resources\QuoteRequests\Pages\EditQuoteRequest;
use App\Filament\Resources\QuoteRequests\Pages\ListQuoteRequests;
use App\Models\QuoteRequest;
use App\Services\B2B\QuoteRequestService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class QuoteRequestResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = QuoteRequest::class;

    protected static ?string $permission = 'manage quotes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Quote Requests';

    protected static string|UnitEnum|null $navigationGroup = 'B2B';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quote')->schema([
                Grid::make(3)->schema([
                    TextInput::make('quote_number')->disabled()->dehydrated(false),
                    TextInput::make('customer_name')->required(),
                    TextInput::make('customer_email')->email()->required(),
                    TextInput::make('customer_phone'),
                    TextInput::make('company_name'),
                    TextInput::make('vat_number'),
                    TextInput::make('status')->disabled()->dehydrated(),
                    DatePicker::make('valid_until'),
                    TextInput::make('grand_total')->numeric()->prefix('EUR')->disabled()->dehydrated(),
                ]),
                Textarea::make('notes')->columnSpanFull(),
                Textarea::make('internal_notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quote_number')->searchable()->sortable(),
                TextColumn::make('customer_name')->searchable(),
                TextColumn::make('customer_email')->searchable(),
                TextColumn::make('company.name')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('grand_total')->money('EUR')->sortable(),
                TextColumn::make('valid_until')->date()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(QuoteRequest::STATUSES, QuoteRequest::STATUSES)),
                SelectFilter::make('source')->options(array_combine(QuoteRequest::SOURCES, QuoteRequest::SOURCES)),
            ])
            ->recordActions([
                Action::make('underReview')->label('Under review')->action(fn (QuoteRequest $record) => $record->update(['status' => 'under_review'])),
                Action::make('sendOffer')
                    ->label('Send offer')
                    ->schema(fn (QuoteRequest $record): array => [
                        Repeater::make('items')
                            ->default($record->items()->get()->map(fn ($item): array => ['id' => $item->id, 'product_name' => $item->product_name, 'offered_price' => $item->offered_price])->all())
                            ->schema([
                                TextInput::make('id')->disabled()->dehydrated(),
                                TextInput::make('product_name')->disabled()->dehydrated(false),
                                TextInput::make('offered_price')->numeric()->required(),
                            ]),
                        DatePicker::make('valid_until')->required()->default(now()->addDays(7)),
                        Textarea::make('internal_notes'),
                    ])
                    ->action(fn (QuoteRequest $record, array $data, QuoteRequestService $quotes) => $quotes->offer($record, $data)),
                Action::make('expire')->color('warning')->action(fn (QuoteRequest $record) => $record->update(['status' => 'expired'])),
                Action::make('convert')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('convert quotes'))
                    ->requiresConfirmation()
                    ->action(fn (QuoteRequest $record, QuoteRequestService $quotes) => $quotes->accept($record)),
                Action::make('internalNote')
                    ->schema([Textarea::make('message')->required()])
                    ->action(fn (QuoteRequest $record, array $data) => $record->messages()->create([
                        'user_id' => auth()->id(),
                        'sender_type' => 'admin',
                        'message' => $data['message'],
                        'is_internal' => true,
                    ])),
                Action::make('pdf')->label('PDF placeholder')->disabled(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuoteRequests::route('/'),
            'create' => CreateQuoteRequest::route('/create'),
            'edit' => EditQuoteRequest::route('/{record}/edit'),
        ];
    }
}
