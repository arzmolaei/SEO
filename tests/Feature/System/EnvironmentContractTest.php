<?php

namespace Tests\Feature\System;

use Tests\TestCase;

class EnvironmentContractTest extends TestCase
{
    public function test_it_uses_seo_os_self_hosted_runtime_defaults(): void
    {
        $this->assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
        $this->assertSame('SEO OS', config('app.name'));
        $this->assertSame('fa', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));

        $environmentDefaults = file_get_contents(base_path('.env.example'));

        $this->assertIsString($environmentDefaults);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $environmentDefaults);
        $this->assertStringContainsString('FILESYSTEM_DISK=local', $environmentDefaults);
    }
}
