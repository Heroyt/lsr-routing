<?php
declare(strict_types=1);
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;

class Route implements RouteInterface
{

	/** @var string[] Current URL path as an array (exploded using the "/") */
	protected(set) array $path = [];
	/** @var string URL in a string format */
	protected(set) string $readablePath = '';

	/** @var Middleware[] Route's middleware objects */
	public array                 $middleware = [];
	protected(set) string $routeName    = '';

	/**
	 * @var array<string, RouteInterface>
	 */
	protected(set) array $localizedRoutes = [];

	/**
	 * @var array<non-empty-string,RouteParamValidatorInterface[]>
	 */
	protected(set) array $paramValidators = [];

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
	 * Adds a middleware object to the Route
	 *
	 * @param Middleware ...$middleware
	 */
	public function middleware(Middleware ...$middleware): Route {
		$this->middleware = array_merge($this->middleware, $middleware);
		return $this;
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

	public function setName(string $name): Route {
		$this->routeName = $name;
		return $this;
	}

	public function getReadable(): string {
		return $this->readablePath;
	}

	/**
	 * @param string $locale
	 * @param string $path
	 *
	 * @return $this
	 */
	public function localize(string $locale, string $path): Route {
		assert($this->router !== null);
		$route = LocalizedRoute::createLocalized($this->getMethod(), $path, $locale, $this);
		$route->setRouter($this->router);
		$this->router->register($route);
		$this->localizedRoutes[$locale] = $route;
		return $this;
	}

	public function setRouter(Router $router): Route {
		$this->router = $router;
		return $this;
	}

	/**
	 * Setup a route parameter validator.
	 *
	 * @param non-empty-string             $name
	 * @param RouteParamValidatorInterface ...$validators
	 *
	 * @return $this
	 */
	public function param(string $name, RouteParamValidatorInterface ...$validators): Route {
		$this->paramValidators[$name] = array_merge($this->paramValidators[$name] ?? [], $validators);
		$this->router?->addParameterValidators($this); // Register the validators in the router if it exists
		return $this;
	}
}
