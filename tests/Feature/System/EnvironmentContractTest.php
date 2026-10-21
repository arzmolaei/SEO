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
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('local', config('filesystems.default'));
    }
}
