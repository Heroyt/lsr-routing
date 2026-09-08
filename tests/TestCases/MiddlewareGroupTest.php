<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use InvalidArgumentException;
use Lsr\Core\Routing\Exceptions\MiddlewareGroupNotFoundException;
use Lsr\Core\Routing\Exceptions\MiddlewareGroupsResolvedException;
use Lsr\Core\Routing\Interfaces\ServiceResolverInterface;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\ServiceReference;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;
use Lsr\Core\Routing\Tests\Mockup\NamedValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MiddlewareGroupTest extends TestCase
{
    protected function tearDown(): void {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
    }

    public function test_groups_resolve_after_all_route_files_and_preserve_order(): void {
        $router = new Router([ROOT . 'routes/middleware-groups.php']);
        $router->setup();

        $route = $router->getRouteByName('middleware-ordered');
        self::assertNotNull($route);
        self::assertSame(
            ['before', 'shared', 'second', 'after'],
            array_map(static fn (NamedMiddleware $middleware): string => $middleware->name, $route->getMiddleware()),
        );

        $grouped = $router->getRouteByName('middleware-grouped');
        self::assertNotNull($grouped);
        self::assertSame(
            ['shared', 'second'],
            array_map(static fn (NamedMiddleware $middleware): string => $middleware->name, $grouped->getMiddleware()),
        );
    }

    public function test_named_variadic_entries_do_not_overwrite_inherited_dependencies(): void {
        $router = new Router();
        $first = new NamedMiddleware('first');
        $second = new NamedMiddleware('second');
        $firstValidator = new NamedValidator('first');
        $secondValidator = new NamedValidator('second');
        $router->group('/named-variadic')
            ->middlewareAll(entry: $first)
            ->middlewareAll(entry: $second)
            ->paramAll('id', validator: $firstValidator)
            ->paramAll('id', validator: $secondValidator)
            ->get('/{id}', static fn () => null)
            ->name('named-variadic');

        $route = $router->getRouteByName('named-variadic');
        self::assertNotNull($route);
        self::assertSame([$first, $second], $route->getMiddleware());
        self::assertSame([$firstValidator, $secondValidator], $route->paramValidators['id']);
    }

    public function test_route_deduplicates_the_same_middleware_instance(): void {
        $router = new Router();
        $middleware = new NamedMiddleware('same');
        $route = $router->get('/deduplicated', static fn () => null)
            ->middleware($middleware, $middleware);

        self::assertSame([$middleware], $route->getMiddleware());
    }

    public function test_unknown_groups_are_reported_together(): void {
        $router = new Router([ROOT . 'routes/middleware-missing.php']);

        try {
            $router->setup();
            self::fail('Expected missing middleware groups to fail route setup.');
        } catch (MiddlewareGroupNotFoundException $exception) {
            self::assertSame(['missing-one', 'missing-two'], array_keys($exception->references));
            self::assertStringContainsString('GET /missing-one', $exception->getMessage());
            self::assertStringContainsString('GET /missing-two', $exception->getMessage());
        }
    }

    public function test_empty_group_name_is_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->middlewareGroup('   ');
    }

    public function test_group_references_and_definitions_are_rejected_after_resolution(): void {
        $router = new Router([ROOT . 'routes/middleware-groups.php']);
        $router->setup();

        $this->expectException(MiddlewareGroupsResolvedException::class);
        $router->get('/late', static fn () => null)->middleware('web');
    }

    public function test_classic_middleware_methods_accept_service_references(): void {
        $typed = new NamedMiddleware('typed');
        $audit = new NamedMiddleware('audit');
        $validator = new NamedValidator('accept');
        $resolver = new class ($typed, $audit, $validator) implements ServiceResolverInterface {
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

        $router = new Router(
            [ROOT . 'routes/middleware-services.php'],
            serviceResolver: $resolver,
        );
        $router->setup();

        $route = $router->getRouteByName('middleware-services');
        self::assertNotNull($route);
        self::assertSame([$typed, $audit], $route->getMiddleware());
        self::assertSame([$validator], $route->paramValidators['id']);
        self::assertInstanceOf(ServiceReference::class, $router->serviceRef(NamedMiddleware::class));
    }
}
