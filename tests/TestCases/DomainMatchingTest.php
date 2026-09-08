<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Exceptions\MethodNotAllowedException;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\Router;
use Lsr\Enums\RequestMethod;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DomainMatchingTest extends TestCase
{
    private Router $router;

    protected function setUp(): void {
        $this->router = new Router();
        $this->router->unregisterAll();
    }

    protected function tearDown(): void {
        $this->router->unregisterAll();
    }

    public function test_same_path_dispatches_exact_host_before_unrestricted_fallback(): void {
        $this->router->get('/shared', static fn () => new Response(200, [], 'fallback'));
        $this->router->domain('a.example.test')->get('/shared', static fn () => new Response(200, [], 'a'));
        $this->router->domain('b.example.test')->get('/shared', static fn () => new Response(200, [], 'b'));
        $this->router->resolveDomains();

        $this->assertBody('a', RequestMethod::GET, ['shared'], 'A.EXAMPLE.TEST.');
        $this->assertBody('b', RequestMethod::GET, ['shared'], 'b.example.test');
        $this->assertBody('fallback', RequestMethod::GET, ['shared'], 'unknown.example.test');
        $this->assertBody('fallback', RequestMethod::GET, ['shared']);
    }

    public function test_hostless_and_unknown_hosts_exclude_domain_only_paths_and_root(): void {
        $this->router->domain('private.example.test')
            ->get('/', static fn () => new Response())
            ->post('/private', static fn () => new Response());
        $this->router->resolveDomains();

        self::assertNull(Router::getRoute(RequestMethod::GET, []));
        self::assertNull(Router::getRoute(RequestMethod::POST, ['private']));
        self::assertNull(Router::getRoute(RequestMethod::GET, [], host: 'unknown.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['private'], host: 'unknown.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['missing'], host: 'private.example.test'));
    }

    public function test_method_can_fall_back_without_hiding_host_specific_method(): void {
        $this->router->domain('api.example.test')->post('/resource', static fn () => new Response(201, [], 'host post'));
        $this->router->get('/resource', static fn () => new Response(200, [], 'public get'));
        $this->router->resolveDomains();

        $this->assertBody('public get', RequestMethod::GET, ['resource'], 'api.example.test');
        $this->assertBody('host post', RequestMethod::POST, ['resource'], 'api.example.test');
        $this->expectException(MethodNotAllowedException::class);
        Router::getRoute(RequestMethod::DELETE, ['resource'], host: 'api.example.test');
    }

    public function test_other_hosts_method_cannot_satisfy_method_on_current_host(): void {
        $this->router->domain('a.example.test')->get('/resource', static fn () => new Response());
        $this->router->domain('b.example.test')->delete('/resource', static fn () => new Response());
        $this->router->resolveDomains();

        $this->expectException(MethodNotAllowedException::class);
        Router::getRoute(RequestMethod::DELETE, ['resource'], host: 'a.example.test');
    }

    public function test_host_synthetic_head_precedes_unrestricted_explicit_head(): void {
        $called = false;
        $this->router->domain('api.example.test')->get('/resource', static function () use (&$called): Response {
            $called = true;
            return new Response(200, [], 'GET body');
        })->name('host-get');
        $this->router->head('/resource', static fn () => new Response(202, [], 'unrestricted head'));
        $this->router->resolveDomains();

        $route = Router::getRoute(RequestMethod::HEAD, ['resource'], host: 'api.example.test');
        self::assertNotNull($route);
        self::assertSame('host-get', $route->getName());
        $response = $route->getHandler()();
        self::assertSame('', (string) $response->getBody());
        self::assertFalse($called);
        $this->assertBody('unrestricted head', RequestMethod::HEAD, ['resource'], 'other.example.test');
    }

    public function test_explicit_host_head_precedes_host_get_fallback(): void {
        $this->router->domain('api.example.test')
            ->get('/resource', static fn () => new Response(200, [], 'get'))
            ->head('/resource', static fn () => new Response(202, [], 'explicit head'));
        $this->router->resolveDomains();

        $this->assertBody('explicit head', RequestMethod::HEAD, ['resource'], 'api.example.test');
    }

    public function test_synthetic_options_combines_only_current_host_and_unrestricted_methods(): void {
        $this->router->domain('api.example.test')->get('/resource', static fn () => new Response());
        $this->router->post('/resource', static fn () => new Response());
        $this->router->domain('other.example.test')->delete('/resource', static fn () => new Response());
        $this->router->resolveDomains();

        $this->assertAllowed(['GET', 'HEAD', 'POST', 'OPTIONS'], ['resource'], 'api.example.test');
        $this->assertAllowed(['POST', 'OPTIONS'], ['resource'], 'unknown.example.test');
        $this->assertAllowed(['POST', 'OPTIONS'], ['resource']);
    }

    public function test_explicit_options_precedes_synthesis_in_either_applicable_tree(): void {
        $this->router->domain('api.example.test')
            ->get('/public-options', static fn () => new Response())
            ->get('/host-options', static fn () => new Response())
            ->options('/host-options', static fn () => new Response(200, [], 'host options'));
        $this->router->options('/public-options', static fn () => new Response(200, [], 'public options'));
        $this->router->options('/host-options', static fn () => new Response(200, [], 'shadowed options'));
        $this->router->resolveDomains();

        $this->assertBody('public options', RequestMethod::OPTIONS, ['public-options'], 'api.example.test');
        $this->assertBody('host options', RequestMethod::OPTIONS, ['host-options'], 'api.example.test');
    }

    public function test_options_asterisk_never_advertises_other_hosts_methods(): void {
        $this->router->domain('api.example.test')->patch('/host-only', static fn () => new Response());
        $this->router->get('/public-only', static fn () => new Response());
        $this->router->domain('other.example.test')->delete('/other-only', static fn () => new Response());
        $this->router->resolveDomains();

        $this->assertAllowed(['GET', 'HEAD', 'PATCH', 'OPTIONS'], ['*'], 'api.example.test');
        $this->assertAllowed(['GET', 'HEAD', 'OPTIONS'], ['*'], 'unknown.example.test');
        $this->assertAllowed(['GET', 'HEAD', 'OPTIONS'], ['*']);
    }

    public function test_explicit_host_options_asterisk_is_confined_to_its_host(): void {
        $this->router->get('/public-only', static fn () => new Response());
        $this->router->domain('api.example.test')->options('*', static fn () => new Response(200, [], 'host options star'));
        $this->router->resolveDomains();

        $this->assertBody('host options star', RequestMethod::OPTIONS, ['*'], 'api.example.test');
        $this->assertAllowed(['GET', 'HEAD', 'OPTIONS'], ['*'], 'unknown.example.test');
    }

    public function test_parameter_validators_and_names_remain_isolated_between_hosts(): void {
        $this->router->domain('numbers.example.test')->get('/items/{id}', static fn () => new Response())->name('numeric')->param(
            'id',
            new class implements RouteParamValidatorInterface {
                public function validate(mixed $value): bool {
                    return ctype_digit((string) $value);
                }
            },
        );
        $this->router->domain('words.example.test')->get('/items/{slug}', static fn () => new Response())->name('word')->param(
            'slug',
            new class implements RouteParamValidatorInterface {
                public function validate(mixed $value): bool {
                    return ctype_alpha((string) $value);
                }
            },
        );
        $this->router->resolveDomains();

        $params = [];
        self::assertSame('numeric', Router::getRoute(RequestMethod::GET, ['items', '42'], $params, host: 'numbers.example.test')?->getName());
        self::assertSame(['id' => '42'], $params);
        $params = [];
        self::assertSame('word', Router::getRoute(RequestMethod::GET, ['items', 'book'], $params, host: 'words.example.test')?->getName());
        self::assertSame(['slug' => 'book'], $params);
        self::assertNull(Router::getRoute(RequestMethod::GET, ['items', 'book'], host: 'numbers.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['items', '42'], host: 'words.example.test'));
    }

    public function test_failed_host_path_does_not_leak_parameters_into_unrestricted_match(): void {
        $this->router->domain('api.example.test')->get('/items/{hostId}/details', static fn () => new Response());
        $this->router->get('/items/{slug}/summary', static fn () => new Response())->name('fallback');
        $this->router->resolveDomains();

        $params = [];
        self::assertSame('fallback', Router::getRoute(RequestMethod::GET, ['items', 'book', 'summary'], $params, host: 'api.example.test')?->getName());
        self::assertSame(['slug' => 'book'], $params);
    }

    public function test_method_fallback_replaces_host_parameters_with_selected_route_parameters(): void {
        $this->router->domain('api.example.test')->post('/items/{hostId}', static fn () => new Response());
        $this->router->get('/items/{slug}', static fn () => new Response())->name('fallback');
        $this->router->resolveDomains();

        $params = [];
        self::assertSame('fallback', Router::getRoute(RequestMethod::GET, ['items', 'book'], $params, host: 'api.example.test')?->getName());
        self::assertSame(['slug' => 'book'], $params);
    }

    /** @param list<string> $path */
    private function assertBody(string $body, RequestMethod $method, array $path, ?string $host = null): void {
        $route = Router::getRoute($method, $path, host: $host);
        self::assertNotNull($route);
        self::assertSame($body, (string) $route->getHandler()()->getBody());
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $path
     */
    private function assertAllowed(array $allowed, array $path, ?string $host = null): void {
        $route = Router::getRoute(RequestMethod::OPTIONS, $path, host: $host);
        self::assertNotNull($route);
        $response = $route->getHandler()();
        self::assertEqualsCanonicalizing($allowed, explode(', ', $response->getHeaderLine('Allow')));
    }
}
