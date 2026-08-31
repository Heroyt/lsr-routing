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

final class MiddlewareGroupTest extends TestCase
{
    protected function tearDown(): void
    {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
    }

    public function testGroupsResolveAfterAllRouteFilesAndPreserveOrder(): void
    {
        $router = new Router([ROOT . 'routes/middleware-groups.php']);
        $router->setup();

        $route = $router->getRouteByName('middleware-ordered');
        self::assertNotNull($route);
        self::assertSame(
            ['before', 'shared', 'second', 'after'],
            array_map(static fn(NamedMiddleware $middleware): string => $middleware->name, $route->getMiddleware()),
        );

        $grouped = $router->getRouteByName('middleware-grouped');
        self::assertNotNull($grouped);
        self::assertSame(
            ['shared', 'second'],
            array_map(static fn(NamedMiddleware $middleware): string => $middleware->name, $grouped->getMiddleware()),
        );
    }

    public function testRouteDeduplicatesTheSameMiddlewareInstance(): void
    {
        $router = new Router();
        $middleware = new NamedMiddleware('same');
        $route = $router->get('/deduplicated', static fn() => null)
            ->middleware($middleware, $middleware);

        self::assertSame([$middleware], $route->getMiddleware());
    }

    public function testUnknownGroupsAreReportedTogether(): void
    {
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

    public function testEmptyGroupNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->middlewareGroup('   ');
    }

    public function testGroupReferencesAndDefinitionsAreRejectedAfterResolution(): void
    {
        $router = new Router([ROOT . 'routes/middleware-groups.php']);
        $router->setup();

        $this->expectException(MiddlewareGroupsResolvedException::class);
        $router->get('/late', static fn() => null)->middleware('web');
    }

    public function testClassicMiddlewareMethodsAcceptServiceReferences(): void
    {
        $typed = new NamedMiddleware('typed');
        $audit = new NamedMiddleware('audit');
        $validator = new NamedValidator('accept');
        $resolver = new class($typed, $audit, $validator) implements ServiceResolverInterface {
            public function __construct(
                private readonly NamedMiddleware $typed,
                private readonly NamedMiddleware $audit,
                private readonly NamedValidator $validator,
            ) {
            }

            public function getServiceId(ServiceReference $reference): string
            {
                return $reference->isTypeReference() ? 'middleware.typed' : $reference->service;
            }

            public function getService(string $serviceId): object
            {
                return match ($serviceId) {
                    'middleware.typed' => $this->typed,
                    'middleware.audit' => $this->audit,
                    'validator.accept' => $this->validator,
                    default => throw new \RuntimeException('Unknown test service ' . $serviceId),
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
