<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registration_redirects_to_onboarding(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'SEO Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/onboarding');
    }

    public function test_unonboarded_user_login_redirects_to_onboarding(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/onboarding');
    }

    public function test_onboarded_user_login_redirects_to_dashboard(): void
    {
        $user = User::factory()->create([
            'onboarded_at' => now(),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
