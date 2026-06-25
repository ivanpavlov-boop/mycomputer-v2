<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;
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
                    Select::make('role')
                        ->label('Role')
                        ->options(User::roleOptions())
                        ->default(User::ROLE_PRODUCT_DATA_ENTRY)
                        ->required()
                        ->rule('in:'.implode(',', array_keys(User::roleOptions()))),
                    DateTimePicker::make('last_login_at')->disabled()->dehydrated(false),
                    TextInput::make('password')
                        ->password()
                        ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->rule(Password::min(8)->mixedCase()->numbers()),
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
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::roleLabel($state))
                    ->sortable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('last_login_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('addresses_count')->counts('addresses')->label('Addresses'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(User::roleOptions()),
                SelectFilter::make('is_active')->options([1 => 'Active', 0 => 'Inactive']),
            ])
            ->recordActions([
                Action::make('sendPasswordResetLink')
                    ->label('Send password reset link')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalHeading('Send password reset link')
                    ->modalDescription('A secure password reset link will be emailed to the active user. Plaintext passwords are never shown or emailed.')
                    ->modalSubmitActionLabel('Send reset link')
                    ->visible(fn (User $record): bool => static::canSendPasswordResetLink($record))
                    ->action(fn (User $record) => static::sendPasswordResetLink($record)),
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
                static::deleteUserAction(),
            ]);
    }

    public static function deleteUserAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Delete user')
            ->modalHeading('Delete user')
            ->modalDescription('This will remove the user from active admin access but keep historical records.')
            ->modalSubmitActionLabel('Delete user')
            ->visible(fn (User $record): bool => static::canDelete($record));
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessResource()
            && $record instanceof User
            && ! static::isCurrentUser($record)
            && ! static::isLastActiveSuperAdmin($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canDeactivate(User $record): bool
    {
        return static::canAccessResource()
            && ! static::isCurrentUser($record)
            && ! static::isLastActiveSuperAdmin($record);
    }

    public static function canSendPasswordResetLink(User $record): bool
    {
        return static::canAccessResource()
            && $record->is_active
            && ! $record->trashed();
    }

    public static function sendPasswordResetLink(User $record): void
    {
        if (! static::canSendPasswordResetLink($record)) {
            Notification::make()
                ->title('Password reset link was not sent')
                ->body('Only active users can receive password reset links.')
                ->danger()
                ->send();

            return;
        }

        try {
            $adminPanel = Filament::getPanel('admin');
            $status = PasswordBroker::broker($adminPanel->getAuthPasswordBroker())->sendResetLink(
                ['email' => $record->email],
                function (CanResetPassword $user, #[\SensitiveParameter] string $token) use ($adminPanel): void {
                    if (! $user instanceof User || ! $user->isActiveAdminAccount() || ! $user->canAccessPanel($adminPanel)) {
                        return;
                    }

                    $notification = app(FilamentResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = $adminPanel->getResetPasswordUrl($token, $user);

                    $user->notify($notification);

                    if (class_exists(PasswordResetLinkSent::class)) {
                        event(new PasswordResetLinkSent($user));
                    }
                },
            );
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Password reset link could not be sent')
                ->body('Check the mail configuration and try again.')
                ->danger()
                ->send();

            return;
        }

        $notification = Notification::make()
            ->title($status === PasswordBroker::RESET_LINK_SENT ? 'Password reset link sent' : 'Password reset link was not sent')
            ->body($status === PasswordBroker::RESET_LINK_SENT ? 'The user will receive a secure password reset link by email.' : __($status));

        if ($status === PasswordBroker::RESET_LINK_SENT) {
            $notification->success();
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function validateRoleSafety(User $record, array $data): void
    {
        $newRole = $data['role'] ?? $record->role;
        $newActiveState = (bool) ($data['is_active'] ?? $record->is_active);

        if (static::wasLastActiveSuperAdmin($record) && ($newRole !== User::ROLE_SUPER_ADMIN || ! $newActiveState)) {
            throw ValidationException::withMessages([
                'role' => 'The last active Super Admin cannot be downgraded or deactivated.',
            ]);
        }
    }

    public static function syncPrimaryRole(User $record): void
    {
        if (! $record->role || ! array_key_exists($record->role, User::roleOptions())) {
            return;
        }

        Role::findOrCreate($record->role, 'web');
        $record->syncRoles([$record->role]);
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

    private static function isLastActiveSuperAdmin(User $record): bool
    {
        if (! $record->is_active || $record->primaryRole() !== User::ROLE_SUPER_ADMIN) {
            return false;
        }

        return static::activeSuperAdminCount() <= 1;
    }

    private static function wasLastActiveSuperAdmin(User $record): bool
    {
        $originalRole = $record->getOriginal('role');
        $originalActiveState = (bool) $record->getOriginal('is_active');
        $wasSuperAdmin = $originalRole === User::ROLE_SUPER_ADMIN
            || (blank($originalRole) && $record->hasAnyRole([User::ROLE_SUPER_ADMIN, 'admin']));

        return $originalActiveState && $wasSuperAdmin && static::activeSuperAdminCount() <= 1;
    }

    private static function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('role', User::ROLE_SUPER_ADMIN)
                    ->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', [User::ROLE_SUPER_ADMIN, 'admin']));
            })
            ->count();
    }

    protected static function canAccessResource(): bool
    {
        return (bool) auth()->user()?->canManageUsers();
    }
}
