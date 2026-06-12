<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = User::class;

    protected static ?string $permission = 'manage users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Users';

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('User')->schema([
                Grid::make(3)->schema([
                    TextInput::make('first_name')->required(),
                    TextInput::make('last_name')->required(),
                    TextInput::make('name')->required(),
                    TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                    TextInput::make('phone'),
                    TextInput::make('company_name'),
                    TextInput::make('vat_number'),
                    Toggle::make('is_active')->default(true),
                    DateTimePicker::make('last_login_at')->disabled()->dehydrated(false),
                    TextInput::make('password')
                        ->password()
                        ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->rule(Password::min(8)->mixedCase()->numbers()),
                    Select::make('roles')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                TextColumn::make('roles.name')->badge(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('last_login_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('addresses_count')->counts('addresses')->label('Addresses'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('roles')->relationship('roles', 'name')->multiple(),
                SelectFilter::make('is_active')->options([1 => 'Active', 0 => 'Inactive']),
            ])
            ->recordActions([
                Action::make('resetPassword')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->required()
                            ->confirmed()
                            ->rule(Password::min(8)->mixedCase()->numbers()),
                        TextInput::make('password_confirmation')->password()->required(),
                    ])
                    ->action(fn (User $record, array $data) => $record->update(['password' => $data['password']])),
                Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (User $record): bool => ! $record->is_active)
                    ->action(fn (User $record) => $record->update(['is_active' => true])),
                Action::make('deactivate')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (User $record): bool => $record->is_active && static::canDeactivate($record))
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update(['is_active' => false])),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessResource()
            && $record instanceof User
            && ! static::isCurrentUser($record)
            && ! static::isLastAdmin($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canDeactivate(User $record): bool
    {
        return static::canAccessResource()
            && ! static::isCurrentUser($record)
            && ! static::isLastAdmin($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    private static function isCurrentUser(User $record): bool
    {
        return auth()->id() === $record->id;
    }

    private static function isLastAdmin(User $record): bool
    {
        return $record->hasRole('admin')
            && User::role('admin')->where('is_active', true)->count() <= 1;
    }
}
