<?php

namespace App\Support\Installer;

use Illuminate\Validation\Rules\Password;

class InstallerPasswordRules
{
    /** @return array<int, Password|string> */
    public function rules(): array
    {
        return [
            'required',
            'string',
            Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
            'confirmed',
        ];
    }
}
