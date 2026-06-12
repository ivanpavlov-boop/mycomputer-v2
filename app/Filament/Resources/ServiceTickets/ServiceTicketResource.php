<?php

namespace App\Filament\Resources\ServiceTickets;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ServiceTickets\Pages\CreateServiceTicket;
use App\Filament\Resources\ServiceTickets\Pages\EditServiceTicket;
use App\Filament\Resources\ServiceTickets\Pages\ListServiceTickets;
use App\Models\ServiceTicket;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Table;
use UnitEnum;

class ServiceTicketResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ServiceTicket::class;

    protected static ?string $permission = 'manage service tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Service Tickets';

    protected static string|UnitEnum|null $navigationGroup = 'Service';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ticket')->schema([
                Grid::make(3)->schema([
                    TextInput::make('ticket_number')->disabled(),
                    Select::make('ticket_type')->options(array_combine(ServiceTicket::TYPES, ServiceTicket::TYPES))->required(),
                    Select::make('status')->options(array_combine(ServiceTicket::STATUSES, ServiceTicket::STATUSES))->required(),
                    Select::make('priority')->options(array_combine(ServiceTicket::PRIORITIES, ServiceTicket::PRIORITIES))->required(),
                    Select::make('assigned_to')->label('Technician')->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                    TextInput::make('serial_number'),
                ]),
                TextInput::make('subject')->required()->maxLength(255),
                Textarea::make('description')->rows(4)->required(),
            ]),
            Section::make('Warranty')->schema([
                Grid::make(3)->schema([
                    Select::make('order_id')->relationship('order', 'order_number')->searchable(),
                    Select::make('product_id')->relationship('product', 'name')->searchable(),
                    DatePicker::make('purchased_at'),
                    DatePicker::make('warranty_expires_at'),
                ]),
            ])->collapsed(),
            Section::make('Workflow')->schema([
                Textarea::make('diagnosis')->rows(3),
                Textarea::make('work_performed')->rows(3),
                Textarea::make('resolution')->rows(3),
                Repeater::make('parts_used')
                    ->schema([
                        TextInput::make('part')->required(),
                        TextInput::make('quantity')->numeric()->default(1),
                    ])
                    ->defaultItems(0),
                Grid::make(3)->schema([
                    DatePicker::make('repair_date'),
                    TextInput::make('refund_amount')->numeric(),
                    DatePicker::make('refund_date'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('ticket_number')->searchable()->sortable(),
            TextColumn::make('ticket_type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('subject')->searchable()->limit(40),
            TextColumn::make('user.email')->label('Customer')->searchable(),
            TextColumn::make('assignee.name')->label('Technician')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('ticket_type')->options(array_combine(ServiceTicket::TYPES, ServiceTicket::TYPES)),
            SelectFilter::make('status')->options(array_combine(ServiceTicket::STATUSES, ServiceTicket::STATUSES)),
            SelectFilter::make('priority')->options(array_combine(ServiceTicket::PRIORITIES, ServiceTicket::PRIORITIES)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceTickets::route('/'),
            'create' => CreateServiceTicket::route('/create'),
            'edit' => EditServiceTicket::route('/{record}/edit'),
        ];
    }
}
