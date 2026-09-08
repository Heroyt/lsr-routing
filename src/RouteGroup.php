<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\Sitemap\SitemapChangeFrequency;
use Lsr\Core\Routing\Sitemap\SitemapDefinition;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;

class RouteGroup
{
    /** @var array<string, RouteInterface> */
    protected array           $routes      = [];
    protected ?RouteInterface $activeRoute = null;

    /** @var list<MiddlewareInterface|string|ServiceReference> */
    protected array $middleware = [];
    /** @var array<string, RouteGroup> */
    protected array $groups = [];
    private readonly SitemapDefinition $sitemapDefinition;
    private readonly RouteMetadata $routeMetadata;

    /**
     * @var array<non-empty-string,list<RouteParamValidatorInterface|ServiceReference>>
     */
    public protected(set) array $paramValidators = [];

    public function __construct(
        protected readonly Router $router,
        public readonly string         $path = '',
        protected readonly ?RouteGroup $parent = null,
    ) {
        $this->sitemapDefinition = new SitemapDefinition($parent?->getSitemapDefinition());
        $this->routeMetadata = new RouteMetadata($parent?->getMetadataDefinition());
        $this->router->trackRouteGroup($this);
    }

    /**
     * Create a new GET route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function get(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::GET, $path, $handler);
    }

    /**
     * Create a new HEAD route in the group
     *
     * @param string                                                    $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function head(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::HEAD, $path, $handler);
    }

    /**
     * Create a new route int the group
     *
     * @param RequestMethod                                  $method
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function route(RequestMethod $method, string $path, array|callable|RouteInterface $handler): static {
        $route = $this->router->route($method, $this->combinePaths($path), $handler);
        $route->setGroup($this);
        // Add an already added middleware to the route
        $route->middleware(...$this->middleware);
        foreach ($this->paramValidators as $name => $validators) {
            $route->param($name, ...$validators);
        }
        // Save route
        $this->routes[$method->value . ':' . $path] = $route;
        // Set route as active
        $this->activeRoute = $route;
        return $this;
    }

    private function combinePaths(string $path): string {
        return trailingSlashIt($this->path) . ($path !== '' && $path[0] === '/' ? substr($path, 1) : $path);
    }

    /**
     * Include the active route, or establish an inheritable default before the first route.
     */
    public function sitemap(?string $name = null): static {
        if ($this->activeRoute === null) {
            return $this->sitemapAll($name);
        }
        if ($this->activeRoute instanceof Route) {
            $this->activeRoute->sitemap($name);
        }
        return $this;
    }

    /** Exclude the active route, or establish an inheritable default before the first route. */
    public function sitemapExclude(): static {
        if ($this->activeRoute === null) {
            return $this->sitemapExcludeAll();
        }
        if ($this->activeRoute instanceof Route) {
            $this->activeRoute->sitemapExclude();
        }
        return $this;
    }

    /** Set active-route priority, or an inheritable default before the first route. */
    public function priority(float $priority): static {
        if ($this->activeRoute === null) {
            return $this->priorityAll($priority);
        }
        if ($this->activeRoute instanceof Route) {
            $this->activeRoute->priority($priority);
        }
        return $this;
    }

    /** Set active-route frequency, or an inheritable default before the first route. */
    public function changefreq(SitemapChangeFrequency|string $frequency): static {
        if ($this->activeRoute === null) {
            return $this->changefreqAll($frequency);
        }
        if ($this->activeRoute instanceof Route) {
            $this->activeRoute->changefreq($frequency);
        }
        return $this;
    }

    /**
     * Shallow-merge active-route metadata, or defaults before the first route; null remains a value.
     *
     * @param array<string, mixed> $data
     */
    public function meta(array $data): static {
        if ($this->activeRoute === null) {
            return $this->metaAll($data);
        }
        if ($this->activeRoute instanceof Route) {
            $this->activeRoute->meta($data);
        }
        return $this;
    }

    /**
     * Include existing and future descendants unless they explicitly override inclusion.
     * The live parent link preserves child declarations even when defaults change later.
     */
    public function sitemapAll(?string $name = null): static {
        $this->sitemapDefinition->sitemap($name);
        return $this;
    }

    /** Exclude existing and future descendants without overriding explicit child inclusion. */
    public function sitemapExcludeAll(): static {
        $this->sitemapDefinition->sitemapExclude();
        return $this;
    }

    /** Set inherited priority without implicitly including descendants or replacing explicit values. */
    public function priorityAll(float $priority): static {
        $this->sitemapDefinition->priority($priority);
        return $this;
    }

    /** Set inherited frequency without implicitly including descendants or replacing explicit values. */
    public function changefreqAll(SitemapChangeFrequency|string $frequency): static {
        $this->sitemapDefinition->changefreq($frequency);
        return $this;
    }

    /**
     * Shallow-merge defaults for existing and future descendants; explicit child keys always win.
     *
     * @param array<string, mixed> $data
     */
    public function metaAll(array $data): static {
        $this->routeMetadata->merge($data);
        return $this;
    }

    /** @internal Live defaults shared with descendants, never flattened during registration. */
    public function getSitemapDefinition(): SitemapDefinition {
        return $this->sitemapDefinition;
    }

    /** @internal Application metadata defaults, independent of sitemap settings. */
    public function getMetadataDefinition(): RouteMetadata {
        return $this->routeMetadata;
    }

    /**
     * Add middleware to the last route, or to the whole group before its first route.
     */
    public function middleware(MiddlewareInterface|string|ServiceReference ...$middleware): static {
        $this->router->assertMiddlewareEntriesAllowed($middleware);
        if ( ! isset($this->activeRoute)) {
            return $this->middlewareAll(...$middleware);
        }
        if (method_exists($this->activeRoute, 'middleware')) {
            $this->activeRoute->middleware(...$middleware);
        }
        return $this;
    }

    /**
     * Add middleware to all existing and future routes in the group.
     */
    public function middlewareAll(MiddlewareInterface|string|ServiceReference ...$middleware): static {
        $this->router->assertMiddlewareEntriesAllowed($middleware);
        foreach ($this->routes as $route) {
            if (method_exists($route, 'middleware')) {
                $route->middleware(...$middleware);
            }
        }
        foreach ($this->groups as $group) {
            $group->middlewareAll(...$middleware);
        }
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * @internal
     * @return list<MiddlewareInterface|string|ServiceReference>
     */
    public function getMiddlewareDefinitions(): array {
        return $this->middleware;
    }

    /**
     * @internal
     * @param list<MiddlewareInterface|ServiceReference> $middleware
     */
    public function replaceMiddlewareDefinitions(array $middleware): void {
        $this->middleware = $middleware;
    }

    /**
     * Create a new POST route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function post(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::POST, $path, $handler);
    }

    /**
     * Create a new DELETE route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function delete(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::DELETE, $path, $handler);
    }

    /**
     * Create a new PUT route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function put(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::PUT, $path, $handler);
    }

    /**
     * Create a new PATCH route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function patch(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::PATCH, $path, $handler);
    }

    /**
     * Create a new OPTIONS route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function options(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::OPTIONS, $path, $handler);
    }

    /**
     * Create a new CONNECT route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function connect(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::CONNECT, $path, $handler);
    }

    /**
     * Create a new TRACE route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function trace(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::TRACE, $path, $handler);
    }

    /**
     * Create a new PUT route in the group
     *
     * @param string                                         $path
     * @param array{0:class-string|object,1:string}|callable|RouteInterface $handler
     *
     * @return $this
     * @throws Exceptions\DuplicateRouteException
     */
    public function update(string $path, array|callable|RouteInterface $handler): static {
        return $this->route(RequestMethod::PUT, $path, $handler);
    }

    /**
     * Name the last added route
     *
     * @param string $name
     *
     * @return $this
     */
    public function name(string $name): static {
        if ( ! isset($this->activeRoute)) {
            throw new RuntimeException('Cannot call RouteGroup::name() without first creating a route in the group.');
        }
        if (method_exists($this->activeRoute, 'name')) {
            $this->activeRoute->name($name);
        }
        return $this;
    }

    public function localize(string $locale, ?string $path = null): static {
        if ( ! isset($this->activeRoute)) {
            throw new RuntimeException('Cannot call RouteGroup::localize() without first creating a route in the group.');
        }
        if (method_exists($this->activeRoute, 'localize')) {
            $this->activeRoute->localize($locale, $path);
        }
        return $this;

    }

    public function redirectFrom(string $path, ?string $locale = null): static {
        if ( ! isset($this->activeRoute)) {
            throw new RuntimeException('Cannot call RouteGroup::redirectFrom() without first creating a route in the group.');
        }
        if (method_exists($this->activeRoute, 'redirectFrom')) {
            $this->activeRoute->redirectFrom($path, $locale);
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * Add a new nested group
     *
     * @param string $path
     *
     * @return RouteGroup
     */
    public function group(string $path): RouteGroup {
        $group = new self($this->router, $this->combinePaths($path), $this);
        $group->middlewareAll(...$this->middleware);
        $this->groups[$path] = $group;
        return $group;
    }

    /**
     * End editing this group and return to its parent
     *
     * @return RouteGroup
     */
    public function endGroup(): RouteGroup {
        if ( ! isset($this->parent)) {
            throw new RuntimeException('Cannot end group, because it has no parent.');
        }
        return $this->parent;
    }

    /**
     * Setup a route parameter validator.
     *
     * @param non-empty-string $name
     */
    public function param(
        string $name,
        RouteParamValidatorInterface|ServiceReference ...$validators,
    ): RouteGroup {
        if (isset($this->activeRoute)) {
            $this->activeRoute->param($name, ...$validators);
            return $this;
        }

        $this->paramAll($name, ...$validators);
        return $this;
    }

    /**
     * Add route parameter validators to all existing and future routes.
     *
     * @param non-empty-string $name
     */
    public function paramAll(
        string $name,
        RouteParamValidatorInterface|ServiceReference ...$validators,
    ): RouteGroup {
        $this->paramValidators[$name] = array_merge($this->paramValidators[$name] ?? [], $validators);
        foreach ($this->routes as $route) {
            if ($route instanceof Route) {
                $route->param($name, ...$validators);
            }
        }
        return $this;
    }

}
