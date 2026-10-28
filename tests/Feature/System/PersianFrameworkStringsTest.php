<?php

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersianFrameworkStringsTest extends TestCase
{
    use RefreshDatabase;

    public function test_persian_users_see_persian_validation_messages_with_persian_attribute_names(): void
    {
        app()->setLocale('fa');

        $this->from(route('login'))->post(route('login.store'), [])
            ->assertSessionHasErrors([
                'email' => 'وارد کردن ایمیل الزامی است.',
                'password' => 'وارد کردن رمز عبور الزامی است.',
            ]);
    }

    public function test_persian_users_see_a_persian_failed_login_message(): void
    {
        app()->setLocale('fa');
        $user = User::factory()->create();

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email' => 'این اطلاعات ورود با هیچ حسابی مطابقت ندارد.']);
    }

    public function test_english_remains_available_from_the_framework(): void
    {
        app()->setLocale('en');

        $this->from(route('login'))->post(route('login.store'), [])
            ->assertSessionHasErrors(['email' => 'The email field is required.']);
    }

    public function test_every_framework_translation_key_has_a_persian_message(): void
    {
        $framework = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');
        $missing = [];

        foreach (['auth', 'pagination', 'passwords', 'validation'] as $group) {
            $english = require $framework."/{$group}.php";
            $persian = require lang_path("fa/{$group}.php");
            unset($english['custom'], $english['attributes']);

            foreach ($this->flattenKeys($english) as $key) {
                if (empty(data_get($persian, $key))) {
                    $missing[] = "{$group}.{$key}";
                }
            }
        }

        $this->assertSame([], $missing, 'Persian framework translations are missing.');
    }

    /**
     * @param  array<string, mixed>  $messages
     * @return list<string>
     */
    private function flattenKeys(array $messages, string $prefix = ''): array
    {
        $keys = [];
        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = is_array($value) ? [...$keys, ...$this->flattenKeys($value, $path)] : [...$keys, $path];
        }

        return $keys;
    }
}
