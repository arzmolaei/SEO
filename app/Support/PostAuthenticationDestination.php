<?php

namespace App\Support;

use App\Models\User;

final class PostAuthenticationDestination
{
    public static function for(User $user): string
    {
        return $user->onboarded_at === null
            ? route('onboarding', absolute: false)
            : route('dashboard', absolute: false);
    }
}
