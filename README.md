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

## Exact-host routing

Version 0.5 adds optional domain constraints without changing `RouteInterface` or the unrestricted route table. `domain()` creates a `RouteGroup`, so there is no need to call `group()` first:

```php
$this->domain('public')
    ->get('/', [PublicController::class, 'index'])
    ->name('public.home');

$this->domain('admin')
    ->middlewareAll('admin-authentication')
    ->get('/', [AdminController::class, 'index'])
    ->name('admin.home');

// Declarations may follow their use, including in a later route file.
$this->declareDomain('www.example.com', alias: 'public');
$this->declareDomain('admin.example.com', alias: 'admin');

// No constraint: intentionally available on every host.
$this->get('/health', [HealthController::class, 'show']);
```

The example assumes the application also declares the `admin-authentication` middleware group. A domain constraint is not authentication or authorization.

`domain('admin')->group('/api')` and `group('/api')->domain('admin')` both produce an `/api` group restricted to the admin host. On a group, `domain()` creates a child with the same path prefix and inherited settings; existing routes and sibling groups are not changed. Ordinary children inherit the host constraint, while another `domain()` child explicitly selects its own host.

### Aliases and setup

After all controller attributes and route files load, each domain reference receives **one alias lookup**. If the alias exists, its concrete hostname replaces the reference; otherwise, the original string is treated as the hostname. Aliases can themselves look like hostnames. Unregistered strings are not missing-alias errors, and alias targets are not recursively expanded.

Aliases and literal hostnames resolving to the same host contribute to the same routing tree. Duplicate routes are checked after resolution using their final host, method and path; group middleware remains attached to its own routes. Conflicting declarations for one alias throw `InvalidArgumentException`; repeating the same normalized mapping is allowed before resolution. Alias names use exact string matching.

`setup()` and `loadRoutes()` finalize domain constraints automatically, after middleware dependency resolution. For inline registration, call `$router->resolveDomains()` after the last declaration, before matching by host, generating domain-bound links or discovering domain-bound sitemap routes. Host-aware matching refuses pending declarations rather than exposing them as unrestricted routes. Alias declarations are frozen after resolution; new routes may still use existing aliases. `unregisterAll()` resets domain tables, aliases and resolution state.

Concrete hosts are case-insensitive; a single terminal DNS dot is ignored. ASCII DNS/punycode names, single-label hosts such as `localhost`, IPv4 and IPv6 literals are supported. Schemes, ports, paths, user information and wildcards are not hostname constraints. Convert internationalized names to punycode before configuration.

### Matching and HTTP behavior

```php
$params = [];
$route = Router::getRoute(
    RequestMethod::GET,
    ['users', '42'],
    $params,
    host: $request->getUri()->getHost(),
);
```

The optional fifth argument preserves existing calls, including the fourth argument used for an explicit route tree. Without a host, only unrestricted routes are eligible. With a host, matching tries that host's tree first, then unrestricted routes as a per-method fallback. Parameters from unsuccessful candidates never leak into the selected route.

Domain-only paths on the wrong host are absent, not redirects. A method mismatch produces `MethodNotAllowedException` only if the path exists in an applicable tree. Automatic `HEAD` stays within the selected GET route's host; a host-specific GET fallback precedes an unrestricted HEAD route. Explicit `OPTIONS` handlers take priority over synthesis; synthetic `OPTIONS`, including `OPTIONS *`, combines methods from the current host and unrestricted routes only.

Route names remain globally unique across hosts. Localized paths share their logical route's domain. `redirectFrom()` inherits that domain; an explicitly registered redirect alias can instead have its own source domain and redirect to a different destination host, preserving the request scheme, port and query.

### Attributes, cache and discovery

`#[Domain('admin')]` can constrain a controller class or method. Method declarations override class declarations; a route attribute's optional trailing `domain:` argument overrides both:

```php
use Lsr\Core\Routing\Attributes\Domain;
use Lsr\Core\Routing\Attributes\Get;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

#[Domain('admin')]
class AdminController
{
    #[Get('/dashboard', name: 'admin.dashboard')]
    public function dashboard(): ResponseInterface {
        return new Response(200, [], 'Admin dashboard');
    }

    #[Get('/status', name: 'public.status', domain: 'public')]
    public function status(): ResponseInterface {
        return new Response(200, [], 'Public status');
    }
}
```

The attributes are under `Lsr\Core\Routing\Attributes`. They use the same deferred alias resolution as fluent groups.

`Router::$availableRoutes` and `getAvailableRoutes()` retain their unrestricted tree shape. `getDomainRoutes()` exposes the resolved per-host trees separately. Built-in routes implement the optional `DomainRouteInterface::getDomain()` capability; custom implementations of the existing `RouteInterface` remain valid. Sitemap discovery still returns route objects, whose resolved domains are available for application-owned URL generation and host filtering; domain restrictions do not implicitly include or exclude routes from sitemaps.

Compiled cache format 4 stores resolved host constraints, domain trees and aliases. Older formats are rejected and rebuilt from configured sources. Rebuild the route cache whenever domain configuration changes, including environment-derived alias targets; warm loading does not execute route declarations again. Keep caches deployment-specific and restart long-running workers after route changes.

`lsr/core` 0.5 forwards the request host and generates domain-aware named links and redirects. Routing does not validate a global host allowlist or trust forwarded headers; configure accepted hosts and trusted proxies at the application/web-server layer.

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
