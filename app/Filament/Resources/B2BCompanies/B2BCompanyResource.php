<?php

namespace App\Filament\Resources\B2BCompanies;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\B2BCompanies\Pages\CreateB2BCompany;
use App\Filament\Resources\B2BCompanies\Pages\EditB2BCompany;
use App\Filament\Resources\B2BCompanies\Pages\ListB2BCompanies;
use App\Models\B2BCompany;
use App\Services\B2B\B2BCompanyService;
use BackedEnum;
use Filament\Actions\Action;
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

class B2BCompanyResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = B2BCompany::class;

    protected static ?string $permission = 'manage b2b companies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = 'B2B Companies';

    protected static string|UnitEnum|null $navigationGroup = 'B2B';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Company')->schema([
                Grid::make(3)->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('vat_number')->required()->maxLength(64),
                    TextInput::make('company_number')->maxLength(64),
                    TextInput::make('mol')->maxLength(255),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('phone')->maxLength(64),
                    TextInput::make('website')->url()->maxLength(255),
                    Select::make('status')->options(array_combine(B2BCompany::STATUSES, B2BCompany::STATUSES))->required(),
                    Select::make('approval_status')->options(array_combine(B2BCompany::APPROVAL_STATUSES, B2BCompany::APPROVAL_STATUSES))->required(),
                    TextInput::make('credit_limit')->numeric()->prefix('EUR'),
                    TextInput::make('payment_terms')->maxLength(255),
                ]),
                Textarea::make('billing_address')->columnSpanFull(),
                Textarea::make('shipping_address')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('vat_number')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('approval_status')->badge()->sortable(),
                TextColumn::make('credit_limit')->money('EUR')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(B2BCompany::STATUSES, B2BCompany::STATUSES)),
                SelectFilter::make('approval_status')->options(array_combine(B2BCompany::APPROVAL_STATUSES, B2BCompany::APPROVAL_STATUSES)),
            ])
            ->recordActions([
                Action::make('approve')->visible(fn (B2BCompany $record): bool => $record->approval_status !== 'approved')->action(fn (B2BCompany $record, B2BCompanyService $service) => $service->approve($record, auth()->user())),
                Action::make('reject')->visible(fn (B2BCompany $record): bool => $record->approval_status !== 'rejected')->action(fn (B2BCompany $record, B2BCompanyService $service) => $service->reject($record)),
                Action::make('suspend')->color('danger')->action(fn (B2BCompany $record) => $record->update(['status' => 'suspended'])),
                Action::make('activate')->color('success')->action(fn (B2BCompany $record) => $record->update(['status' => 'active'])),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListB2BCompanies::route('/'),
            'create' => CreateB2BCompany::route('/create'),
            'edit' => EditB2BCompany::route('/{record}/edit'),
        ];
    }
}
