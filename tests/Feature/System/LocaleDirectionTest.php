<?php

namespace Tests\Feature\System;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleDirectionTest extends TestCase
{
    public function test_persian_locale_renders_an_rtl_document(): void
    {
        app()->setLocale('fa');

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('lang="fa"', false);
        $response->assertSee('dir="rtl"', false);
    }

    public function test_english_locale_renders_an_ltr_document(): void
    {
        app()->setLocale('en');

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('lang="en"', false);
        $response->assertSee('dir="ltr"', false);
    }

    public function test_document_defaults_to_iransansx_font_preference(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('data-font="iransansx"', false);
    }

    public function test_inertia_shares_the_locale_contract(): void
    {
        app()->setLocale('fa');

        $response = $this->get(route('login'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'fa')
            ->where('direction', 'rtl')
        );
    }
}
