<?php
declare(strict_types=1);
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateLocalizedRouteException;
use Lsr\Core\Routing\Exceptions\InvalidLocalizedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Interfaces\LocalizableRouteInterface;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Server\MiddlewareInterface;

class Route implements LocalizableRouteInterface
{

	/** @var string[] Current URL path as an array (exploded using the "/") */
	protected(set) array $path = [];
	/** @var string URL in a string format */
	protected(set) string $readablePath = '';

	/** @var list<MiddlewareInterface> */
	private array $middleware = [];
	/** @var list<MiddlewareInterface|string|ServiceReference> */
	private array $middlewareDefinitions = [];
	/** @var list<MiddlewareInterface|ServiceReference> */
	private array $resolvedMiddlewareDefinitions = [];
	protected(set) string $routeName    = '';

	/**
	 * @var array<string, RouteInterface>
	 */
	protected(set) array $localizedRoutes = [];
	protected(set) ?string $locale = null;

	/**
	 * @var array<non-empty-string,RouteParamValidatorInterface[]>
	 */
	protected(set) array $paramValidators = [];

	/**
	 * @var array<non-empty-string,list<RouteParamValidatorInterface|ServiceReference>>
	 */
	private array $paramValidatorDefinitions = [];

	protected ?Router $router = null;

	/**
	 * @var callable-string|array{0: class-string|object, 1: string}|SerializableClosure
	 */
	protected(set) string|array|SerializableClosure $handler;


	/**
	 * Route constructor.
	 *
	 * @param RequestMethod $type
	 * @param callable-string|array{0: class-string|object, 1: string}|Closure $handler
	 */
	public function __construct(
		protected(set) RequestMethod $type,
		string|array|Closure $handler,
	) {
		if ($handler instanceof Closure) {
			$this->handler = new SerializableClosure($handler);
		}
		else {
			$this->handler = $handler;
		}
	}

	/**
	 * Create a new route
	 *
	 * @param RequestMethod                                     $type       [GET, POST, DELETE, PUT]
	 * @param string                                            $pathString Path
	 * @param callable|array{0: class-string|object, 1: string} $handler    Callback
	 *
	 * @return Route
	 * @throws DuplicateRouteException
	 */
	public static function create(RequestMethod $type, string $pathString, callable|array $handler): Route {
		/** @phpstan-ignore argument.type */
		$route = new self($type, $handler);
		$route->path = array_filter(explode('/', $pathString), static fn(string $part) => !empty($part));
		$route->readablePath = $pathString;
		return $route;
	}

	/**
	 * Get route's name
	 *
	 * @return string Can be empty if no name is set
	 */
	public function getName(): string {
		return $this->routeName;
	}

	/**
	 * Add middleware instances, middleware group names, or DI service references.
	 */
	public function middleware(MiddlewareInterface|string|ServiceReference ...$middleware): Route
	{
		foreach ($middleware as $entry) {
			if (is_string($entry)) {
				$this->router?->assertMiddlewareGroupReferenceAllowed();
			}
			$this->middlewareDefinitions[] = $entry;
			if ($entry instanceof MiddlewareInterface && !in_array($entry, $this->middleware, true)) {
				$this->middleware[] = $entry;
			}
		}
		if ($this->router?->areMiddlewareGroupsResolved()) {
			$this->router->materializeRouteDependencies($this);
		}
		return $this;
	}

	/**
	 * @return list<MiddlewareInterface>
	 */
	public function getMiddleware(): array
	{
		return $this->middleware;
	}

	/**
	 * @internal
	 * @return list<MiddlewareInterface|string|ServiceReference>
	 */
	public function getMiddlewareDefinitions(): array
	{
		return $this->middlewareDefinitions;
	}

	/**
	 * @internal
	 * @return list<MiddlewareInterface|ServiceReference>
	 */
	public function getResolvedMiddlewareDefinitions(): array
	{
		return $this->resolvedMiddlewareDefinitions;
	}

	/**
	 * @internal
	 * @param list<MiddlewareInterface|ServiceReference> $definitions
	 * @param list<MiddlewareInterface>                  $middleware
	 */
	public function replaceMiddleware(array $definitions, array $middleware): void
	{
		$this->resolvedMiddlewareDefinitions = $definitions;
		$this->middleware = $middleware;
	}

	/**
	 * Names a route
	 *
	 * @param string $name
	 *
	 * @return $this
	 * @throws DuplicateNamedRouteException
	 */
	public function name(string $name): Route {
		$this->routeName = $name;

		if ($this->router !== null) {
			// Test for duplicate names
			$test = $this->router->getRouteByName($name);
			if ($test !== null && !$this->compare($test)) {
				throw new DuplicateNamedRouteException($test, $this);
			}

			// Register named route
			$this->router->registerNamed($this);
		}
		return $this;
	}

	public function compare(RouteInterface $route): bool {
		return
			!($route instanceof LocalizedRoute) &&
			$this->getMethod() === $route->getMethod() &&
			static::compareRoutePaths($this->getPath(), $route->getPath()) &&
			self::compareHandlers($this->getHandler(), $route->getHandler());
	}

	/**
	 * @return RequestMethod
	 */
	public function getMethod(): RequestMethod {
		return $this->type;
	}

	/**
	 * Compare two route paths.
	 *
	 * Ignores different parameter names, but checks if both paths contain parameter at the same place.
	 *
	 * @param string[] $path1
	 * @param string[] $path2
	 *
	 * @return bool True if the paths match.
	 */
	public static function compareRoutePaths(array $path1, array $path2): bool {
		if (count($path1) !== count($path2)) {
			return false;
		}
		foreach ($path1 as $key => $part) {
			// Test if part is parameter
			if (preg_match('/({[^}]+})/', $part)) {
				if (!preg_match('/({[^}]+})/', $path2[$key])) {
					return false;
				}
				continue;
			}
			if ($part !== $path2[$key]) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Get split route path
	 *
	 * @return string[]
	 */
	public function getPath(): array {
		return $this->path;
	}

	/**
	 * Compare two route handlers.
	 *
	 * @param array{0:class-string|object, 1: string}|callable $handler1
	 * @param array{0:class-string|object, 1: string}|callable $handler2
	 *
	 * @return bool
	 */
	public static function compareHandlers(array|callable $handler1, array|callable $handler2): bool {
		if (is_array($handler1) && is_array($handler2)) {
			if (is_object($handler1[0])) {
				$handler1[0] = $handler1[0]::class;
			}
			if (is_object($handler2[0])) {
				$handler2[0] = $handler2[0]::class;
			}
			return $handler1[0] === $handler2[0] && $handler1[1] === $handler2[1];
		}
		return $handler1 === $handler2;
	}

	/**
	 * @return array{0: class-string|object, 1: string}|callable
	 */
	public function getHandler(): callable|array {
		if ($this->handler instanceof SerializableClosure) {
			return $this->handler->getClosure();
		}
		return $this->handler;
	}

	/**
	 * @internal
	 * @return callable-string|array{0: class-string|object, 1: string}|SerializableClosure
	 */
	public function getCacheHandler(): string|array|SerializableClosure
	{
		return $this->handler;
	}

	public function setName(string $name): Route {
		$this->routeName = $name;
		return $this;
	}

	public function getReadable(): string {
		return $this->readablePath;
	}

	/**
	 * Register a canonical path for a locale.
	 *
	 * When no path is given, the route's existing path becomes the canonical
	 * path for the locale.
	 */
	public function localize(string $locale, ?string $path = null): static {
		$locale = trim($locale);
		if ($locale === '') {
			throw new InvalidLocalizedRouteException('A localized route locale must not be empty.');
		}

		$this->assertLocaleParameterAvailable();

		if ($path === null) {
			if ($this->locale !== null || isset($this->localizedRoutes[$locale])) {
				throw new DuplicateLocalizedRouteException($this, $locale);
			}
			$this->locale = $locale;
			return $this;
		}

		if ($this->locale === $locale || isset($this->localizedRoutes[$locale])) {
			throw new DuplicateLocalizedRouteException($this, $locale);
		}
		$this->assertCompatibleLocalizedPath($path);

		assert($this->router !== null);
		$route = LocalizedRoute::createLocalized($this->getMethod(), $path, $locale, $this);
		$route->paramValidators = $this->paramValidators;
		$route->paramValidatorDefinitions = $this->paramValidatorDefinitions;
		$route->setRouter($this->router);
		$this->router->register($route);
		$this->localizedRoutes[$locale] = $route;
		return $this;
	}

	/**
	 * @param string[] $path
	 *
	 * @return list<string>
	 */
	private static function getParameterContract(array $path): array {
		$contract = [];
		foreach ($path as $part) {
			preg_match_all(
				'/\\{([^}]+)}|\\[([^\\]=]+)(?:=[^\\]]*)?]/',
				$part,
				$matches,
				PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
			);
			foreach ($matches as $match) {
				$required = $match[1] ?? null;
				$optional = $match[2] ?? null;
				if (is_string($required)) {
					$contract[] = 'required:' . $required;
				}
				else if (is_string($optional)) {
					$contract[] = 'optional:' . $optional;
				}
			}
		}
		sort($contract);
		return $contract;
	}

	private function assertLocaleParameterAvailable(): void {
		$contract = self::getParameterContract($this->path);
		if (!in_array('required:lang', $contract, true) && !in_array('optional:lang', $contract, true)) {
			return;
		}
		throw new InvalidLocalizedRouteException(
			sprintf('Route "%s" cannot be localized because "lang" is already a path parameter.', $this->getReadable())
		);
	}

	private function assertCompatibleLocalizedPath(string $path): void {
		$localizedPath = array_values(array_filter(explode('/', $path), 'not_empty'));
		if (self::getParameterContract($this->path) === self::getParameterContract($localizedPath)) {
			return;
		}
		throw new InvalidLocalizedRouteException(
			sprintf(
				'Localized path "%s" must use the same parameters as route "%s".',
				$path,
				$this->getReadable(),
			)
		);
	}

	/**
	 * Register a legacy path that permanently redirects to this route.
	 *
	 * When a locale is given, the alias targets that exact localized path.
	 */
	public function redirectFrom(string $path, ?string $locale = null): static {
		assert($this->router !== null);
		$redirectTo = $this;
		if ($locale !== null) {
			$redirectTo = $this->getRouteForLocale($locale);
			if ($redirectTo === null) {
				throw new \RuntimeException(
					sprintf('Route "%s" has no path for locale "%s".', $this->getName(), $locale)
				);
			}
		}

		$aliasPath = array_values(array_filter(explode('/', $path), 'not_empty'));
		if (self::getParameterContract($aliasPath) !== self::getParameterContract($redirectTo->getPath())) {
			throw new InvalidLocalizedRouteException(
				sprintf(
					'Legacy alias "%s" must use the same parameters as route "%s".',
					$path,
					$redirectTo->getReadable(),
				)
			);
		}
		$alias = AliasRoute::createAlias($this->getMethod(), $path, $redirectTo);
		$alias->setRouter($this->router);
		$this->router->register($alias);
		return $this;
	}

	public function getLocale(): ?string {
		return $this->locale;
	}

	public function getRouteForLocale(string $locale): ?RouteInterface {
		if ($this->locale === $locale) {
			return $this;
		}
		return $this->localizedRoutes[$locale] ?? null;
	}

	public function getCanonicalRoute(): RouteInterface {
		return $this;
	}

	public function hasLocalizedRoutes(): bool {
		return $this->locale !== null || $this->localizedRoutes !== [];
	}

	public function setRouter(Router $router): Route {
		$this->router = $router;
		if ($router->areMiddlewareGroupsResolved()) {
			$router->materializeRouteDependencies($this);
		}
		return $this;
	}

	/**
	 * Setup a route parameter validator.
	 *
	 * @param non-empty-string $name
	 */
	public function param(
		string $name,
		RouteParamValidatorInterface|ServiceReference ...$validators,
	): static {
		foreach ($validators as $validator) {
			$this->paramValidatorDefinitions[$name][] = $validator;
			if (
				$validator instanceof RouteParamValidatorInterface
				&& !in_array($validator, $this->paramValidators[$name] ?? [], true)
			) {
				$this->paramValidators[$name][] = $validator;
			}
		}
		if ($this->router?->areMiddlewareGroupsResolved()) {
			$this->router->materializeRouteDependencies($this);
		}
		else {
			$this->router?->addParameterValidators($this);
		}
		foreach ($this->localizedRoutes as $localizedRoute) {
			if ($localizedRoute instanceof Route) {
				$localizedRoute->param($name, ...$validators);
			}
		}
		return $this;
	}

	/**
	 * @internal
	 * @return array<non-empty-string,list<RouteParamValidatorInterface|ServiceReference>>
	 */
	public function getParamValidatorDefinitions(): array
	{
		return $this->paramValidatorDefinitions;
	}

	/**
	 * @internal
	 * @param array<non-empty-string,list<RouteParamValidatorInterface|ServiceReference>> $definitions
	 * @param array<non-empty-string,list<RouteParamValidatorInterface>>                  $validators
	 */
	public function replaceParamValidators(array $definitions, array $validators): void
	{
		$this->paramValidatorDefinitions = $definitions;
		$this->paramValidators = $validators;
	}

	/**
	 * @internal
	 * @param array<string, RouteInterface> $localizedRoutes
	 */
	public function restoreLocalization(?string $locale, array $localizedRoutes): void
	{
		$this->locale = $locale;
		$this->localizedRoutes = $localizedRoutes;
	}
}
