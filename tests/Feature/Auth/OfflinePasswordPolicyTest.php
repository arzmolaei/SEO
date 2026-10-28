<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class OfflinePasswordPolicyTest extends TestCase
{
    private const STRONG = 'Strong-Passw0rd!x';

    public function test_production_password_policy_is_local_and_never_calls_an_external_service(): void
    {
        $this->app['env'] = 'production';
        Http::preventStrayRequests();
        Http::fake();

        $this->assertTrue(Validator::make(['password' => self::STRONG], ['password' => Password::defaults()])->passes());
        // Local structural strength is still enforced.
        $this->assertTrue(Validator::make(['password' => 'weakpassword'], ['password' => Password::defaults()])->fails());
        Http::assertNothingSent();
    }

    public function test_an_operator_may_opt_in_to_the_breached_password_lookup(): void
    {
        $this->app['env'] = 'production';
        config(['auth.password_breach_check' => true]);
        $suffix = strtoupper(substr(sha1(self::STRONG), 5));
        Http::fake(['api.pwnedpasswords.com/*' => Http::response($suffix.':42', 200)]);

        $this->assertTrue(Validator::make(['password' => self::STRONG], ['password' => Password::defaults()])->fails());
        Http::assertSentCount(1);
    }
}
