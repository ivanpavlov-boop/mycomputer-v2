<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AdminStaffPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;

        if (mb_strlen($password) < 10) {
            $fail(__('admin-password-reset.validation.password.min'));
        }

        if (! preg_match('/[A-Z]/', $password)) {
            $fail(__('admin-password-reset.validation.password.uppercase'));
        }

        if (! preg_match('/[a-z]/', $password)) {
            $fail(__('admin-password-reset.validation.password.lowercase'));
        }

        if (! preg_match('/[0-9]/', $password)) {
            $fail(__('admin-password-reset.validation.password.number'));
        }

        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            $fail(__('admin-password-reset.validation.password.symbol'));
        }
    }
}
