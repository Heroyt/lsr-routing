<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Lsr\Core\Routing\Commands\RouteCacheCleanCommand;
use Lsr\Core\Routing\Commands\RouteCacheCompileCommand;
use Lsr\Core\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RouteCacheCommandTest extends TestCase
{
    private string $directory;
    private string $cacheFile;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/lsr-route-command-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
        $this->cacheFile = $this->directory . '/routes.php';
    }

    protected function tearDown(): void {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
        foreach ([$this->cacheFile, $this->cacheFile . '.tmp', $this->cacheFile . '.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function test_compile_and_clean_commands_manage_the_php_artifact(): void {
        $source = ROOT . 'routes/compiled-routes.php';
        $cache = new CompiledRouteCache(
            $this->cacheFile,
            autoCompile: false,
            routeSources: [$source],
        );
        $router = new Router([$source], compiledRouteCache: $cache);

        $compile = new CommandTester(new RouteCacheCompileCommand($router));
        self::assertSame(Command::SUCCESS, $compile->execute([]));
        self::assertFileExists($this->cacheFile);
        self::assertStringContainsString('compiled successfully', $compile->getDisplay());

        $clean = new CommandTester(new RouteCacheCleanCommand($cache));
        self::assertSame(Command::SUCCESS, $clean->execute([]));
        self::assertFileDoesNotExist($this->cacheFile);
        self::assertStringContainsString('cleared successfully', $clean->getDisplay());
    }
}
