<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Enums\RequestMethod;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DomainRouteGroupTest extends TestCase
{
    private Router $router;

    protected function setUp(): void {
        $this->router = new Router();
        $this->router->unregisterAll();
    }

    protected function tearDown(): void {
        $this->router->unregisterAll();
    }

    public function test_domain_and_path_groups_compose_in_both_orders_with_inherited_dependencies(): void {
        $this->router->group('/path-first')
            ->middlewareAll('guard')
            ->paramAll('id', $this->numericValidator())
            ->domain('api')
            ->group('/nested')
            ->get('/{id}', static fn () => new Response(200, [], 'path first'))
            ->name('path-first');
        $this->router->domain('api')
            ->middlewareAll('guard')
            ->paramAll('id', $this->numericValidator())
            ->group('/domain-first')
            ->group('/nested')
            ->get('/{id}', static fn () => new Response(200, [], 'domain first'))
            ->name('domain-first');
        $this->router->middlewareGroup('guard', new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request)->withHeader('X-Guard', 'applied');
            }
        });
        $this->router->declareDomain('api.example.test', 'api');
        $this->router->loadRoutes();

        $params = [];
        $pathFirst = Router::getRoute(RequestMethod::GET, ['path-first', 'nested', '42'], $params, host: 'api.example.test');
        self::assertInstanceOf(Route::class, $pathFirst);
        self::assertSame(['id' => '42'], $params);
        $response = $this->dispatch($pathFirst, new ServerRequest('GET', 'https://api.example.test/path-first/nested/42'));
        self::assertSame('path first', (string) $response->getBody());
        self::assertSame('applied', $response->getHeaderLine('X-Guard'));

        $params = [];
        $domainFirst = Router::getRoute(RequestMethod::GET, ['domain-first', 'nested', '7'], $params, host: 'api.example.test');
        self::assertInstanceOf(Route::class, $domainFirst);
        self::assertSame(['id' => '7'], $params);
        $response = $this->dispatch($domainFirst, new ServerRequest('GET', 'https://api.example.test/domain-first/nested/7'));
        self::assertSame('domain first', (string) $response->getBody());
        self::assertSame('applied', $response->getHeaderLine('X-Guard'));

        self::assertNull(Router::getRoute(RequestMethod::GET, ['path-first', 'nested', 'invalid'], host: 'api.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['domain-first', 'nested', 'invalid'], host: 'api.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['path-first', 'nested', '42']));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['domain-first', 'nested', '7'], host: 'other.example.test'));
    }

    public function test_domain_child_does_not_retroactively_constrain_parent_or_siblings(): void {
        $parent = $this->router->group('/api');
        $parent->get('/before', static fn () => new Response())->name('before');
        $child = $parent->domain('a.example.test');
        $child->get('/only-a', static fn () => new Response())->name('only-a');
        $child->domain('b.example.test')->get('/only-b', static fn () => new Response())->name('only-b');
        $child->group('/nested')->get('/inherited', static fn () => new Response())->name('inherited');
        $child->get('/still-a', static fn () => new Response())->name('still-a');
        $child->endGroup()->get('/after', static fn () => new Response())->name('after');
        $this->router->resolveDomains();

        self::assertSame('before', Router::getRoute(RequestMethod::GET, ['api', 'before'])?->getName());
        self::assertSame('after', Router::getRoute(RequestMethod::GET, ['api', 'after'])?->getName());
        self::assertSame('only-a', Router::getRoute(RequestMethod::GET, ['api', 'only-a'], host: 'a.example.test')?->getName());
        self::assertSame('only-b', Router::getRoute(RequestMethod::GET, ['api', 'only-b'], host: 'b.example.test')?->getName());
        self::assertSame('inherited', Router::getRoute(RequestMethod::GET, ['api', 'nested', 'inherited'], host: 'a.example.test')?->getName());
        self::assertSame('still-a', Router::getRoute(RequestMethod::GET, ['api', 'still-a'], host: 'a.example.test')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['api', 'only-a'], host: 'b.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['api', 'only-b'], host: 'a.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['api', 'still-a']));
    }

    public function test_localized_routes_and_redirect_from_inherit_the_resolved_domain(): void {
        $this->router->domain('public')
            ->get('/vysledky/{id}', static fn () => new Response(200, [], 'public results'))
            ->name('results')
            ->localize('cs')
            ->localize('en', '/en/results/{id}')
            ->redirectFrom('/en/result/{id}', 'en');
        $this->router->domain('other.example.test')
            ->get('/en/results/{id}', static fn () => new Response(200, [], 'other results'))
            ->name('other-results');
        $this->router->declareDomain('public.example.test', 'public');
        $this->router->resolveDomains();

        $params = [];
        $canonical = Router::getRoute(RequestMethod::GET, ['en', 'results', '42'], $params, host: 'public.example.test');
        self::assertNotNull($canonical);
        self::assertSame('public results', (string) $canonical->getHandler()()->getBody());
        self::assertSame(['id' => '42', 'lang' => 'en'], $params);
        self::assertSame('other-results', Router::getRoute(RequestMethod::GET, ['en', 'results', '42'], host: 'other.example.test')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['en', 'results', '42']));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['en', 'result', '42'], host: 'other.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['en', 'result', '42']));

        $params = [];
        $alias = Router::getRoute(RequestMethod::GET, ['en', 'result', '42'], $params, host: 'public.example.test');
        self::assertNotNull($alias);
        $request = new ServerRequest('GET', 'https://public.example.test/en/result/42?tab=score');
        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $response = $alias->getHandler()($request);
        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/en/results/42?tab=score', $response->getHeaderLine('Location'));
    }

    public function test_explicit_alias_can_redirect_same_path_across_hosts_without_false_loop(): void {
        $this->router->domain('destination')->get('/items/{id}', static fn () => new Response(200, [], 'destination'))->name('destination');
        $target = $this->router->getRouteByName('destination');
        self::assertNotNull($target);
        $this->router->domain('source')->get('/items/{id}', $target);
        $this->router->declareDomain('new.example.test', 'destination');
        $this->router->declareDomain('old.example.test', 'source');
        $this->router->resolveDomains();

        $params = [];
        $alias = Router::getRoute(RequestMethod::GET, ['items', '42'], $params, host: 'old.example.test');
        self::assertNotNull($alias);
        $request = new ServerRequest('GET', 'https://old.example.test:8443/items/42?tab=score');
        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $response = $alias->getHandler()($request);
        self::assertSame(308, $response->getStatusCode());
        self::assertSame('https://new.example.test:8443/items/42?tab=score', $response->getHeaderLine('Location'));
        self::assertSame($target, Router::getRoute(RequestMethod::GET, ['items', '42'], host: 'new.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['items', '42'], host: 'unknown.example.test'));
    }

    private function numericValidator(): RouteParamValidatorInterface {
        return new class implements RouteParamValidatorInterface {
            public function validate(mixed $value): bool {
                return ctype_digit((string) $value);
            }
        };
    }

    private function dispatch(Route $route, ServerRequestInterface $request): ResponseInterface {
        $handler = new class ($route) implements RequestHandlerInterface {
            public function __construct(private readonly Route $route) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface {
                return $this->route->getHandler()($request);
            }
        };
        foreach (array_reverse($route->getMiddleware()) as $middleware) {
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        return $handler->handle($request);
    }
}
