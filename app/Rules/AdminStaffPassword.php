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
            $fail('Паролата трябва да бъде поне 10 символа.');
        }

        if (! preg_match('/[A-Z]/', $password)) {
            $fail('Паролата трябва да съдържа главна буква.');
        }

        if (! preg_match('/[a-z]/', $password)) {
            $fail('Паролата трябва да съдържа малка буква.');
        }

        if (! preg_match('/[0-9]/', $password)) {
            $fail('Паролата трябва да съдържа цифра.');
        }

        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            $fail('Паролата трябва да съдържа специален символ.');
        }
    }
}
