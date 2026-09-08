<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Lsr\Core\Routing\Exceptions\RouteCacheCompilationException;
use Lsr\Core\Routing\Interfaces\ServiceResolverInterface;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\ServiceReference;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;
use Lsr\Core\Routing\Tests\Mockup\NamedValidator;
use Lsr\Enums\RequestMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompiledRouteCacheTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
        unset($GLOBALS['compiled-route-loads']);
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function test_compiled_cache_hydrates_the_final_matcher_without_reloading_sources(): void {
        $source = ROOT . 'routes/compiled-routes.php';
        $cacheFile = $this->temporaryFile('routes.php');
        $cache = new CompiledRouteCache($cacheFile, routeSources: [$source]);

        $router = new Router([$source], compiledRouteCache: $cache);
        $router->setup();

        self::assertFileExists($cacheFile);
        self::assertSame(1, $GLOBALS['compiled-route-loads']);
        $firstMiddleware = $router->getRouteByName('compiled-route')?->getMiddleware()[0] ?? null;
        $secondMiddleware = $router->getRouteByName('compiled-second')?->getMiddleware()[0] ?? null;
        self::assertInstanceOf(NamedMiddleware::class, $firstMiddleware);
        self::assertSame($firstMiddleware, $secondMiddleware);

        $cachedRouter = new Router([$source], compiledRouteCache: $cache);
        $cachedRouter->setup();

        self::assertSame(1, $GLOBALS['compiled-route-loads'], 'A fresh router must use the PHP cache.');
        $cachedFirst = $cachedRouter->getRouteByName('compiled-route');
        $cachedSecond = $cachedRouter->getRouteByName('compiled-second');
        self::assertInstanceOf(Route::class, $cachedFirst);
        self::assertInstanceOf(Route::class, $cachedSecond);
        self::assertSame($cachedFirst->getMiddleware()[0], $cachedSecond->getMiddleware()[0]);
        self::assertInstanceOf(NamedValidator::class, $cachedFirst->paramValidators['id'][0]);
        self::assertIsCallable($cachedRouter->getRouteByName('compiled-closure')?->getHandler());

        $params = [];
        $localized = Router::getRoute(RequestMethod::GET, ['compiled-en', '42'], $params);
        self::assertSame($cachedFirst, $localized);
        self::assertSame(['id' => '42', 'lang' => 'en'], $params);

        $legacyParams = [];
        self::assertNotNull(Router::getRoute(RequestMethod::GET, ['compiled-legacy', '42'], $legacyParams));
    }

    public function test_compiled_cache_hydrates_service_references_through_di(): void {
        $typed = new NamedMiddleware('typed');
        $audit = new NamedMiddleware('audit');
        $validator = new NamedValidator('accept');
        $resolver = $this->createResolver($typed, $audit, $validator);
        $source = ROOT . 'routes/middleware-services.php';
        $cacheFile = $this->temporaryFile('services.php');
        $cache = new CompiledRouteCache($cacheFile, routeSources: [$source]);

        (new Router([$source], compiledRouteCache: $cache, serviceResolver: $resolver))->setup();
        $cachedRouter = new Router([$source], compiledRouteCache: $cache, serviceResolver: $resolver);
        $cachedRouter->setup();

        $route = $cachedRouter->getRouteByName('middleware-services');
        self::assertNotNull($route);
        self::assertSame([$typed, $audit], $route->getMiddleware());
        self::assertSame([$validator], $route->paramValidators['id']);
    }

    public function test_malformed_cached_middleware_does_not_replace_live_routes(): void {
        $source = ROOT . 'routes/compiled-routes.php';
        $cacheFile = $this->temporaryFile('malformed.php');
        $cache = new CompiledRouteCache($cacheFile, routeSources: [$source]);
        $router = new Router([$source], compiledRouteCache: $cache);
        $router->setup();
        $liveRoutes = $router->getAvailableRoutes();

        $data = require $cacheFile;
        $data['routes'][0]['middleware'] = 'not-an-array';
        file_put_contents($cacheFile, '<?php return ' . var_export($data, true) . ';');

        self::assertFalse($cache->load($router));
        self::assertSame($liveRoutes, $router->getAvailableRoutes());
    }

    public function test_timestamp_checking_detects_new_route_files(): void {
        $directory = $this->temporaryDirectory();
        $cacheFile = $directory . '/routes.php.cache';
        $this->temporaryPaths[] = $cacheFile . '.lock';
        $this->temporaryPaths[] = $cacheFile;
        $firstSource = $directory . '/first.php';
        $this->temporaryPaths[] = $firstSource;
        file_put_contents($firstSource, $this->routeSource('first-cache-route', '/first-cache-route'));

        $cache = new CompiledRouteCache(
            $cacheFile,
            checkTimestamps: true,
            routeSources: [$directory],
        );
        (new Router([$directory], compiledRouteCache: $cache))->setup();

        $secondSource = $directory . '/second.php';
        $this->temporaryPaths[] = $secondSource;
        file_put_contents($secondSource, $this->routeSource('second-cache-route', '/second-cache-route'));

        $router = new Router([$directory], compiledRouteCache: $cache);
        $router->setup();
        self::assertNotNull($router->getRouteByName('second-cache-route'));
    }

    public function test_automatic_compilation_falls_back_for_unserializable_middleware(): void {
        $source = ROOT . 'routes/unserializable-routes.php';
        $cacheFile = $this->temporaryFile('unserializable.php');
        $router = new Router(
            [$source],
            compiledRouteCache: new CompiledRouteCache($cacheFile, routeSources: [$source]),
        );

        $router->setup();
        self::assertNotNull($router->getRouteByName('unserializable'));
        self::assertFileDoesNotExist($cacheFile);

        $this->expectException(RouteCacheCompilationException::class);
        $this->expectExceptionMessage('UnserializableMiddleware');
        $router->compileCache();
    }

    private function temporaryFile(string $name): string {
        $directory = $this->temporaryDirectory();
        $file = $directory . '/' . $name;
        $this->temporaryPaths[] = $file . '.lock';
        $this->temporaryPaths[] = $file;
        return $file;
    }

    private function temporaryDirectory(): string {
        $directory = sys_get_temp_dir() . '/lsr-routing-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryPaths[] = $directory;
        return $directory;
    }

    private function routeSource(string $name, string $path): string {
        return sprintf(
            "<?php\n\n\$this->get('%s', [\\Lsr\\Core\\Routing\\Tests\\Mockup\\Controllers\\DummyController::class, 'action'])->name('%s');\n",
            $path,
            $name,
        );
    }

    private function createResolver(
        NamedMiddleware $typed,
        NamedMiddleware $audit,
        NamedValidator $validator,
    ): ServiceResolverInterface {
        return new class ($typed, $audit, $validator) implements ServiceResolverInterface {
            public function __construct(
                private readonly NamedMiddleware $typed,
                private readonly NamedMiddleware $audit,
                private readonly NamedValidator $validator,
            ) {
            }

            public function getServiceId(ServiceReference $reference): string {
                return $reference->isTypeReference() ? 'middleware.typed' : $reference->service;
            }

            public function getService(string $serviceId): object {
                return match ($serviceId) {
                    'middleware.typed' => $this->typed,
                    'middleware.audit' => $this->audit,
                    'validator.accept' => $this->validator,
                    default => throw new RuntimeException('Unknown test service ' . $serviceId),
                };
            }
        };
    }
}
