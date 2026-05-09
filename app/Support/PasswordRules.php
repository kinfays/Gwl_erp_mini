<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function account(): Password
    {
        return Password::min(5)->letters()->numbers();
    }
}
