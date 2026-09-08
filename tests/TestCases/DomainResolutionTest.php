<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use InvalidArgumentException;
use LogicException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Interfaces\DomainRouteInterface;
use Lsr\Core\Routing\Router;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DomainResolutionTest extends TestCase
{
    private Router $router;

    protected function setUp(): void {
        $this->router = new Router();
        $this->router->unregisterAll();
    }

    protected function tearDown(): void {
        $this->router->unregisterAll();
    }

    public function test_aliases_declared_before_and_after_routes_resolve_together(): void {
        $this->router->declareDomain('API.EXAMPLE.TEST.', 'api');
        $this->router->domain('api')->get('/before', static fn () => new Response())->name('before');
        $this->router->domain('web')->get('/after', static fn () => new Response())->name('after');
        $this->router->declareDomain('www.example.test', 'web');
        $this->router->resolveDomains();

        self::assertSame('before', Router::getRoute(RequestMethod::GET, ['before'], host: 'api.example.test')?->getName());
        self::assertSame('after', Router::getRoute(RequestMethod::GET, ['after'], host: 'www.example.test')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['after'], host: 'web'));
    }

    public function test_unknown_references_fall_back_to_single_label_and_literal_hosts(): void {
        $this->router->domain('intranet')->get('/health', static fn () => new Response())->name('internal');
        $this->router->domain('EXAMPLE.TEST.')->get('/health', static fn () => new Response())->name('literal');
        $this->router->resolveDomains();

        self::assertSame('internal', Router::getRoute(RequestMethod::GET, ['health'], host: 'intranet')?->getName());
        self::assertSame('literal', Router::getRoute(RequestMethod::GET, ['health'], host: 'example.test')?->getName());
    }

    public function test_hostname_shaped_alias_is_looked_up_before_literal_fallback(): void {
        $this->router->domain('old.example.test')->get('/health', static fn () => new Response())->name('moved');
        $this->router->declareDomain('new.example.test', 'old.example.test');
        $this->router->resolveDomains();

        self::assertSame('moved', Router::getRoute(RequestMethod::GET, ['health'], host: 'new.example.test')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['health'], host: 'old.example.test'));
    }

    public function test_alias_targets_are_concrete_hosts_not_recursive_aliases(): void {
        $this->router->declareDomain('second', 'first');
        $this->router->declareDomain('final.example.test', 'second');
        $this->router->domain('first')->get('/health', static fn () => new Response())->name('direct');
        $this->router->resolveDomains();

        self::assertSame('direct', Router::getRoute(RequestMethod::GET, ['health'], host: 'second')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['health'], host: 'final.example.test'));
    }

    public function test_normalized_alias_and_literal_hosts_merge_without_losing_routes(): void {
        $this->router->domain('public')->get('/aliased', static fn () => new Response())->name('aliased');
        $this->router->domain('EXAMPLE.TEST.')->get('/literal', static fn () => new Response())->name('literal');
        $this->router->declareDomain('Example.Test.', 'public');
        $this->router->declareDomain('example.test', 'public');
        $this->router->resolveDomains();

        self::assertSame('aliased', Router::getRoute(RequestMethod::GET, ['aliased'], host: 'EXAMPLE.TEST.')?->getName());
        self::assertSame('literal', Router::getRoute(RequestMethod::GET, ['literal'], host: 'example.test')?->getName());
    }

    public function test_conflicting_alias_declaration_is_rejected(): void {
        $this->router->declareDomain('first.example.test', 'public');

        $this->expectException(InvalidArgumentException::class);
        $this->router->declareDomain('second.example.test', 'public');
    }

    public function test_duplicate_routes_are_detected_when_distinct_aliases_resolve_to_one_host(): void {
        $this->router->domain('first')->get('/same', static fn () => new Response(200, [], 'first'));
        $this->router->domain('second')->get('/same', static fn () => new Response(200, [], 'second'));
        $this->router->declareDomain('EXAMPLE.TEST.', 'first');
        $this->router->declareDomain('example.test', 'second');

        $this->expectException(DuplicateRouteException::class);
        $this->router->resolveDomains();
    }

    public function test_host_aware_matching_cannot_expose_unresolved_routes(): void {
        $this->router->get('/public', static fn () => new Response())->name('unrestricted');
        $this->router->domain('pending')->get('/private', static fn () => new Response());
        self::assertSame('unrestricted', Router::getRoute(RequestMethod::GET, ['public'])?->getName());

        $this->expectException(LogicException::class);
        Router::getRoute(RequestMethod::GET, ['private'], host: 'pending');
    }

    public function test_constrained_route_domain_cannot_be_read_before_resolution(): void {
        $this->router->domain('pending')->get('/private', static fn () => new Response())->name('pending');
        $route = $this->router->getRouteByName('pending');
        self::assertInstanceOf(DomainRouteInterface::class, $route);

        $this->expectException(LogicException::class);
        $route->getDomain();
    }

    public function test_alias_declarations_are_frozen_after_resolution(): void {
        $this->router->declareDomain('example.test', 'public');
        $this->router->resolveDomains();

        $this->expectException(LogicException::class);
        $this->router->declareDomain('example.test', 'public');
    }

    public function test_unregister_clears_aliases_host_routes_and_resolution_freeze(): void {
        $this->router->declareDomain('old.example.test', 'public');
        $this->router->domain('public')->get('/old', static fn () => new Response())->name('old');
        $this->router->resolveDomains();
        $this->router->unregisterAll();
        $this->router->declareDomain('new.example.test', 'public');
        $this->router->domain('public')->get('/new', static fn () => new Response())->name('new');
        $this->router->resolveDomains();

        self::assertNull($this->router->getRouteByName('old'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['old'], host: 'old.example.test'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['new'], host: 'old.example.test'));
        self::assertSame('new', Router::getRoute(RequestMethod::GET, ['new'], host: 'new.example.test')?->getName());
    }

    public function test_setup_with_no_sources_drops_previous_domain_registration(): void {
        $this->router->declareDomain('old.example.test', 'public');
        $this->router->domain('public')->get('/old', static fn () => new Response())->name('old-route');
        $this->router->resolveDomains();
        $this->router->setup();

        self::assertNull($this->router->getRouteByName('old-route'));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['old'], host: 'old.example.test'));
        $this->router->domain('public')->get('/new', static fn () => new Response())->name('literal-after-reset');
        $this->router->resolveDomains();
        self::assertSame('literal-after-reset', Router::getRoute(RequestMethod::GET, ['new'], host: 'public')?->getName());
        self::assertNull(Router::getRoute(RequestMethod::GET, ['new'], host: 'old.example.test'));
    }

    public function test_repeated_terminal_dns_dots_are_rejected(): void {
        $this->router->domain('example.test..')->get('/', static fn () => new Response());
        $this->expectException(InvalidArgumentException::class);
        $this->router->resolveDomains();
    }

    public function test_resolved_alias_is_equivalent_to_a_custom_domain_route(): void {
        $this->router->domain('admin')->get('/page', 'strlen')->name('admin.page');
        $this->router->declareDomain('admin.example', 'admin');
        $this->router->resolveDomains();
        $original = $this->router->getRouteByName('admin.page');
        self::assertNotNull($original);
        $custom = $this->createStubForIntersectionOfInterfaces([RouteInterface::class, DomainRouteInterface::class]);
        $custom->method('getDomain')->willReturn('admin.example');
        $custom->method('getMethod')->willReturn(RequestMethod::GET);
        $custom->method('getPath')->willReturn($original->getPath());
        $custom->method('getReadable')->willReturn('/page');
        $custom->method('getHandler')->willReturn('strlen');

        $this->router->register($custom);
        self::assertSame($original, Router::getRoute(RequestMethod::GET, ['page'], host: 'admin.example'));
    }
}
