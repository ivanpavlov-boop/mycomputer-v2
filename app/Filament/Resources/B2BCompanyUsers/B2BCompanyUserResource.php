<?php

namespace App\Filament\Resources\B2BCompanyUsers;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\B2BCompanyUsers\Pages\CreateB2BCompanyUser;
use App\Filament\Resources\B2BCompanyUsers\Pages\EditB2BCompanyUser;
use App\Filament\Resources\B2BCompanyUsers\Pages\ListB2BCompanyUsers;
use App\Models\B2BCompany;
use App\Models\B2BCompanyUser;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class B2BCompanyUserResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = B2BCompanyUser::class;

    protected static ?string $permission = 'manage b2b companies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'B2B Users';

    protected static string|UnitEnum|null $navigationGroup = 'B2B';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('b2b_company_id')->label('Company')->options(fn () => B2BCompany::query()->pluck('name', 'id'))->searchable()->required(),
            Select::make('user_id')->label('User')->options(fn () => User::query()->pluck('email', 'id'))->searchable()->required(),
            Select::make('role')->options(array_combine(B2BCompanyUser::ROLES, B2BCompanyUser::ROLES))->required(),
            Select::make('status')->options(array_combine(B2BCompanyUser::STATUSES, B2BCompanyUser::STATUSES))->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->searchable()->sortable(),
                TextColumn::make('user.email')->searchable(),
                TextColumn::make('role')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(array_combine(B2BCompanyUser::ROLES, B2BCompanyUser::ROLES)),
                SelectFilter::make('status')->options(array_combine(B2BCompanyUser::STATUSES, B2BCompanyUser::STATUSES)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListB2BCompanyUsers::route('/'),
            'create' => CreateB2BCompanyUser::route('/create'),
            'edit' => EditB2BCompanyUser::route('/{record}/edit'),
        ];
    }
}
