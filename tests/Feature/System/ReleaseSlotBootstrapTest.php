<?php

namespace Tests\Feature\System;

use App\Support\Update\ActiveReleasePointer;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class ReleaseSlotBootstrapTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_release_slot_keeps_versioned_base_path_but_uses_stable_environment_and_storage(): void
    {
        $stableRoot = sys_get_temp_dir().'/seo-os-stable-root-'.bin2hex(random_bytes(6));
        $slotRoot = sys_get_temp_dir().'/seo-os-release-slot-'.bin2hex(random_bytes(6));

        mkdir($stableRoot.'/storage', 0755, true);
        file_put_contents($stableRoot.'/VERSION', "0.1.0-alpha.1\n");
        mkdir($slotRoot.'/bootstrap', 0755, true);
        mkdir($slotRoot.'/routes', 0755, true);
        file_put_contents($slotRoot.'/routes/web.php', "<?php\n");
        file_put_contents($slotRoot.'/routes/console.php', "<?php\n");

        $sourceBootstrap = dirname(__DIR__, 3).'/bootstrap/app.php';
        copy($sourceBootstrap, $slotRoot.'/bootstrap/app.php');

        define('SEO_OS_INSTALLATION_ROOT', $stableRoot);

        $original = Application::getInstance();

        try {
            /** @var Application $app */
            $app = require $slotRoot.'/bootstrap/app.php';

            $this->assertSame($slotRoot, $app->basePath());
            $this->assertSame($stableRoot.'/storage', $app->storagePath());
            $this->assertSame($stableRoot, $app->environmentPath());
            $this->assertSame($stableRoot, (new ActiveReleasePointer)->current()['release_root']);
        } finally {
            Application::setInstance($original);
            @unlink($slotRoot.'/bootstrap/app.php');
            @unlink($slotRoot.'/routes/web.php');
            @unlink($slotRoot.'/routes/console.php');
            @rmdir($slotRoot.'/bootstrap');
            @rmdir($slotRoot.'/routes');
            @rmdir($slotRoot);
            @rmdir($stableRoot.'/storage');
            @unlink($stableRoot.'/VERSION');
            @rmdir($stableRoot);
        }
    }
}
