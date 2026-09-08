<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Closure;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\Interfaces\ServiceResolverInterface;
use Lsr\Core\Routing\RouteGroup;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\ServiceReference;
use Lsr\Core\Routing\Tests\Mockup\ValidatorOnlyRoute;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouteGroupServiceReferenceTest extends TestCase
{
    protected function tearDown(): void {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
    }

    public function test_custom_routes_receive_usable_validators_under_the_base_contract(): void {
        $router = new Router(serviceResolver: $this->createResolver(static fn (): bool => true));
        $route = ValidatorOnlyRoute::create(RequestMethod::GET, '/custom/{id}', static fn () => null);
        $group = new class ($router, $route) extends RouteGroup {
            public function __construct(Router $router, RouteInterface $route) {
                parent::__construct($router);
                $this->activeRoute = $route;
            }
        };

        $group->param('id', ServiceReference::named('validator.accept'));

        self::assertTrue($route->acceptsParameter('id', 'allowed'));
        self::assertFalse($route->acceptsParameter('id', 'denied'));
    }

    public function test_concrete_routes_keep_service_references_deferred_until_materialization(): void {
        $ready = false;
        $resolver = $this->createResolver(static function () use (&$ready): bool {
            return $ready;
        });
        $router = new Router(serviceResolver: $resolver);
        $group = $router->group('/deferred')
            ->get('/{id}', static fn () => null)
            ->name('deferred-validator');

        $group->param('id', ServiceReference::named('validator.accept'));
        $ready = true;
        $route = $router->getRouteByName('deferred-validator');
        self::assertNotNull($route);
        $router->materializeRouteDependencies($route);

        self::assertSame($route, Router::getRoute(RequestMethod::GET, ['deferred', 'allowed']));
        self::assertNull(Router::getRoute(RequestMethod::GET, ['deferred', 'denied']));
    }

    private function createResolver(Closure $isReady): ServiceResolverInterface {
        return new class ($isReady) implements ServiceResolverInterface {
            public function __construct(private readonly Closure $isReady) {
            }

            public function getServiceId(ServiceReference $reference): string {
                if ( ! ($this->isReady)()) {
                    throw new RuntimeException('Services are not available during route registration.');
                }
                return $reference->service;
            }

            public function getService(string $serviceId): object {
                if ($serviceId !== 'validator.accept') {
                    throw new RuntimeException('Unknown service ' . $serviceId);
                }
                return new class implements RouteParamValidatorInterface {
                    public function validate(mixed $value): bool {
                        return $value === 'allowed';
                    }
                };
            }
        };
    }
}
