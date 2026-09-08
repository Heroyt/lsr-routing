# LSR Routing

`lsr/routing` provides HTTP route registration and matching for the Laser framework, with named routes, route groups, middleware, controller attributes and compiled route caches. Its namespace is `Lsr\Core\Routing`.

## Requirements

- PHP `>= 8.4`.
- LSR interfaces `^0.3.20`, helpers `^0.3.0` and request `^0.3.0`.
- Nette DI `^3.2`, Laravel Serializable Closure `^2.0`, and PSR HTTP server middleware/handler interfaces `^1.0`.
- No PHP extensions are declared directly; dependencies may impose additional platform requirements. See [composer.json](composer.json).
- A writable cache location when compiled caching is enabled. The optional route-cache console commands need Symfony Console, which is a development dependency rather than a runtime requirement of this package.

## Installation

```sh
composer require lsr/routing
```

## Registering and matching routes

Registration and matching can be used without constructing the full LSR application:

```php
require __DIR__ . '/vendor/autoload.php';

use Lsr\Core\Routing\Router;
use Lsr\Enums\RequestMethod;
use Nyholm\Psr7\Response;

$router = new Router();
$router->get('/health', static fn() => new Response(200, [], 'OK'))
    ->name('health');

$route = Router::getRoute(RequestMethod::GET, ['health']);
echo $route?->getName(); // health
```

This example matches a route; it does not execute its handler or emit a response. HTTP dispatch is an application responsibility (provided by `lsr/core` in the framework). See [`Router`](src/Router.php) and [`Route`](src/Route.php) for the fluent APIs.

Paths support required parameters such as `/users/{id}` and optional parameters such as `/users/[id]`. `getRoute()` accepts path segments and a by-reference parameter array. Named routes are available through `getRouteByName()`.

Route tables are process-wide static state. For application-owned route sources, use `Router::setup()` to clear previous registration and load the configured sources/cache. Do not call it after the inline registration above: it clears those routes before loading its configured sources.

## Framework configuration

Register `Lsr\Core\Routing\DI\RoutingExtension` in the application's Nette DI configuration. Its [schema and service definitions](src/DI/RoutingExtension.php) expose:

- `routeFiles`: PHP files or directories containing route declarations. Route files are included in router instance scope and can register through `$this`.
- `controllers`: controller files or directories scanned for route attributes from [`Attributes`](src/Attributes).
- `cache.file`, `cache.autoCompile`, `cache.checkTimestamps` and `cache.commands`: compiled cache configuration. Timestamp checking defaults to `false`, so plan explicit cache invalidation/recompilation when deploying changed routes. The default cache file is `TMP_DIR . 'routes.php'`, or a `routes.php` file under the system temporary directory's `lsr` subdirectory when `TMP_DIR` is not defined.
- `sitemap.defaultIncluded`: whether routes are included in sitemap discovery by default; it defaults to `false`. Route metadata and sitemap declarations are discovery information, not access control. See [`Sitemap`](src/Sitemap).

Middleware can be attached to routes/groups. Named middleware groups and `serviceRef()` allow service-backed middleware; the DI extension supplies a Nette service resolver. Resolve configured route dependencies through `setup()` before dispatch. See [`RouteGroup`](src/RouteGroup.php) and [`NetteServiceResolver`](src/DI/NetteServiceResolver.php) for integration contracts.

`RouteGroup::param()` keeps service-reference validation deferred for built-in `Route` instances. If a custom group supplies another `RouteInterface` implementation, it resolves service references before calling that route's validator-only `param()` contract; raw references are not forwarded as validators. The base interface is unchanged.

## Development

GitHub Actions runs CS, PHPStan and PHPUnit on PHP 8.4 and 8.5. Run the same checks locally after installing development dependencies:

```sh
composer install --prefer-dist --no-interaction --no-progress
composer cs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --no-coverage
```

Configuration is in [phpstan.neon](phpstan.neon) and [phpunit.xml](phpunit.xml). CI does not require a coverage driver or external services. `composer test` remains available for coverage runs with a compatible driver.

Run `composer cs` to check PHP coding style and `composer cs:fix` (or `composer cbf`) to apply fixes with PHP CS Fixer. The rules and source paths are defined in [.php-cs-fixer.php](.php-cs-fixer.php).

## AI coding assistance

See [LSR Skills](https://github.com/Heroyt/lsr-skills) for AI agent skills for working with the LSR framework.

## License

Licensed under the [MIT License](LICENSE).
