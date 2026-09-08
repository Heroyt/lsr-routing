<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\AliasRoute;
use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompiledDomainRouteCacheTest extends TestCase
{
    private string $directory;
    private string $cacheFile;

    protected function setUp(): void {
        (new Router())->unregisterAll();
        $this->directory = sys_get_temp_dir() . '/lsr-domain-cache-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
        $this->cacheFile = $this->directory . '/routes.php';
        unset($GLOBALS['domain-route-loads']);
    }

    protected function tearDown(): void {
        (new Router())->unregisterAll();
        unset($GLOBALS['domain-route-loads']);
        foreach ([$this->cacheFile, $this->cacheFile . '.lock', $this->cacheFile . '.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function test_cold_and_warm_cache_preserve_host_isolation_and_merged_aliases(): void {
        $source = ROOT . 'fixtures/domains/routes.php';
        $cache = new CompiledRouteCache($this->cacheFile, routeSources: [$source]);
        $cold = new Router([$source], compiledRouteCache: $cache);
        $cold->setup();
        $this->assertHostRouting($cold);

        $warm = new Router([$source], compiledRouteCache: $cache);
        $warm->setup();
        self::assertSame(1, $GLOBALS['domain-route-loads'], 'The warm router must not reload route declarations.');
        $this->assertHostRouting($warm);

        $warm->domain('merged')->get('/after-cache', [DummyController::class, 'action'])->name('after-cache');
        self::assertSame('after-cache', Router::getRoute(RequestMethod::GET, ['after-cache'], host: 'primary.example')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['after-cache'], host: 'merged'));
        $warm->domain('456')->get('/numeric-after-cache', [DummyController::class, 'action'])->name('numeric-after-cache');
        self::assertSame('numeric-after-cache', Router::getRoute(RequestMethod::GET, ['numeric-after-cache'], host: 'primary.example')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['numeric-after-cache'], host: '456'));
    }

    public function test_warm_cache_preserves_attribute_domain_precedence(): void {
        $sources = [ROOT . 'fixtures/domains/attribute-aliases.php'];
        $controllers = [ROOT . 'src/DomainController.php'];
        $cache = new CompiledRouteCache($this->cacheFile, routeSources: $sources, controllerSources: $controllers);
        $cold = new Router($sources, $controllers, $cache);
        $cold->setup();
        $warm = new Router(compiledRouteCache: $cache);
        $warm->setup();

        foreach (['class' => 'class.example', 'method' => 'method.example', 'repeated' => 'route.example'] as $path => $host) {
            $route = Router::getRoute(RequestMethod::GET, ['attribute', $path], host: $host);
            self::assertInstanceOf(Route::class, $route);
            self::assertSame($warm->getRouteByName('domain.attribute.' . $path), $route);
            self::assertSame($host, $route->getDomain());
            self::assertNull(Router::getRoute(RequestMethod::GET, ['attribute', $path]));
        }
    }

    #[DataProvider('invalidCaches')]
    public function test_invalid_or_stale_cache_cannot_replace_live_routes(string $corruption): void {
        $source = ROOT . 'fixtures/domains/routes.php';
        $cache = new CompiledRouteCache($this->cacheFile, checkTimestamps: true, routeSources: [$source]);
        $router = new Router([$source], compiledRouteCache: $cache);
        $router->setup();
        $data = require $this->cacheFile;
        switch ($corruption) {
            case 'old-format':
                --$data['version'];
                break;
            case 'stale-manifest':
                $data['manifest'] = [];
                break;
            case 'missing-domains':
                unset($data['domainTrees']);
                break;
            case 'invalid-host':
                $data['domainTrees']['https://primary.example'] = $data['domainTrees']['primary.example'];
                break;
            case 'cross-host-tree':
                $data['domainTrees']['secondary.example'] = $data['domainTrees']['primary.example'];
                break;
            case 'leaked-unrestricted-tree':
                $data['tree'] = $data['domainTrees']['primary.example'];
                break;
            case 'missing-route-domain':
                unset($data['routes'][0]['domain']);
                break;
            case 'invalid-alias-target':
                $data['domainAliases']['primary'] = 'primary.example:443';
                break;
            case 'missing-alias-map':
                unset($data['domainAliases']);
                break;
            case 'invalid-route-reference':
                $data['domainTrees']['primary.example'] = [
                    'type' => 'array',
                    'children' => ['missing' => ['type' => 'route', 'id' => -1]],
                ];
                break;
            case 'localized-domain-mismatch':
                foreach ($data['routes'] as &$definition) {
                    if ($definition['type'] === 'localized') {
                        $definition['domain'] = 'secondary.example';
                    }
                }
                unset($definition);
                break;
        }
        file_put_contents($this->cacheFile, '<?php return ' . var_export($data, true) . ';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }

        $router->unregisterAll();
        $live = $router->get('/live', [DummyController::class, 'action'])->name('live');
        $router->declareDomain('live.example', 'live-host');
        $router->domain('live-host')->get('/private', [DummyController::class, 'action'])->name('private');
        $router->resolveDomains();
        $constrained = Router::getRoute(RequestMethod::GET, ['private'], host: 'live.example');
        self::assertSame('private', $constrained?->getName());

        self::assertFalse($cache->load($router));
        self::assertSame($live, Router::getRoute(RequestMethod::GET, ['live']));
        self::assertSame($live, $router->getRouteByName('live'));
        self::assertSame($constrained, Router::getRoute(RequestMethod::GET, ['private'], host: 'live.example'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['shared'], host: 'primary.example'));
        $router->domain('live-host')->get('/additional', [DummyController::class, 'action'])->name('additional');
        self::assertSame('additional', Router::getRoute(RequestMethod::GET, ['additional'], host: 'live.example')?->getName());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidCaches(): iterable {
        foreach ([
            'old-format',
            'stale-manifest',
            'missing-domains',
            'invalid-host',
            'cross-host-tree',
            'leaked-unrestricted-tree',
            'missing-route-domain',
            'invalid-alias-target',
            'missing-alias-map',
            'invalid-route-reference',
            'localized-domain-mismatch',
        ] as $corruption) {
            yield $corruption => [$corruption];
        }
    }

    private function assertHostRouting(Router $router): void {
        foreach ([
            'primary.example' => 'domain.primary',
            'secondary.example' => 'domain.secondary',
            'unknown.example' => 'domain.fallback',
            '' => 'domain.fallback',
        ] as $host => $name) {
            self::assertSame(
                $router->getRouteByName($name),
                Router::getRoute(RequestMethod::GET, ['shared'], host: $host === '' ? null : $host),
            );
        }
        self::assertSame(
            $router->getRouteByName('domain.fallback.post'),
            Router::getRoute(RequestMethod::POST, ['shared'], host: 'primary.example'),
        );
        foreach (['merged', 'literal', 'numeric-alias'] as $path) {
            self::assertSame(
                $router->getRouteByName('domain.' . $path),
                Router::getRoute(RequestMethod::GET, [$path], host: 'primary.example'),
            );
            self::assertNull(Router::getRoute(RequestMethod::GET, [$path], host: 'secondary.example'));
        }
        self::assertSame(
            $router->getRouteByName('domain.numeric'),
            Router::getRoute(RequestMethod::GET, ['numeric'], host: '123'),
        );
        $params = [];
        $localized = Router::getRoute(RequestMethod::GET, ['article-en', '42'], $params, host: 'primary.example');
        self::assertSame($router->getRouteByName('domain.article'), $localized);
        self::assertSame(['id' => '42', 'lang' => 'en'], $params);
        self::assertNull(Router::getRoute(RequestMethod::GET, ['article-en', '42'], host: 'secondary.example'));
        $alias = Router::getRoute(RequestMethod::GET, ['old-article', '42'], host: 'primary.example');
        self::assertInstanceOf(AliasRoute::class, $alias);
        self::assertSame('primary.example', $alias->getDomain());
        self::assertSame($router->getRouteByName('domain.article'), $alias->redirectTo);
        self::assertNull(Router::getRoute(RequestMethod::GET, ['old-article', '42'], host: 'secondary.example'));
        $crossDomain = Router::getRoute(RequestMethod::GET, ['cross-domain', '42'], host: 'secondary.example');
        self::assertInstanceOf(AliasRoute::class, $crossDomain);
        self::assertSame('secondary.example', $crossDomain->getDomain());
        self::assertSame($router->getRouteByName('domain.article'), $crossDomain->redirectTo);
        self::assertNull(Router::getRoute(RequestMethod::GET, ['cross-domain', '42'], host: 'primary.example'));
        $primary = $router->getRouteByName('domain.primary');
        $secondary = $router->getRouteByName('domain.secondary');
        self::assertNotNull($primary);
        self::assertNotNull($secondary);
        self::assertSame($primary->getMiddleware()[0], $secondary->getMiddleware()[0]);
    }
}
