<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Enums\RequestMethod;
use PHPUnit\Framework\TestCase;

final class DomainAttributeTest extends TestCase
{
    protected function setUp(): void {
        (new Router())->unregisterAll();
    }

    protected function tearDown(): void {
        (new Router())->unregisterAll();
    }

    public function test_attribute_precedence_resolves_aliases_declared_after_controller_scanning(): void {
        $router = new Router(
            [ROOT . 'fixtures/domains/attribute-aliases.php'],
            controllers: [ROOT . 'src/DomainController.php'],
        );
        $router->setup();

        foreach ([
            ['class', 'class.example'],
            ['method', 'method.example'],
            ['repeated', 'route.example'],
            ['route', 'route.example'],
        ] as [$path, $domain]) {
            $route = Router::getRoute(RequestMethod::GET, ['attribute', $path], host: $domain);
            self::assertInstanceOf(Route::class, $route);
            self::assertSame($router->getRouteByName('domain.attribute.' . $path), $route);
            self::assertSame($domain, $route->getDomain());
            foreach ([null, 'class.example', 'method.example', 'route.example'] as $otherHost) {
                if ($otherHost !== $domain) {
                    self::assertNull(Router::getRoute(RequestMethod::GET, ['attribute', $path], host: $otherHost));
                }
            }
        }

        foreach ([
            'connect' => RequestMethod::CONNECT,
            'delete' => RequestMethod::DELETE,
            'head' => RequestMethod::HEAD,
            'options' => RequestMethod::OPTIONS,
            'patch' => RequestMethod::PATCH,
            'post' => RequestMethod::POST,
            'put' => RequestMethod::PUT,
            'trace' => RequestMethod::TRACE,
            'update' => RequestMethod::PUT,
        ] as $path => $method) {
            $route = Router::getRoute($method, ['attribute', $path], host: 'route.example');
            self::assertInstanceOf(Route::class, $route);
            self::assertSame('route.example', $route->getDomain());
            self::assertNull(Router::getRoute($method, ['attribute', $path], host: 'class.example'));
        }
    }
}
