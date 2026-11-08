<?php

declare(strict_types=1);

namespace Suawave\Tests;

use PHPUnit\Framework\TestCase;

final class CallbackControllerTest extends TestCase
{
    public function testInitRunsTheParentInitialisationFirst(): void
    {
        $root = dirname(__DIR__);
        $script = <<<'PHP'
define('_PS_VERSION_', '8.2.0');
$GLOBALS['order'] = [];
class Configuration { public static function get($name) { return false; } }
class ModuleFrontController { public function init() { $GLOBALS['order'][] = 'parent-init'; } }
require $argv[1] . '/suawave/src/autoload.php';
require $argv[1] . '/suawave/controllers/front/callback.php';
register_shutdown_function(function () { fwrite(STDERR, implode(',', $GLOBALS['order'])); });
(new SuawaveCallbackModuleFrontController())->init();
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, '--', $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $order = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('parent-init', $order);
        $this->assertSame('not_configured', $output);
    }
}
