<?php

namespace App\Modules\Identity\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Single definition of the password policy (M0-09: 12 characters minimum).
 */
final class PasswordRules
{
    public const MIN_LENGTH = 12;

    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:200', Password::min(self::MIN_LENGTH)->letters()->numbers()];
    }
}
