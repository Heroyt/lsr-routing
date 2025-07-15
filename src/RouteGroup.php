<?php

namespace Lsr\Core\Routing;

use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use RuntimeException;

class RouteGroup
{

	/** @var array<string, RouteInterface> */
	protected array           $routes      = [];
	protected ?RouteInterface $activeRoute = null;

	/** @var Middleware[] */
	protected array $middleware = [];
	/** @var array<string, RouteGroup> */
	protected array $groups = [];

	/**
	 * @var array<non-empty-string,RouteParamValidatorInterface[]>
	 */
	public protected(set) array $paramValidators = [];

	public function __construct(
		protected readonly Router $router,
		public readonly string         $path = '',
		protected readonly ?RouteGroup $parent = null,
	) {
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
	public function get(string $path, array|callable|RouteInterface $handler) : static {
		return $this->route(RequestMethod::GET, $path, $handler);
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
	public function route(RequestMethod $method, string $path, array|callable|RouteInterface $handler) : static {
		$route = $this->router->route($method, $this->combinePaths($path), $handler);
		// Add an already added middleware to the route
		$route->middleware(...$this->middleware);
		foreach ($this->paramValidators as $name => $validators) {
			$route->param($name, ...$validators);
		}
		// Save route
		$this->routes[$method->value.':'.$path] = $route;
		// Set route as active
		$this->activeRoute = $route;
		return $this;
	}

	private function combinePaths(string $path) : string {
		return trailingSlashIt($this->path) . ($path !== '' && $path[0] === '/' ? substr($path, 1) : $path);
	}

	/**
	 * Adds a middleware to the last added route all to all routes if no route was created yet
	 *
	 * @param Middleware ...$middleware
	 *
	 * @return $this
	 */
	public function middleware(Middleware ...$middleware) : static {
		if (!isset($this->activeRoute)) {
			return $this->middlewareAll(...$middleware);
		}
		if (method_exists($this->activeRoute, 'middleware')) {
			$this->activeRoute->middleware(...$middleware);
		}
		return $this;
	}

	/**
	 * Add middleware to all group's routes
	 *
	 * @param Middleware ...$middleware
	 *
	 * @return $this
	 */
	public function middlewareAll(Middleware ...$middleware) : static {
		// Add middleware to existing routes
		foreach ($this->routes as $route) {
			if (method_exists($route, 'middleware')) {
				$route->middleware(...$middleware);
			}
		}

		// Add middleware to all child groups
		foreach ($this->groups as $group) {
			$group->middlewareAll(...$middleware);
		}

		// Save middleware into group
		$this->middleware = array_merge($this->middleware, $middleware);

		return $this;
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
	public function post(string $path, array|callable|RouteInterface $handler) : static {
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
	public function delete(string $path, array|callable|RouteInterface $handler) : static {
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
	public function put(string $path, array|callable|RouteInterface $handler) : static {
		return $this->route(RequestMethod::PUT, $path, $handler);
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
	public function update(string $path, array|callable|RouteInterface $handler) : static {
		return $this->route(RequestMethod::PUT, $path, $handler);
	}

	/**
	 * Name the last added route
	 *
	 * @param string $name
	 *
	 * @return $this
	 */
	public function name(string $name) : static {
		if (!isset($this->activeRoute)) {
			throw new RuntimeException('Cannot call RouteGroup::name() without first creating a route in the group.');
		}
		if (method_exists($this->activeRoute, 'name')) {
			$this->activeRoute->name($name);
		}
		return $this;
	}

	public function localize(string $locale, string $path) : static {
		if (!isset($this->activeRoute)) {
			throw new RuntimeException('Cannot call RouteGroup::localize() without first creating a route in the group.');
		}
		if (method_exists($this->activeRoute, 'localize')) {
			$this->activeRoute->localize($locale, $path);
		}
		return $this;

	}

	/**
	 * @return string
	 */
	public function getPath() : string {
		return $this->path;
	}

	/**
	 * Add a new nested group
	 *
	 * @param string $path
	 *
	 * @return RouteGroup
	 */
	public function group(string $path) : RouteGroup {
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
	public function endGroup() : RouteGroup {
		if (!isset($this->parent)) {
			throw new RuntimeException('Cannot end group, because it has no parent.');
		}
		return $this->parent;
	}

	/**
	 * Setup a route parameter validator.
	 *
	 * @param non-empty-string             $name
	 * @param RouteParamValidatorInterface ...$validators
	 *
	 * @return $this
	 */
	public function param(string $name, RouteParamValidatorInterface ...$validators): RouteGroup {
		if (isset($this->activeRoute)) {
			$this->activeRoute->param($name, ...$validators);
			return $this;
		}

		$this->paramAll($name, ...$validators);
		return $this;
	}

	public function paramAll(string $name, RouteParamValidatorInterface ...$validators): RouteGroup {
		$this->paramValidators[$name] = array_merge($this->paramValidators[$name] ?? [], $validators);
		foreach ($this->routes as $route) {
			$route->param($name, ...$validators);
		}
		return $this;
	}

}