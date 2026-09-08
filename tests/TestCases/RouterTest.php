<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\AliasRoute;
use Lsr\Core\Routing\Exceptions\DuplicateLocalizedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Exceptions\InvalidLocalizedRouteException;
use Lsr\Core\Routing\HeadRoute;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\LocalizedRoute;
use Lsr\Core\Routing\OptionsRoute;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\RouteParameter;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RouterTest extends TestCase
{
    private static Router $router;

    public static function setUpBeforeClass(): void {
        self::getRouter()->setup();
    }

    protected function tearDown(): void {
        self::getRouter()->unregisterAll();
        self::getRouter()->loadRoutes();
    }

    public static function getRouter(): Router {
        if ( ! isset(self::$router)) {
            self::$router = new Router([
                ROOT . 'routes/test.php',
            ]);
        }
        return self::$router;
    }

    public static function getRoutes(): array {
        return [
            [
                self::getRouter()->get('/settings/modes/{id}/variations', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['settings', 'modes', '1', 'variations'],
                ['id' => '1'],
                true,
            ],
            [
                self::getRouter()->get('[lang=cs]/optional', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['cs', 'optional'],
                ['lang' => 'cs'],
                true,
            ],
            [
                self::getRouter()->get('[lang=cs]/optional', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['en', 'optional'],
                ['lang' => 'en'],
                true,
            ],
            [
                self::getRouter()->get('[lang=cs]/optional', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['optional'],
                ['lang' => 'cs'],
                true,
            ],
            [
                self::getRouter()->get('[lang=cs]/optional2', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['optional2'],
                ['lang' => 'cs'],
                true,
            ],
            [
                self::getRouter()->get('optional-no-default/[param]/hi', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['optional-no-default', '123', 'hi'],
                ['param' => '123'],
                true,
            ],
            [
                self::getRouter()->get('optional-no-default/[param]/hi', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['optional-no-default', 'hi'],
                [],
                true,
            ],
            [
                self::getRouter()->get('optional-no-default/[param]/hello', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['optional-no-default', 'hello'],
                [],
                true,
            ],
            [
                self::getRouter()->get('optional-no-default/[param]/hello', [DummyController::class, 'action']),
                RequestMethod::GET,
                ['optional-no-default', '123', 'hello'],
                ['param' => '123'],
                true,
            ],
        ];
    }

    /**
     * @return array{0:array<int|string, string>,1:array<int|string, string>,2:bool}[]
     */
    public static function getPathsToCompare(): array {
        return [
            [
                ['test', '1'],
                ['test', '1'],
                true,
            ],
            [
                ['test', '1'],
                ['test', '2'],
                false,
            ],
            [
                ['test', '1', 'hello' => 'world'],
                ['test', '1'],
                true,
            ],
            [
                ['test', '1'],
                ['test', '1', 'hello' => 'world'],
                true,
            ],
            [
                ['test', '1'],
                ['test'],
                false,
            ],
        ];
    }

    public function test_load_routes(): void {
        $this::getRouter()->unregisterAll();
        self::assertFalse(isset(Router::$availableRoutes['loaded']['GET']));
        self::assertFalse(isset(Router::$availableRoutes['loaded']['POST']));
        self::assertFalse(isset(Router::$availableRoutes['loaded']['DELETE']));
        self::assertFalse(isset(Router::$availableRoutes['loaded']['{id}']['GET']));
        self::assertFalse(isset(Router::$availableRoutes['loaded']['{id}']['POST']));
        self::assertFalse(isset(Router::$availableRoutes['registered']['action']['GET']));
        self::assertFalse(isset(Router::$availableRoutes['registered']['action']['POST']));
        self::assertFalse(isset(Router::$availableRoutes['registered']['action']['DELETE']));
        self::assertFalse(isset(Router::$availableRoutes['registered']['action']['PUT']));
        self::assertFalse(isset(Router::$availableRoutes['registered']['action2']['PUT']));
        self::assertFalse(isset(Router::$availableRoutes['cli']['action']['CLI']));
        self::assertFalse(isset(Router::$namedRoutes['get-loaded']));
        self::assertFalse(isset(Router::$namedRoutes['delete-loaded']));
        self::assertFalse(isset(Router::$namedRoutes['registered-post']));

        [$availableRoutes, $namedRoutes] = $this::getRouter()->loadRoutes();

        self::assertNotEmpty($availableRoutes['loaded']['GET']);
        self::assertNotEmpty($availableRoutes['loaded']['POST']);
        self::assertNotEmpty($availableRoutes['loaded']['DELETE']);
        self::assertNotEmpty($availableRoutes['loaded']['{id}']['GET']);
        self::assertNotEmpty($availableRoutes['nahrano']['{id}']['GET']);
        self::assertNotEmpty($availableRoutes['loaded']['{id}']['POST']);
        self::assertNotEmpty($availableRoutes['settings']['POST']);

        // Localized routes
        self::assertNotEmpty($availableRoutes['settings']['GET']);
        self::assertNotEmpty($availableRoutes['settings']['GET'][0]->localizedRoutes['cs']);
        self::assertNotEmpty($availableRoutes['nastaveni']['GET']);
        self::assertInstanceOf(LocalizedRoute::class, $availableRoutes['nastaveni']['GET'][0]);

        self::assertNotEmpty($availableRoutes['settings']['gate']['GET']);
        self::assertNotEmpty($availableRoutes['settings']['modes']['GET']);
        self::assertNotEmpty($availableRoutes['settings']['modes']['{system}']['GET']);
        self::assertNotEmpty($namedRoutes['get-loaded']);
        self::assertNotEmpty($namedRoutes['delete-loaded']);
    }

    #[Depends('testLoadRoutes')]
    public function test_load_validated_routes(): void {
        self::assertNotEmpty(Router::$availableRoutes['validated']);
        self::assertNotEmpty(Router::$availableRoutes['validated']['[lang=cs]']);
        self::assertInstanceOf(RouteParameter::class, Router::$availableRoutes['validated']['[lang=cs]']);

        // Check that the validator is registered
        self::assertCount(1, Router::$availableRoutes['validated']['[lang=cs]']->validators);


        self::assertNotEmpty(Router::$availableRoutes['validated2']);
        self::assertNotEmpty(Router::$availableRoutes['validated2']['{id}']);
        self::assertInstanceOf(RouteParameter::class, Router::$availableRoutes['validated2']['{id}']);
        // Check that the validator is registered
        self::assertCount(1, Router::$availableRoutes['validated2']['{id}']->validators);

        self::assertNotEmpty(Router::$availableRoutes['validated2']['{slug}']);
        self::assertInstanceOf(RouteParameter::class, Router::$availableRoutes['validated2']['{slug}']);
        // Check that no validator is registered
        self::assertCount(0, Router::$availableRoutes['validated2']['{slug}']->validators);
    }

    /**
     * @param RouteInterface $route
     * @param RequestMethod $method
     * @param string[] $path
     * @param array<string,mixed> $expectedParams
     * @param bool $expected
     *
     * @return void
     */
    #[Depends('testLoadRoutes')] #[DataProvider('getRoutes')]
    public function test_get_route(RouteInterface $route, RequestMethod $method, array $path, array $expectedParams, bool $expected): void {
        $params = [];
        $routeGot = Router::getRoute($method, $path, $params);
        self::assertSame(
            $expected,
            $routeGot !== null && $route->compare($routeGot),
            $method->value . ' ' . implode('/', $path) . ': ' .
            json_encode(
                ['found' => $routeGot, 'compare' => $routeGot !== null ? $route->compare($routeGot) : null],
                JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        self::assertEquals($expectedParams, $params);
    }

    public function test_head_route_falls_back_to_get(): void {
        $router = new Router();
        $router->unregisterAll();
        $getRoute = $router->get('/head-fallback', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::HEAD, ['head-fallback'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertInstanceOf(HeadRoute::class, $routeGot);
        self::assertSame(RequestMethod::HEAD, $routeGot->getMethod());
        self::assertSame($getRoute->getPath(), $routeGot->getPath());
        self::assertSame($getRoute->getReadable(), $routeGot->getReadable());
        self::assertSame([], $params);

        $router->unregisterAll();
    }

    public function test_head_route_fallback_does_not_call_get_handler(): void {
        $router = new Router();
        $router->unregisterAll();

        $called = false;
        $router->get('/head-no-handler', static function () use (&$called): void {
            $called = true;
        });

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::HEAD, ['head-no-handler'], $params);

        self::assertInstanceOf(HeadRoute::class, $routeGot);
        $handler = $routeGot->getHandler();
        $handler();

        self::assertFalse($called);

        $router->unregisterAll();
    }

    public function test_head_route_fallback_copies_get_middleware(): void {
        $router = new Router();
        $router->unregisterAll();

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $router->get('/head-middleware', [DummyController::class, 'action'])
            ->middleware($middleware);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::HEAD, ['head-middleware'], $params);

        self::assertInstanceOf(Route::class, $routeGot);
        self::assertSame([$middleware], $routeGot->getMiddleware());

        $router->unregisterAll();
    }

    public function test_explicit_head_route_has_priority_over_get(): void {
        $router = new Router();
        $router->unregisterAll();
        $router->get('/head-priority', [DummyController::class, 'action']);
        $headRoute = $router->head('/head-priority', [DummyController::class, 'actionWithParams2']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::HEAD, ['head-priority'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($headRoute->compare($routeGot));

        $router->unregisterAll();
    }

    public function test_patch_route(): void {
        $router = new Router();
        $router->unregisterAll();
        $patchRoute = $router->patch('/patch-route', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::PATCH, ['patch-route'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($patchRoute->compare($routeGot));

        $router->unregisterAll();
    }

    public function test_options_route(): void {
        $router = new Router();
        $router->unregisterAll();
        $optionsRoute = $router->options('/options-route', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::OPTIONS, ['options-route'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($optionsRoute->compare($routeGot));

        $router->unregisterAll();
    }

    public function test_options_route_falls_back_to_allowed_methods(): void {
        $router = new Router();
        $router->unregisterAll();
        $router->get('/options-fallback', [DummyController::class, 'action']);
        $router->post('/options-fallback', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::OPTIONS, ['options-fallback'], $params);

        self::assertInstanceOf(OptionsRoute::class, $routeGot);
        self::assertSame(RequestMethod::OPTIONS, $routeGot->getMethod());
        self::assertSame(['options-fallback'], $routeGot->getPath());
        self::assertSame('/options-fallback', $routeGot->getReadable());
        self::assertSame([], $params);

        $response = $routeGot->getHandler()();
        self::assertSame('', (string) $response->getBody());
        self::assertEqualsCanonicalizing(
            ['GET', 'POST', 'HEAD', 'OPTIONS'],
            explode(', ', $response->getHeaderLine('Allow')),
        );

        $router->unregisterAll();
    }

    public function test_options_route_fallback_does_not_call_handlers(): void {
        $router = new Router();
        $router->unregisterAll();

        $called = false;
        $router->get('/options-no-handler', static function () use (&$called): void {
            $called = true;
        });

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::OPTIONS, ['options-no-handler'], $params);

        self::assertInstanceOf(OptionsRoute::class, $routeGot);
        $routeGot->getHandler()();

        self::assertFalse($called);

        $router->unregisterAll();
    }

    public function test_explicit_options_route_has_priority_over_fallback(): void {
        $router = new Router();
        $router->unregisterAll();
        $router->get('/options-priority', [DummyController::class, 'action']);
        $optionsRoute = $router->options('/options-priority', [DummyController::class, 'actionWithParams2']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::OPTIONS, ['options-priority'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($optionsRoute->compare($routeGot));

        $router->unregisterAll();
    }

    public function test_options_asterisk_route_falls_back_to_global_allowed_methods(): void {
        $router = new Router();
        $router->unregisterAll();
        $router->get('/options-star-get', [DummyController::class, 'action']);
        $router->patch('/options-star-patch', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::OPTIONS, ['*'], $params);

        self::assertInstanceOf(OptionsRoute::class, $routeGot);
        self::assertSame(['*'], $routeGot->getPath());
        self::assertSame('*', $routeGot->getReadable());
        self::assertEqualsCanonicalizing(
            ['GET', 'PATCH', 'HEAD', 'OPTIONS'],
            explode(', ', $routeGot->getHandler()()->getHeaderLine('Allow')),
        );

        $router->unregisterAll();
    }

    public function test_explicit_options_asterisk_route_has_priority_over_fallback(): void {
        $router = new Router();
        $router->unregisterAll();
        $router->get('/options-star-priority', [DummyController::class, 'action']);
        $optionsRoute = $router->options('*', [DummyController::class, 'actionWithParams2']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::OPTIONS, ['*'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($optionsRoute->compare($routeGot));

        $router->unregisterAll();
    }

    public function test_connect_route(): void {
        $router = new Router();
        $router->unregisterAll();
        $connectRoute = $router->connect('/connect-route', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::CONNECT, ['connect-route'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($connectRoute->compare($routeGot));

        $router->unregisterAll();
    }

    public function test_trace_route(): void {
        $router = new Router();
        $router->unregisterAll();
        $traceRoute = $router->trace('/trace-route', [DummyController::class, 'action']);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::TRACE, ['trace-route'], $params);

        self::assertInstanceOf(RouteInterface::class, $routeGot);
        self::assertTrue($traceRoute->compare($routeGot));

        $router->unregisterAll();
    }

    /**
     *
     * @param array<int|string, string> $path1
     * @param array<int|string, string> $path2
     * @param bool                      $expected
     *
     * @return void
     */
    #[DataProvider('getPathsToCompare')]
    public function test_compare_paths(array $path1, array $path2, bool $expected): void {
        self::assertSame($expected, Router::comparePaths($path1, $path2));
    }

    public function test_localized_route_dispatches_canonical_route_and_sets_locale(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router
            ->get('/ochrana-osobnich-udaju', [DummyController::class, 'action'])
            ->name('public.privacy')
            ->localize('cs')
            ->localize('en', '/en/privacy');

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::GET, ['ochrana-osobnich-udaju'], $params);
        self::assertSame($route, $routeGot);
        self::assertSame(['lang' => 'cs'], $params);

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::GET, ['en', 'privacy'], $params);
        self::assertSame($route, $routeGot);
        self::assertSame(['lang' => 'en'], $params);
        self::assertSame('public.privacy', $routeGot?->getName());

        $router->unregisterAll();
    }

    public function test_localized_head_fallback_propagates_locale(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router
            ->get('/vysledky/{gameId}', [DummyController::class, 'action'])
            ->localize('cs')
            ->localize('en', '/en/results/{gameId}');

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::HEAD, ['en', 'results', '42'], $params);

        self::assertInstanceOf(HeadRoute::class, $routeGot);
        self::assertSame(['gameId' => '42', 'lang' => 'en'], $params);
        self::assertSame($route->getHandler(), $routeGot->fallbackFor->getHandler());

        $router->unregisterAll();
    }

    public function test_localized_legacy_alias_redirects_once_to_canonical_path(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router
            ->get('/vysledky/{gameId}', [DummyController::class, 'action'])
            ->localize('cs')
            ->localize('en', '/en/results/{gameId}')
            ->redirectFrom('/en/result/{gameId}', 'en');

        $params = [];
        $routeGot = Router::getRoute(RequestMethod::GET, ['en', 'result', '42'], $params);
        self::assertInstanceOf(AliasRoute::class, $routeGot);
        $request = new ServerRequest('GET', '/en/result/42?tab=score');
        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $handler = $routeGot->getHandler();
        $response = $handler($request);

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/en/results/42?tab=score', $response->getHeaderLine('Location'));

        $params = [];
        $canonical = Router::getRoute(RequestMethod::GET, ['en', 'results', '42'], $params);
        self::assertSame($route, $canonical);
        self::assertSame(['gameId' => '42', 'lang' => 'en'], $params);

        $router->unregisterAll();
    }

    public function test_localized_path_collision_fails_during_registration(): void {
        $router = new Router();
        $router->unregisterAll();
        $router
            ->get('/prvni', [DummyController::class, 'action'])
            ->localize('cs')
            ->localize('en', '/en/shared');

        try {
            $router
                ->get('/druha', [DummyController::class, 'action'])
                ->localize('cs')
                ->localize('en', '/en/shared');
            self::fail('A localized path collision must fail during registration.');
        } catch (DuplicateRouteException) {
            self::addToAssertionCount(1);
        } finally {
            $router->unregisterAll();
        }
    }

    public function test_duplicate_locale_fails_during_registration(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router
            ->get('/vysledky/{gameId}', [DummyController::class, 'action'])
            ->localize('cs')
            ->localize('en', '/en/results/{gameId}');

        try {
            $route->localize('en', '/en/published-results/{gameId}');
            self::fail('A duplicate locale must fail during registration.');
        } catch (DuplicateLocalizedRouteException) {
            self::addToAssertionCount(1);
        } finally {
            $router->unregisterAll();
        }
    }

    public function test_localized_parameter_mismatch_fails_during_registration(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router->get('/vysledky/{gameId}', [DummyController::class, 'action']);

        try {
            $route->localize('en', '/en/results/{id}');
            self::fail('Localized variants must preserve the logical route parameter contract.');
        } catch (InvalidLocalizedRouteException) {
            self::addToAssertionCount(1);
        } finally {
            $router->unregisterAll();
        }
    }

    public function test_canonical_and_localized_paths_cannot_collide(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router
            ->get('/privacy', [DummyController::class, 'action'])
            ->localize('cs');

        try {
            $route->localize('en', '/privacy');
            self::fail('A localized path must not silently reuse its canonical route leaf.');
        } catch (DuplicateRouteException) {
            self::addToAssertionCount(1);
        } finally {
            $router->unregisterAll();
        }
    }

    public function test_localized_route_rejects_lang_path_parameter(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router->get('/privacy/{lang}', [DummyController::class, 'action']);

        try {
            $route->localize('cs');
            self::fail('Locale metadata must not overwrite a route parameter named lang.');
        } catch (InvalidLocalizedRouteException) {
            self::addToAssertionCount(1);
        } finally {
            $router->unregisterAll();
        }
    }

    public function test_legacy_alias_parameter_mismatch_fails_during_registration(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router->get('/results/{gameId}', [DummyController::class, 'action']);

        try {
            $route->redirectFrom('/result/{id}');
            self::fail('A legacy alias must provide the canonical route parameter contract.');
        } catch (InvalidLocalizedRouteException) {
            self::addToAssertionCount(1);
        } finally {
            $router->unregisterAll();
        }
    }

    public function test_localized_route_preserves_parameter_validators(): void {
        $router = new Router();
        $router->unregisterAll();
        $route = $router
            ->get('/vysledky/{gameId}', [DummyController::class, 'action'])
            ->localize('cs')
            ->localize('en', '/en/results/{gameId}')
            ->param(
                'gameId',
                new class implements RouteParamValidatorInterface {
                    public function validate(mixed $value): bool {
                        return ctype_digit((string) $value);
                    }
                },
            );

        $params = [];
        self::assertNull(Router::getRoute(RequestMethod::GET, ['en', 'results', 'invalid'], $params));

        $params = [];
        self::assertSame($route, Router::getRoute(RequestMethod::GET, ['en', 'results', '42'], $params));
        self::assertSame(['gameId' => '42', 'lang' => 'en'], $params);

        $router->unregisterAll();
    }

    public function test_route_cache_round_trip_preserves_localized_metadata(): void {
        $router = new Router();
        $router->unregisterAll();
        $router
            ->get('/ochrana-osobnich-udaju', [DummyController::class, 'action'])
            ->name('public.privacy')
            ->localize('cs')
            ->localize('en', '/en/privacy');

        $serialized = serialize([Router::$availableRoutes, Router::$namedRoutes]);
        $router->unregisterAll();
        /** @var array{0:array<string,mixed>,1:array<string,RouteInterface>} $cached */
        $cached = unserialize($serialized, ['allowed_classes' => true]);
        [Router::$availableRoutes, Router::$namedRoutes] = $cached;

        $params = [];
        $route = Router::getRoute(RequestMethod::GET, ['en', 'privacy'], $params);
        self::assertSame('public.privacy', $route?->getName());
        self::assertSame(['lang' => 'en'], $params);
        self::assertSame('/en/privacy', $route?->getRouteForLocale('en')?->getReadable());

        $router->unregisterAll();
    }

    #[Depends('testLoadValidatedRoutes')]
    public function test_validated_optional_routes(): void {
        $method = RequestMethod::GET;

        $params = [];
        $route = Router::getRoute($method, ['validated'], $params);
        self::assertNotNull($route);
        self::assertEquals(['lang' => 'cs'], $params); // Default lang is 'cs'

        // Test valid values
        foreach (['cs', 'en', 'de'] as $lang) {
            $params = [];
            $route = Router::getRoute($method, ['validated', $lang], $params);
            self::assertNotNull($route);
            self::assertEquals(['lang' => $lang], $params);

            $params = [];
            $route = Router::getRoute($method, ['validated', $lang, 'optional'], $params);
            self::assertNotNull($route);
            self::assertEquals(['lang' => $lang], $params);

            $params = [];
            $route = Router::getRoute($method, ['validated', $lang, 'optional2'], $params);
            self::assertNotNull($route);
            self::assertEquals(['lang' => $lang], $params);
        }

        // Test invalid values
        foreach (['1', 'abcd', 'hello', 'sk'] as $lang) {
            $params = [];
            $route = Router::getRoute($method, ['validated', $lang], $params);
            self::assertNull(
                $route,
                'Invalid lang should not match: ' . $lang . ', with parameters: ' . json_encode($params),
            );
            self::assertEquals(['lang' => 'cs'], $params);

            $params = [];
            $route = Router::getRoute($method, ['validated', $lang, 'optional'], $params);
            self::assertNull(
                $route,
                'Invalid lang should not match: ' . $lang . ', with parameters: ' . json_encode($params),
            );
            self::assertEquals(['lang' => 'cs'], $params);

            $params = [];
            $route = Router::getRoute($method, ['validated', $lang, 'optional2'], $params);
            self::assertNull(
                $route,
                'Invalid lang should not match: ' . $lang . ', with parameters: ' . json_encode($params),
            );
            self::assertEquals(['lang' => 'cs'], $params);
        }
    }

    #[Depends('testLoadValidatedRoutes')]
    public function test_validated_required_routes(): void {
        $method = RequestMethod::GET;

        // Test numeric IDs
        foreach (['1', '2', '99', 8] as $param) {
            $params = [];
            $route = Router::getRoute($method, ['validated2', $param], $params);
            self::assertNotNull($route);
            self::assertEquals(['id' => $param], $params);
        }

        // Test non-numeric IDs
        foreach (['abc', '1a', '2b', '99c'] as $param) {
            $params = [];
            $route = Router::getRoute($method, ['validated2', $param], $params);
            self::assertNotNull($route);
            self::assertEquals(['slug' => $param], $params);
        }
    }

}
