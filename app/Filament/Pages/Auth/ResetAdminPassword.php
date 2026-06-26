<?php

namespace App\Filament\Pages\Auth;

use App\Rules\AdminStaffPassword;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResetAdminPassword extends ResetPassword
{
    /**
     * @throws ValidationException
     */
    public function resetPassword(): ?PasswordResetResponse
    {
        Validator::make(
            [
                'password' => $this->password,
                'passwordConfirmation' => $this->passwordConfirmation,
            ],
            [
                'password' => ['required', new AdminStaffPassword, 'same:passwordConfirmation'],
                'passwordConfirmation' => ['required'],
            ],
            [
                'password.same' => 'Потвърждението на паролата не съвпада.',
            ],
        )->validate();

        return parent::resetPassword();
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->rule(new AdminStaffPassword)
            ->validationMessages([
                'same' => 'Потвърждението на паролата не съвпада.',
            ]);
    }
}
