<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use Generator;
use InvalidArgumentException;
use LogicException;
use Lsr\Core\Routing\Attributes\Domain;
use Lsr\Core\Routing\Attributes\Meta;
use Lsr\Core\Routing\Attributes\Route as RouteAttribute;
use Lsr\Core\Routing\Attributes\Sitemap;
use Lsr\Core\Routing\Attributes\SitemapExclude;
use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Lsr\Core\Routing\Domain\Hostname;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Exceptions\MethodNotAllowedException;
use Lsr\Core\Routing\Exceptions\MiddlewareGroupNotFoundException;
use Lsr\Core\Routing\Exceptions\MiddlewareGroupsResolvedException;
use Lsr\Core\Routing\Exceptions\RouteCacheCompilationException;
use Lsr\Core\Routing\Exceptions\ServiceReferenceException;
use Lsr\Core\Routing\Interfaces\DomainRouteInterface;
use Lsr\Core\Routing\Interfaces\LocalizableRouteInterface;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\Interfaces\ServiceResolverInterface;
use Lsr\Core\Routing\Sitemap\SitemapEntry;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Server\MiddlewareInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use RegexIterator;
use RuntimeException;

class Router
{
    protected const string FIRST_ROUTE_KEY = '0';
    protected const string PARAM_REGEX          = '({(?P<name>[^}]+)})\/?';
    protected const string OPTIONAL_PARAM_REGEX = '(\[(?P<optname>[^[\]=]+)(?:=(?P<default>[^[\]]*))?])';

    protected const string ANY_PARAM_REGEX = '(?:' . self::PARAM_REGEX . ')|(?:' . self::OPTIONAL_PARAM_REGEX . ')';
    protected const string ASTERISK_ROUTE = '*';
    /** @var array<string, RouteNode> Structure holding all set routes */
    public static array $availableRoutes = [];
    /** @var array<string, RouteInterface> Array of named routes with their names as array keys */
    public static array $namedRoutes = [];
    /** @var array<array-key,array<string,RouteNode>> Host-scoped trees never enter the unrestricted table. */
    private static array $domainRoutes = [];
    /** @var array<int,true> Routers with declarations not yet safe to match. */
    private static array $pendingDomainOwners = [];
    /** @var array<array-key,string> Alias to normalized concrete hostname. */
    private array $domainAliases = [];
    /** @var array<int,Route> */
    private array $pendingDomainRoutes = [];
    private bool $domainsResolved = false;

    /** @var array<non-empty-string,list<MiddlewareInterface|ServiceReference>> */
    private array $middlewareGroups = [];
    /** @var array<int,Route> */
    private array $ownedRoutes = [];
    /** @var array<int,RouteGroup> */
    private array $routeGroups = [];
    private bool $middlewareGroupsResolved = false;

    /**
     * Sitemap discovery defaults to opt-in. Set sitemapDefaultIncluded to true
     * for public-first applications, then exclude private routes and groups.
     * Nette DI exposes this as routing.sitemap.defaultIncluded.
     * Inclusion is a discovery hint, not an authorization or indexing guarantee.
     *
     * @param string[] $routeFiles
     * @param string[] $controllers
     */
    public function __construct(
        private readonly array $routeFiles = [],
        private readonly array $controllers = [],
        private readonly ?CompiledRouteCache $compiledRouteCache = null,
        private readonly ?ServiceResolverInterface $serviceResolver = null,
        private readonly bool $sitemapDefaultIncluded = false,
    ) {
    }

    public function isSitemapDefaultIncluded(): bool {
        return $this->sitemapDefaultIncluded;
    }

    /**
     * Discover logical GET routes in one sitemap. Null selects the default sitemap.
     *
     * Call after setup(), or after registering routes manually. Redirect aliases,
     * localized wrappers and unregistered routes are never returned. Ordering is
     * unspecified; callers needing stable file partitioning should sort by route identity.
     *
     * @return list<Route>
     */
    public function getSitemapRoutes(?string $name = null): array {
        $routes = [];
        foreach ($this->sitemapCandidates() as $route) {
            $metadata = $route->getSitemapMetadata();
            if ($metadata->included && $metadata->name === $name) {
                $routes[] = $route;
            }
        }
        return $routes;
    }

    /** @return list<string|null> Names of nonempty sitemaps; null denotes the default sitemap. */
    public function getSitemapNames(): array {
        $names = [];
        foreach ($this->sitemapCandidates() as $route) {
            $metadata = $route->getSitemapMetadata();
            if ($metadata->included && ! in_array($metadata->name, $names, true)) {
                $names[] = $metadata->name;
            }
        }
        return $names;
    }

    /**
     * Expand logical routes into their canonical language paths.
     *
     * Every entry in a family has the same hreflang map, including itself when
     * its locale is declared. An unlabelled primary path is not an x-default.
     * Applications supply absolute URLs and parameter values, filter unavailable
     * content translations consistently, and render loc/xhtml:link elements.
     * Locale underscores become hyphens and casing is normalized to lowercase.
     * Language, optional script/region and explicit x-default shapes are supported;
     * applications must use assigned language/region codes for their declared locales.
     *
     * @see https://developers.google.com/search/docs/specialty/international/localized-versions#sitemap
     *
     * @return list<SitemapEntry>
     */
    public function getSitemapEntries(?string $name = null): array {
        $entries = [];
        foreach ($this->getSitemapRoutes($name) as $route) {
            $variants = [$route];
            foreach ($route->localizedRoutes as $localized) {
                if ($localized instanceof LocalizedRoute && $this->isRegisteredRoute($localized)) {
                    $variants[] = $localized;
                }
            }
            $alternates = [];
            foreach ($variants as $variant) {
                $locale = $variant->getLocale();
                if ($locale === null) {
                    continue;
                }
                $hreflang = strtolower(str_replace('_', '-', $locale));
                if (
                    $hreflang !== 'x-default'
                    && preg_match('/^[a-z]{2}(?:-[a-z]{4})?(?:-[a-z]{2})?$/D', $hreflang) !== 1
                ) {
                    throw new InvalidArgumentException(
                        sprintf('Route "%s" has an unsupported sitemap locale "%s".', $route->getReadable(), $locale),
                    );
                }
                if (isset($alternates[$hreflang])) {
                    throw new InvalidArgumentException(
                        sprintf('Route "%s" has duplicate sitemap hreflang "%s".', $route->getReadable(), $hreflang),
                    );
                }
                $alternates[$hreflang] = $variant;
            }
            $metadata = $route->getSitemapMetadata();
            foreach ($variants as $variant) {
                $entries[] = new SitemapEntry($variant, $metadata, $alternates);
            }
        }
        return $entries;
    }

    /** @return Generator<int,Route> */
    private function sitemapCandidates(): Generator {
        foreach ($this->ownedRoutes as $route) {
            if (
                $route->getMethod() === RequestMethod::GET
                && ! ($route instanceof LocalizedRoute)
                && ! ($route instanceof AliasRoute)
                && $this->isRegisteredRoute($route)
            ) {
                yield $route;
            }
        }
    }

    private function isRegisteredRoute(Route $route): bool {
        $domain = $route->getDomain();
        /** @var array<array-key,mixed> $node The matcher contains nested nodes and numeric leaf keys. */
        $node = $domain === null ? self::$availableRoutes : (self::$domainRoutes[$domain] ?? []);
        foreach ($route->getPath() as $part) {
            $child = $node[strtolower($part)] ?? null;
            if ($child instanceof RouteParameter) {
                $node = $child->routes;
            } elseif (is_array($child)) {
                $node = $child;
            } else {
                return false;
            }
        }
        $methods = $node[$route->getMethod()->value] ?? null;
        if ($methods instanceof RouteParameter) {
            $methods = $methods->routes;
        }
        return is_array($methods) && ($methods[self::FIRST_ROUTE_KEY] ?? null) === $route;
    }

    public function declareDomain(string $domain, string $alias): self {
        if ($this->domainsResolved) {
            throw new LogicException('Domain declarations are already resolved.');
        }
        if ($alias === '') {
            throw new InvalidArgumentException('A domain alias must not be empty.');
        }
        $domain = Hostname::normalize($domain);
        if (isset($this->domainAliases[$alias]) && $this->domainAliases[$alias] !== $domain) {
            throw new InvalidArgumentException(sprintf('Domain alias "%s" is already declared for another hostname.', $alias));
        }
        $this->domainAliases[$alias] = $domain;
        return $this;
    }

    public function domain(string $domain): RouteGroup {
        if ($domain === '') {
            throw new InvalidArgumentException('A domain constraint must not be empty.');
        }
        return new RouteGroup($this, domainReference: $domain);
    }

    /** Finalize inline declarations, or automatically finalize after loading all route sources. */
    public function resolveDomains(): void {
        if ($this->domainsResolved) {
            return;
        }
        foreach ($this->routeGroups as $group) {
            $reference = $group->getDomainReference();
            if ($reference !== null) {
                $this->resolveDomainReference($reference);
            }
        }
        $trees = self::$domainRoutes;
        foreach ($this->pendingDomainRoutes as $route) {
            $reference = $route->getDomainReference();
            assert($reference !== null);
            $domain = $this->resolveDomainReference($reference);
            $route->resolveDomain($domain);
            $trees[$domain] ??= [];
            $this->indexRoute($route, $trees[$domain]);
        }
        self::$domainRoutes = $trees;
        $this->pendingDomainRoutes = [];
        $this->domainsResolved = true;
        unset(self::$pendingDomainOwners[spl_object_id($this)]);
    }

    private function resolveDomainReference(string $reference): string {
        return $this->domainAliases[$reference] ?? Hostname::normalize($reference);
    }

    /** @return array<array-key,array<string,RouteNode>> */
    public function getDomainRoutes(): array {
        if (self::$pendingDomainOwners !== []) {
            throw new LogicException('Resolve domain declarations before reading the domain route tables.');
        }
        return self::$domainRoutes;
    }

    /** @return array<array-key,string> */
    public function getDomainAliases(): array {
        return $this->domainAliases;
    }

    public function serviceRef(string $service): ServiceReference {
        return new ServiceReference($service);
    }

    public function middlewareGroup(
        string $name,
        MiddlewareInterface|ServiceReference ...$middleware,
    ): self {
        $this->assertMiddlewareGroupReferenceAllowed();
        $name = $this->normalizeMiddlewareGroupName($name);
        foreach ($middleware as $entry) {
            if ($entry instanceof MiddlewareInterface && in_array($entry, $this->middlewareGroups[$name] ?? [], true)) {
                continue;
            }
            $this->middlewareGroups[$name][] = $entry;
        }
        $this->middlewareGroups[$name] ??= [];
        return $this;
    }

    public function areMiddlewareGroupsResolved(): bool {
        return $this->middlewareGroupsResolved;
    }

    public function assertMiddlewareGroupReferenceAllowed(): void {
        if ($this->middlewareGroupsResolved) {
            throw new MiddlewareGroupsResolvedException();
        }
    }

    /**
     * @param array<array-key,MiddlewareInterface|string|ServiceReference> $middleware
     */
    public function assertMiddlewareEntriesAllowed(array $middleware): void {
        if (array_any($middleware, static fn (mixed $entry): bool => is_string($entry))) {
            $this->assertMiddlewareGroupReferenceAllowed();
        }
    }

    /** @internal */
    public function trackRouteGroup(RouteGroup $group): void {
        $this->routeGroups[spl_object_id($group)] = $group;
    }

    /**
     * @internal
     * @return array<int,Route>
     */
    public function getOwnedRoutes(): array {
        return $this->ownedRoutes;
    }

    /**
     * @internal
     * @return array<string,RouteNode>
     */
    public function getAvailableRoutes(): array {
        return self::$availableRoutes;
    }

    /**
     * @internal
     * @return array<string,RouteInterface>
     */
    public function getNamedRoutes(): array {
        return self::$namedRoutes;
    }

    /**
     * @internal
     * @return string[]
     */
    public function getConfiguredRouteSources(): array {
        return $this->routeFiles;
    }

    /**
     * @internal
     * @return string[]
     */
    public function getConfiguredControllerSources(): array {
        return $this->controllers;
    }

    /**
     * Resolve all middleware group references and DI-controlled route dependencies.
     */
    private function resolveRouteDependencies(): void {
        $missing = [];
        foreach ($this->ownedRoutes as $route) {
            $this->collectMissingMiddlewareGroups(
                $route->getMiddlewareDefinitions(),
                $route->getMethod()->value . ' /' . implode('/', $route->getPath()),
                $missing,
            );
        }
        foreach ($this->routeGroups as $group) {
            $this->collectMissingMiddlewareGroups(
                $group->getMiddlewareDefinitions(),
                'group /' . ltrim($group->getPath(), '/'),
                $missing,
            );
        }
        if ($missing !== []) {
            foreach ($missing as &$contexts) {
                $contexts = array_values(array_unique($contexts));
            }
            unset($contexts);
            throw new MiddlewareGroupNotFoundException($missing);
        }

        foreach ($this->routeGroups as $group) {
            $group->replaceMiddlewareDefinitions(
                $this->expandMiddlewareGroups($group->getMiddlewareDefinitions()),
            );
        }
        foreach ($this->ownedRoutes as $route) {
            $this->materializeRouteDependencies($route);
        }
        $this->middlewareGroupsResolved = true;
    }

    /**
     * @internal
     */
    public function materializeRouteDependencies(Route $route): void {
        $definitions = $this->expandMiddlewareGroups($route->getMiddlewareDefinitions());
        $resolvedDefinitions = [];
        $resolvedMiddleware = [];
        foreach ($definitions as $definition) {
            $middleware = $definition instanceof ServiceReference
                ? $this->resolveServiceReference($definition, MiddlewareInterface::class)
                : $definition;
            if (in_array($middleware, $resolvedMiddleware, true)) {
                continue;
            }
            $resolvedDefinitions[] = $definition;
            $resolvedMiddleware[] = $middleware;
        }
        $route->replaceMiddleware($resolvedDefinitions, $resolvedMiddleware);

        $resolvedValidatorDefinitions = [];
        $resolvedValidators = [];
        foreach ($route->getParamValidatorDefinitions() as $name => $validators) {
            foreach ($validators as $definition) {
                $validator = $definition instanceof ServiceReference
                    ? $this->resolveServiceReference($definition, RouteParamValidatorInterface::class)
                    : $definition;
                if (in_array($validator, $resolvedValidators[$name] ?? [], true)) {
                    continue;
                }
                $resolvedValidatorDefinitions[$name][] = $definition;
                $resolvedValidators[$name][] = $validator;
            }
        }
        $route->replaceParamValidators($resolvedValidatorDefinitions, $resolvedValidators);
        if ($resolvedValidators !== []) {
            $this->addParameterValidators($route);
        }
    }

    /**
     * @internal
     */
    public function getServiceId(ServiceReference $reference): string {
        if ($this->serviceResolver === null) {
            throw new ServiceReferenceException(
                sprintf('Cannot resolve route service "%s" without a configured service resolver.', $reference->service),
            );
        }
        return $this->serviceResolver->getServiceId($reference);
    }

    /**
     * @internal
     * @template T of object
     * @param class-string<T> $expectedType
     * @return T
     */
    public function resolveServiceId(string $serviceId, string $expectedType): object {
        if ($this->serviceResolver === null) {
            throw new ServiceReferenceException(
                sprintf('Cannot resolve route service "%s" without a configured service resolver.', $serviceId),
            );
        }
        $service = $this->serviceResolver->getService($serviceId);
        if ( ! $service instanceof $expectedType) {
            throw new ServiceReferenceException(
                sprintf(
                    'Route service "%s" must implement %s; got %s.',
                    $serviceId,
                    $expectedType,
                    $service::class,
                ),
            );
        }
        return $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $expectedType
     * @return T
     */
    private function resolveServiceReference(ServiceReference $reference, string $expectedType): object {
        return $this->resolveServiceId($this->getServiceId($reference), $expectedType);
    }

    /**
     * @param list<MiddlewareInterface|string|ServiceReference> $middleware
     * @param non-empty-string $context
     * @param array<non-empty-string,list<non-empty-string>>     $missing
     */
    private function collectMissingMiddlewareGroups(array $middleware, string $context, array &$missing): void {
        foreach ($middleware as $entry) {
            if ( ! is_string($entry)) {
                continue;
            }
            $name = $this->normalizeMiddlewareGroupName($entry);
            if ( ! array_key_exists($name, $this->middlewareGroups)) {
                $missing[$name][] = $context;
            }
        }
    }

    /**
     * @param list<MiddlewareInterface|string|ServiceReference> $middleware
     * @return list<MiddlewareInterface|ServiceReference>
     */
    private function expandMiddlewareGroups(array $middleware): array {
        $expanded = [];
        foreach ($middleware as $entry) {
            if ( ! is_string($entry)) {
                $expanded[] = $entry;
                continue;
            }
            $name = $this->normalizeMiddlewareGroupName($entry);
            if ( ! array_key_exists($name, $this->middlewareGroups)) {
                throw new MiddlewareGroupNotFoundException([$name => ['route materialization']]);
            }
            array_push($expanded, ...$this->middlewareGroups[$name]);
        }
        return $expanded;
    }

    /**
     * @return non-empty-string
     */
    private function normalizeMiddlewareGroupName(string $name): string {
        $name = strtolower(trim($name));
        if ($name === '') {
            throw new InvalidArgumentException('A middleware group name must not be empty.');
        }
        return $name;
    }

    /**
     * Compare 2 different paths
     *
     * @param string[] $path1
     * @param string[] $path2 Defaults to current request path
     *
     * @return bool
     */
    public static function comparePaths(array $path1, array $path2): bool {
        foreach ($path1 as $key => $value) {
            if ( ! is_numeric($key)) {
                unset($path1[$key]);
            } else {
                $path1[$key] = strtolower($value);
            }
        }
        foreach ($path2 as $key => $value) {
            if ( ! is_numeric($key)) {
                unset($path2[$key]);
            } else {
                $path2[$key] = strtolower($value);
            }
        }
        return $path1 === $path2;
    }

    /**
     * Get set route if it exists
     *
     * @param RequestMethod                 $type   [GET, POST, DELETE, PUT]
     * @param string[]                      $path   URL path as an array
     * @param array<string, mixed>          $params URL parameters in a key-value array
     * @param array<string, RouteNode>|null $routes Available routes that should be processed
     *
     * @return RouteInterface|null
     *
     * @throws MethodNotAllowedException
     */
    public static function getRoute(RequestMethod $type, array $path, array &$params = [], ?array $routes = null, ?string $host = null): ?RouteInterface {
        if ($routes === null && $host !== null && $host !== '') {
            if (self::$pendingDomainOwners !== []) {
                throw new LogicException('Resolve domain declarations before matching a request host.');
            }
            if (self::$domainRoutes !== []) {
                return self::getHostRoute($type, $path, $params, Hostname::normalize($host));
            }
        }
        if ($routes === null && self::$domainRoutes !== [] && ($host === null || $host === '')) {
            return self::getHostRoute($type, $path, $params, '');
        }
        if ( ! isset($routes)) {
            $routes = self::$availableRoutes; // Default routes value
        }

        // Make sure the path doesn't contain any empty parts (e.g. due to double slashes)
        $path = array_values(array_filter($path));

        if ($type === RequestMethod::OPTIONS && $path === [self::ASTERISK_ROUTE]) {
            return self::getAsteriskOptionsRoute($routes);
        }

        $counter = 0;
        foreach ($path as $value) {
            assert(is_array($routes));
            $counter++;
            // Check if path key exists and if it does, move into it.
            if (isset($routes[$value])) {
                $routes = $routes[$value];
                continue;
            }

            // Get all parameter parts available for the current path
            $paramRoutes = array_filter(
                $routes,
                static fn ($node) => $node instanceof RouteParameter,
            );
            $paramRouteCount = count($paramRoutes);

            // Exactly one available parameter found
            if ($paramRouteCount === 1) {
                $key = array_key_first($paramRoutes);
                $paramRoute = $paramRoutes[$key];

                $name = $paramRoute->name;

                if ($paramRoute->optional) {
                    $route = self::tryOptionalParam($type, $path, $params, $paramRoute, $value, $counter);
                    if (isset($route)) { // Found
                        return $route;
                    }
                } else {
                    // Validate the parameter value - if invalid, treat as not found.
                    if ( ! $paramRoute->validate($value)) {
                        // No route found
                        return null;
                    }

                    // Required parameter - set value and move into it.
                    $routes = $paramRoute->routes;                       // Move into the parameter routes
                    $params[$name] = $value;                             // Set the parameter value
                    continue;
                }
            }

            // More than one parameter found → check all possible paths
            if ($paramRouteCount > 1) {
                foreach ($paramRoutes as $paramRoute) {
                    if ( ! $paramRoute->validate($value)) {
                        continue; // Invalid parameter value → skip
                    }

                    if ($paramRoute->optional) {
                        $route = self::tryOptionalParam($type, $path, $params, $paramRoute, $value, $counter);
                        if (isset($route)) { // Found
                            return $route;
                        }
                        continue;
                    }

                    $routes = $paramRoute->routes;
                    $params[$paramRoute->name] = $value;

                    // Recurse
                    $route = self::getRoute(
                        $type,
                        array_slice($path, $counter),
                        $params,
                        $routes,
                    );
                    if (isset($route)) { // Found
                        return $route;
                    }
                    // Not found → the parameter was invalid → remove the parameter value and try the next parameter.
                    unset($params[$paramRoute->name]);
                }
            }

            // No route found
            return null;
        }

        assert(is_array($routes));
        /** @var array<string, RouteNode> $routes */

        // Check optional parameters at the end of the path
        $optionalParams = array_filter(
            $routes,
            static fn ($node) => $node instanceof RouteParameter && $node->optional,
        );
        foreach ($optionalParams as $paramRoutes) {
            // No need to validate, because only the default value is used, and we assume it is valid.
            try {
                $route = self::tryOptionalParam($type, [], $params, $paramRoutes, null, $counter);
                if (isset($route)) { // Found
                    return $route;
                }
            } catch (MethodNotAllowedException) {
            }
        }

        // Check if the request method exists for the found route
        if (isset($routes[$type->value]) && is_array($routes[$type->value]) && count($routes[$type->value]) !== 0) {
            // Return the first
            $route = reset($routes[$type->value]);
            assert($route instanceof RouteInterface);
            return self::resolveRoute($route, $params);
        }

        if ($type === RequestMethod::OPTIONS) {
            $allowedMethods = self::getAllowedMethods($routes);
            if ($allowedMethods !== []) {
                return OptionsRoute::createFallback(
                    $allowedMethods,
                    $path,
                    '/' . implode('/', $path),
                );
            }
        }

        if (isset($routes[RequestMethod::GET->value]) && $type === RequestMethod::HEAD && is_array($routes[RequestMethod::GET->value]) && count($routes[RequestMethod::GET->value]) !== 0) {
            $route = reset($routes[RequestMethod::GET->value]);
            assert($route instanceof RouteInterface);
            return HeadRoute::createFallback(self::resolveRoute($route, $params));
        }

        // Route exists, but the method for this route doesn't
        throw new MethodNotAllowedException(
            'Method ' . $type->value . ' is not allowed for path /' . implode('/', $path),
        );
    }

    /**
     * Host routes take precedence; unrestricted routes remain a per-method fallback.
     * Failed candidates never contribute parameters or methods from another host.
     *
     * @param string[] $path
     * @param array<string,mixed> $params
     */
    private static function getHostRoute(RequestMethod $type, array $path, array &$params, string $host): ?RouteInterface {
        $trees = [];
        if (isset(self::$domainRoutes[$host])) {
            $trees[] = self::$domainRoutes[$host];
        }
        $trees[] = self::$availableRoutes;
        $methodFailure = null;
        $options = null;
        $optionsParams = [];
        $allowed = [];
        foreach ($trees as $tree) {
            $candidateParams = $params;
            try {
                $route = self::getRoute($type, $path, $candidateParams, $tree);
            } catch (MethodNotAllowedException $exception) {
                // The legacy matcher also throws for empty method leaves. Only a
                // successful OPTIONS probe establishes an actual 405 for this host.
                $probeParams = $params;
                try {
                    if (self::getRoute(RequestMethod::OPTIONS, $path, $probeParams, $tree) !== null) {
                        $methodFailure ??= $exception;
                    }
                } catch (MethodNotAllowedException) {
                }
                continue;
            }
            if ($route === null) {
                continue;
            }
            if ($route instanceof OptionsRoute) {
                $options ??= $route;
                if ($options === $route) {
                    $optionsParams = $candidateParams;
                }
                foreach ($route->allowedMethods as $method) {
                    $allowed[$method->value] = $method;
                }
                continue;
            }
            $params = $candidateParams;
            return $route;
        }
        if ($options !== null) {
            assert($allowed !== []);
            $params = $optionsParams;
            return OptionsRoute::createFallback(array_values($allowed), $options->getPath(), $options->getReadable());
        }
        if ($methodFailure !== null) {
            throw $methodFailure;
        }
        return null;
    }

    /**
     * Resolve locale metadata while keeping one logical route identity.
     *
     * @param array<string,mixed> $params
     */
    private static function resolveRoute(RouteInterface $route, array &$params): RouteInterface {
        if ( ! $route instanceof LocalizableRouteInterface) {
            return $route;
        }

        $locale = $route->getLocale();
        if ($locale !== null) {
            $params['lang'] = $locale;
        }
        return $route->getCanonicalRoute();
    }

    /**
     * @param array<string, RouteNode> $routes
     *
     * @return RouteInterface|null
     */
    private static function getAsteriskOptionsRoute(array $routes): ?RouteInterface {
        /** @var mixed $asteriskRoutes */
        $asteriskRoutes = $routes[self::ASTERISK_ROUTE] ?? null;
        if (is_array($asteriskRoutes)) {
            /** @var mixed $optionsRoutes */
            $optionsRoutes = $asteriskRoutes[RequestMethod::OPTIONS->value] ?? null;
            if (is_array($optionsRoutes)) {
                foreach ($optionsRoutes as $route) {
                    if ($route instanceof RouteInterface) {
                        return $route;
                    }
                }
            }
        }

        $allowedMethods = self::getAllowedMethodsRecursive($routes);
        if ($allowedMethods === []) {
            return null;
        }

        return OptionsRoute::createFallback($allowedMethods, [self::ASTERISK_ROUTE], self::ASTERISK_ROUTE);
    }

    /**
     * @param array<string, RouteNode> $routes
     *
     * @return list<RequestMethod>
     */
    private static function getAllowedMethods(array $routes): array {
        $methods = [];
        foreach (RequestMethod::cases() as $method) {
            if (isset($routes[$method->value]) && is_array($routes[$method->value]) && count($routes[$method->value]) !== 0) {
                $methods[$method->value] = $method;
            }
        }

        if (isset($methods[RequestMethod::GET->value])) {
            $methods[RequestMethod::HEAD->value] ??= RequestMethod::HEAD;
        }

        if ($methods === []) {
            return [];
        }

        $methods[RequestMethod::OPTIONS->value] ??= RequestMethod::OPTIONS;

        return array_values($methods);
    }

    /**
     * @param array<string, RouteNode> $routes
     *
     * @return list<RequestMethod>
     */
    private static function getAllowedMethodsRecursive(array $routes): array {
        $methods = self::getAllowedMethods($routes);
        foreach ($routes as $routeNode) {
            if ($routeNode instanceof RouteParameter) {
                foreach (self::getAllowedMethodsRecursive($routeNode->routes) as $method) {
                    $methods[$method->value] = $method;
                }
                continue;
            }

            if (is_array($routeNode)) {
                foreach (self::getAllowedMethodsRecursive($routeNode) as $method) {
                    $methods[$method->value] = $method;
                }
            }
        }

        return array_values($methods);
    }

    /**
     * @param RequestMethod       $type
     * @param string[]            $path
     * @param array<string,mixed> $params
     * @param RouteParameter      $routeParam
     * @param string|null         $value
     * @param int                 $counter
     *
     * @return RouteInterface|null
     */
    protected static function tryOptionalParam(RequestMethod $type, array $path, array &$params, RouteParameter $routeParam, ?string $value, int $counter): ?RouteInterface {
        // Extract data from param
        $name = $routeParam->name;

        if ( ! empty($routeParam->default)) {
            $params[$name] = $routeParam->default;
        }
        try {
            $route = self::getRoute($type, $path, $params, $routeParam->routes);
            if (isset($route)) {
                return $route;
            }
        } catch (MethodNotAllowedException) {
        }

        if ($value !== null && ! $routeParam->validate($value)) {
            // Invalid parameter value → skip
            return null;
        }

        // Try to set the optional param
        if ($value !== null) {
            $params[$name] = $value; // Set the parameter value
        }

        // Recurse
        try {
            $route = self::getRoute($type, array_slice($path, $counter), $params, $routeParam->routes);
            if (isset($route)) { // Found
                return $route;
            }
        } catch (MethodNotAllowedException) {
        }
        // Not found → the parameter was invalid → remove the parameter value and try the next parameter.
        unset($params[$name]);


        try {
            $route = self::getRoute(
                $type,
                array_slice($path, 1),
                $params,
                $routeParam->routes,
            );
            if (isset($route)) {
                return $route;
            }
        } catch (MethodNotAllowedException) {
        }
        unset($params[$name]);
        return null;
    }

    /**
     * Initialize routes from a fresh compiled cache or from configured sources.
     *
     * @throws ReflectionException
     */
    public function setup(): void {
        $this->unregisterAll();
        if ($this->compiledRouteCache?->load($this) === true) {
            return;
        }

        $this->loadRoutes();
        if ($this->compiledRouteCache?->autoCompile !== true) {
            return;
        }
        try {
            $this->compiledRouteCache->compile($this);
        } catch (RouteCacheCompilationException) {
            // Route compilation is an optimization. The live route graph remains valid.
        }
    }

    public function compileCache(): void {
        if ($this->compiledRouteCache === null) {
            throw new RouteCacheCompilationException('No compiled route cache is configured.');
        }
        $this->unregisterAll();
        $this->loadRoutes();
        $this->compiledRouteCache->compile($this);
    }

    public function clearCache(): void {
        $this->compiledRouteCache?->clear();
    }

    /**
     * Load defined routes
     *
     *
     * @return array{0:array<string,RouteNode>,1:array<string,RouteInterface>} [availableRoutes, namedRoutes]
     * @throws DuplicateNamedRouteException
     * @throws DuplicateRouteException
     * @throws ReflectionException
     */
    public function loadRoutes(): array {
        // Setup route files
        $routeFiles = [];
        foreach ($this->routeFiles as $file) {
            if (is_dir($file)) {
                $files = glob(trailingSlashIt($file) . '*.php');
                if (is_array($files)) {
                    $routeFiles[] = $files;
                }
            } elseif (file_exists($file)) {
                $routeFiles[] = [$file];
            }
        }
        $routeFiles = array_merge(...$routeFiles);

        // Load from controllers
        $controllerFiles = [];
        foreach ($this->controllers as $file) {
            if (is_dir($file)) {
                $this->loadRoutesFromControllersDir($file, $controllerFiles);
            } elseif (file_exists($file)) {
                $this->loadRoutesFromControllerFile($file, $controllerFiles);
            }
        }

        // Load from files
        foreach ($routeFiles as $file) {
            require $file;
        }

        $this->resolveRouteDependencies();
        $this->resolveDomains();
        return [self::$availableRoutes, self::$namedRoutes];
    }

    /**
     * Recursively scans for controller classes in a directory and loads its routes
     *
     * @param string   $dir
     * @param string[] $files
     *
     * @return void
     * @throws DuplicateNamedRouteException
     * @throws DuplicateRouteException
     * @throws ReflectionException
     */
    private function loadRoutesFromControllersDir(string $dir, array &$files = []): void {
        $Directory = new RecursiveDirectoryIterator($dir);
        $Iterator = new RecursiveIteratorIterator($Directory);
        $Regex = new RegexIterator($Iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        /** @var array{0:string} $match */
        foreach ($Regex as $match) {
            $this->loadRoutesFromControllerFile($match[0], $files);
        }
    }

    /**
     * Scan one controller file for a controller class and its defined routes
     *
     * @param string   $classFile
     * @param string[] $files
     *
     * @return void
     * @throws DuplicateNamedRouteException
     * @throws DuplicateRouteException
     * @throws ReflectionException
     */
    private function loadRoutesFromControllerFile(string $classFile, array &$files = []): void {
        $f = fopen($classFile, 'rb');
        $namespace = '\App\Controllers';
        if (is_resource($f)) {
            while ($read = fscanf($f, 'namespace %s;')) {
                if ( ! empty($read[0])) {
                    $namespace = str_replace(';', '', $read[0]);
                    break;
                }
            }
            fclose($f);
        }
        $className = $namespace . '\\' . basename($classFile, '.php');
        if (class_exists($className)) {
            $files[] = $classFile;
            $this->loadRoutesFromController($className);
        }
    }

    /**
     * Scan controller's methods using reflection API to find any Route attributes
     *
     * @param class-string|object $controller
     *
     * @return void
     * @throws DuplicateRouteException
     * @throws DuplicateNamedRouteException
     * @throws ReflectionException
     */
    private function loadRoutesFromController(string|object $controller): void {
        // Initiate reflection class and get methods
        $reflection = new ReflectionClass($controller);
        $classDomain = $reflection->getAttributes(Domain::class)[0] ?? null;
        foreach ($reflection->getMethods() as $method) {
            // Find attributes of type Route
            $attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $attribute) {
                /** @var RouteAttribute $routeAttr */
                $routeAttr = $attribute->newInstance(); // Must instantiate a new attribute object

                // Create normal web route
                $route = Route::create($routeAttr->method, $routeAttr->path, [$controller, $method->getName()]);
                $methodDomain = $method->getAttributes(Domain::class)[0] ?? null;
                $route->setDomain(
                    $routeAttr->domain
                    ?? $methodDomain?->newInstance()->domain
                    ?? $classDomain?->newInstance()->domain,
                );
                foreach ($method->getAttributes(Meta::class) as $metaAttribute) {
                    $route->meta($metaAttribute->newInstance()->data);
                }
                foreach ($method->getAttributes(Sitemap::class) as $sitemapAttribute) {
                    $sitemapAttribute->newInstance()->apply($route);
                }
                if ($method->getAttributes(SitemapExclude::class) !== []) {
                    $route->sitemapExclude();
                }
                $this->register($route);
                if ( ! empty($routeAttr->name)) {
                    $test = $this->getRouteByName($routeAttr->name);
                    if ($test !== null && ! $route->compare($test)) {
                        throw new DuplicateNamedRouteException($test, $route);
                    }
                    $route->setName($routeAttr->name);
                    $this->registerNamed($route);
                }
            }
        }
    }

    /**
     * Add a new route into availableRoutes array
     *
     * @param RouteInterface $route Route object
     *
     * @throws DuplicateRouteException
     */
    public function register(RouteInterface $route): void {
        if ($route instanceof Route) {
            $reference = $route->getDomainReference();
            if ($reference !== null && ! $this->domainsResolved) {
                $route->setRouter($this);
                $this->pendingDomainRoutes[spl_object_id($route)] = $route;
                $this->ownedRoutes[spl_object_id($route)] = $route;
                self::$pendingDomainOwners[spl_object_id($this)] = true;
                return;
            }
            if ($reference !== null) {
                $route->resolveDomain($this->resolveDomainReference($reference));
            }
        }
        $domain = $route instanceof DomainRouteInterface ? $route->getDomain() : null;
        if ($domain === null) {
            $this->indexRoute($route, self::$availableRoutes);
        } else {
            $domain = Hostname::normalize($domain);
            self::$domainRoutes[$domain] ??= [];
            $this->indexRoute($route, self::$domainRoutes[$domain]);
        }
        if ($route instanceof Route) {
            $this->ownedRoutes[spl_object_id($route)] = $route;
            $route->setRouter($this);
        }
    }

    /**
     * @param array<string,RouteNode> $tree
     */
    private function indexRoute(RouteInterface $route, array &$tree): void {
        $routes = &$tree;
        $type = $route->getMethod();
        // Walk through the path and create a nested array structure
        foreach ($route->getPath() as $name) {
            $isParam = preg_match('/' . self::ANY_PARAM_REGEX . '/', $name, $matches) > 0;
            $paramName = $matches['name'] ?? '';
            if (empty($paramName)) {
                $paramName = $matches['optname'] ?? '';
            }
            $lowerName = strtolower($name);

            // Create a new branch in the radix tree of routes
            if ( ! isset($routes[$lowerName])) {
                if ($isParam) {
                    // Create parameter route
                    $routes[$lowerName] = new RouteParameter(
                        $paramName,
                        optional: ! empty($matches['optname']),
                        default : $matches['default'] ?? null,
                    );
                } else {
                    // Create normal route
                    $routes[$lowerName] = [];
                }
            }
            assert(is_array($routes[$lowerName]) || $routes[$lowerName] instanceof RouteParameter);
            if ($routes[$lowerName] instanceof RouteParameter) {
                // Maybe add validators
                if ($route instanceof Route && isset($route->paramValidators[$paramName])) {
                    $routes[$lowerName]->addValidators(...$route->paramValidators[$paramName]);
                }
                $routes = &$routes[$lowerName]->routes;
            } elseif (is_array($routes[$lowerName])) {
                $routes = &$routes[$lowerName];
            }
        }

        // Create a pre-leaf node in the radix tree for the route for the HTTP method.
        if ( ! isset($routes[$type->value])) {
            $routes[$type->value] = [];
        }

        if (is_array($routes[$type->value])) {
            $routes = &$routes[$type->value];
        } elseif ($routes[$type->value] instanceof RouteParameter) {
            $routes = &$routes[$type->value]->routes;
        }

        /** @phpstan-ignore isset.offset, booleanAnd.alwaysFalse */
        if (isset($routes[self::FIRST_ROUTE_KEY]) && $routes[self::FIRST_ROUTE_KEY] instanceof RouteInterface) {
            if ($routes[self::FIRST_ROUTE_KEY]->compare($route)) {
                return;
            }
            throw new DuplicateRouteException($routes[self::FIRST_ROUTE_KEY], $route);
        }
        $routes[self::FIRST_ROUTE_KEY] = $route;
    }

    /**
     * Get named Route object if it exists
     *
     * @param string $name
     *
     * @return RouteInterface|null
     */
    public function getRouteByName(string $name): ?RouteInterface {
        return self::$namedRoutes[$name] ?? null;
    }

    /**
     * Add a named class
     *
     * @param RouteInterface $route
     *
     * @return void
     */
    public function registerNamed(RouteInterface $route): void {
        self::$namedRoutes[$route->getName()] = $route;
    }

    public function addParameterValidators(Route $route): void {
        if (isset($this->pendingDomainRoutes[spl_object_id($route)])) {
            return;
        }
        // Go through route's path and add validators to all found parameters
        $domain = $route->getDomain();
        $routes = $domain === null ? self::$availableRoutes : (self::$domainRoutes[$domain] ?? []);
        foreach ($route->getPath() as $part) {
            $part = strtolower($part); // Normalize
            assert(is_array($routes));
            /** @var array<string, RouteNode> $routes */
            if ( ! isset($routes[$part])) {
                throw new RuntimeException('Route part was not found. Is the route registered?');
            }

            $next = $routes[$part];

            if ($next instanceof RouteParameter) {
                // Add validators to the parameter
                if (isset($route->paramValidators[$next->name])) {
                    $next->addValidators(...$route->paramValidators[$next->name]);
                }

                $routes = $next->routes;
                continue;
            }

            $routes = $next;
        }
    }

    /**
     * Unregister all routes
     */
    public function unregisterAll(): void {
        self::$availableRoutes = [];
        self::$namedRoutes = [];
        $this->middlewareGroups = [];
        $this->ownedRoutes = [];
        $this->routeGroups = [];
        $this->middlewareGroupsResolved = false;
        self::$domainRoutes = [];
        self::$pendingDomainOwners = [];
        $this->domainAliases = [];
        $this->pendingDomainRoutes = [];
        $this->domainsResolved = false;
    }

    /**
     * @internal
     * @param array<string,RouteNode>      $availableRoutes
     * @param array<string,RouteInterface> $namedRoutes
     * @param list<Route>                  $routes
     * @param array<array-key,array<string,RouteNode>> $domainRoutes
     * @param array<array-key,string> $domainAliases
     */
    public function restoreCompiledRoutes(array $availableRoutes, array $namedRoutes, array $routes, array $domainRoutes = [], array $domainAliases = []): void {
        self::$availableRoutes = $availableRoutes;
        self::$namedRoutes = $namedRoutes;
        $this->middlewareGroups = [];
        $this->routeGroups = [];
        $this->ownedRoutes = [];
        $this->middlewareGroupsResolved = true;
        self::$domainRoutes = $domainRoutes;
        self::$pendingDomainOwners = [];
        $this->domainAliases = $domainAliases;
        $this->pendingDomainRoutes = [];
        $this->domainsResolved = true;
        foreach ($routes as $route) {
            $this->ownedRoutes[spl_object_id($route)] = $route;
            $route->setRouter($this);
        }
    }

    public function unregister(RouteInterface $route): void {
        // Unregister named route
        if (isset(self::$namedRoutes[$route->getName()]) && self::$namedRoutes[$route->getName()] === $route) {
            unset(self::$namedRoutes[$route->getName()]);
        }

        if (isset($this->pendingDomainRoutes[spl_object_id($route)])) {
            unset($this->pendingDomainRoutes[spl_object_id($route)], $this->ownedRoutes[spl_object_id($route)]);
            if ($this->pendingDomainRoutes === []) {
                unset(self::$pendingDomainOwners[spl_object_id($this)]);
            }
            return;
        }
        // Unregister from available routes
        $domain = $route instanceof DomainRouteInterface ? $route->getDomain() : null;
        if ($domain !== null) {
            if ( ! isset(self::$domainRoutes[$domain])) {
                return;
            }
            $routes = &self::$domainRoutes[$domain];
        } else {
            $routes = &self::$availableRoutes;
        }
        foreach ($route->getPath() as $name) {
            $lowerName = strtolower($name);
            if ( ! isset($routes[$lowerName])) {
                return; // Not found
            }
            if ($routes[$lowerName] instanceof RouteParameter) {
                $routes = &$routes[$lowerName]->routes;
            } elseif (is_array($routes[$lowerName])) {
                $routes = &$routes[$lowerName];
            } else {
                return; // Not found
            }
        }

        $type = $route->getMethod();
        if ( ! isset($routes[$type->value])) {
            return; // Not found
        }

        if (is_array($routes[$type->value])) {
            $routes = &$routes[$type->value];
        } elseif ($routes[$type->value] instanceof RouteParameter) {
            $routes = &$routes[$type->value]->routes;
        } else {
            return; // Not found
        }

        /** @phpstan-ignore isset.offset */
        if (isset($routes[self::FIRST_ROUTE_KEY])) {
            $firstRoute = $routes[self::FIRST_ROUTE_KEY];
            if ($firstRoute instanceof RouteInterface && $firstRoute === $route) {
                unset($routes[self::FIRST_ROUTE_KEY], $this->ownedRoutes[spl_object_id($route)]);

            }
        }
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function get(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::GET, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function head(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::HEAD, $pathString, $handler);
    }

    /**
     * @param RequestMethod                                                    $method
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     */
    public function route(RequestMethod $method, string $pathString, callable|array|RouteInterface $handler, ?RouteGroup $group = null): Route {
        if ($handler instanceof RouteInterface) {
            $route = AliasRoute::createAlias($method, $pathString, $handler);
        } else {
            $route = Route::create($method, $pathString, $handler);
        }
        if ($group !== null) {
            $route->setGroup($group);
        }
        $this->register($route);
        return $route;
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function post(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::POST, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function delete(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::DELETE, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function update(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::UPDATE, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function put(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::PUT, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function patch(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::PATCH, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function options(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::OPTIONS, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function connect(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::CONNECT, $pathString, $handler);
    }

    /**
     * @param string                                                           $pathString
     * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
     *
     * @return Route
     * @throws DuplicateRouteException
     */
    public function trace(string $pathString, callable|array|RouteInterface $handler): Route {
        return $this->route(RequestMethod::TRACE, $pathString, $handler);
    }

    public function group(string $path = ''): RouteGroup {
        return new RouteGroup($this, $path);
    }

}
