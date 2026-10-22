<?php

namespace Tests\Feature\System;

use Tests\TestCase;

class EnvironmentContractTest extends TestCase
{
    public function test_it_uses_seo_os_self_hosted_runtime_defaults(): void
    {
        $this->assertGreaterThanOrEqual(80401, PHP_VERSION_ID);
        $this->assertSame('SEO OS', config('app.name'));
        $this->assertSame('fa', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));

        $environmentDefaults = file_get_contents(base_path('.env.example'));

        $this->assertIsString($environmentDefaults);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $environmentDefaults);
        $this->assertStringContainsString('FILESYSTEM_DISK=local', $environmentDefaults);
        $this->assertStringContainsString('INSTALLER_ASSUME_INSTALLED=false', $environmentDefaults);
    }

    public function test_frontend_contract_is_tailwind_only_without_bootstrap(): void
    {
        $packageJson = file_get_contents(base_path('package.json'));
        $this->assertIsString($packageJson);

        $package = json_decode($packageJson, true, 512, JSON_THROW_ON_ERROR);
        $dependencies = [
            ...($package['dependencies'] ?? []),
            ...($package['devDependencies'] ?? []),
        ];

        $this->assertArrayHasKey('tailwindcss', $dependencies);
        $this->assertArrayHasKey('@tailwindcss/vite', $dependencies);
        $this->assertArrayNotHasKey('bootstrap', $dependencies);
        $this->assertArrayNotHasKey('react-bootstrap', $dependencies);
    }

    public function test_php_constraint_allows_supported_php_8_upgrades_without_crossing_major_versions(): void
    {
        $composerJson = file_get_contents(base_path('composer.json'));
        $this->assertIsString($composerJson);

        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('^8.4.1', $composer['require']['php'] ?? null);
    }
}
