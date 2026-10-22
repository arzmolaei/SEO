<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_public_registration_is_disabled_by_default_for_installed_self_host(): void
    {
        config(['installer.public_registration' => false]);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), $this->registrationPayload())->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_screen_can_be_rendered_when_explicitly_enabled(): void
    {
        config(['installer.public_registration' => true]);

        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register_when_explicitly_enabled(): void
    {
        config(['installer.public_registration' => true]);

        $response = $this->post(route('register.store'), $this->registrationPayload());

        $this->assertAuthenticated();
        $response->assertRedirect(route('onboarding', absolute: false));
    }

    /** @return array{name: string, email: string, password: string, password_confirmation: string} */
    private function registrationPayload(): array
    {
        return [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
    }
}
