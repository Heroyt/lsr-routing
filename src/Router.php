<?php

namespace Lsr\Core\Routing;

use Lsr\Caching\Cache;
use Lsr\Core\Routing\Attributes\Route as RouteAttribute;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Exceptions\MethodNotAllowedException;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use RegexIterator;
use Throwable;

/**
 * @phpstan-type RouteNode RouteInterface|RouteParameter|array<string, RouteNode>
 */
class Router
{
	protected const string PARAM_REGEX          = '({(?P<name>[^}]+)})\/?';
	protected const string OPTIONAL_PARAM_REGEX = '(\[(?P<optname>[^[\]=]+)(?:=(?P<default>[^[\]]*))?])';

	protected const string ANY_PARAM_REGEX = '(?:' . self::PARAM_REGEX . ')|(?:' . self::OPTIONAL_PARAM_REGEX . ')';
	/** @var array<string, RouteNode> Structure holding all set routes */
	public static array $availableRoutes = [];
	/** @var array<string, RouteInterface> Array of named routes with their names as array keys */
	public static array $namedRoutes = [];

	/**
	 * @param Cache    $cache
	 * @param string[] $routeFiles
	 * @param string[] $controllers
	 *
	 * @codeCoverageIgnore
	 */
	public function __construct(
		private readonly Cache $cache,
		private readonly array $routeFiles = [],
		private readonly array $controllers = [],
	) {
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
			if (!is_numeric($key)) {
				unset($path1[$key]);
			}
			else {
				$path1[$key] = strtolower($value);
			}
		}
		foreach ($path2 as $key => $value) {
			if (!is_numeric($key)) {
				unset($path2[$key]);
			}
			else {
				$path2[$key] = strtolower($value);
			}
		}
		return $path1 === $path2;
	}

	/**
	 * Get set route if it exists
	 *
	 * @param RequestMethod                                    $type   [GET, POST, DELETE, PUT]
	 * @param string[]                                         $path   URL path as an array
	 * @param array<string, mixed>                             $params URL parameters in a key-value array
	 * @param array<RouteInterface|array<RouteInterface>>|null $routes Available routes that should be processed
	 *
	 * @return RouteInterface|null
	 *
	 * @throws MethodNotAllowedException
	 */
	public static function getRoute(RequestMethod $type, array $path, array &$params = [], ?array $routes = null): ?RouteInterface {
		if (!isset($routes)) {
			$routes = self::$availableRoutes; // Default routes value
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
				static fn($node) => $node instanceof RouteParameter,

			);
			$paramRouteCount = count($paramRoutes);

			// Exactly one available parameter found
			if ($paramRouteCount === 1) {
				$key = array_key_first($paramRoutes);
				$paramRoute = $paramRoutes[$key];
				assert($paramRoute instanceof RouteParameter);

				// Validate the parameter value - if invalid, treat as not found.
				if (!$paramRoute->validate($value)) {
					// No route found
					return null;
				}

				$name = $paramRoute->name;

				if ($paramRoute->optional) {
					$route = self::tryOptionalParam($type, $path, $params, $paramRoute, $value, $counter);
					if (isset($route)) { // Found
						return $route;
					}
					continue;
				}

				// Required parameter - set value and move into it.
				$routes = $paramRoute->routes;                       // Move into the parameter routes
				$params[$name] = $value;                            // Set the parameter value
				continue;
			}

			// More than one parameter found → check all possible paths
			if ($paramRouteCount > 1) {
				foreach ($paramRoutes as $paramRoute) {
					assert($paramRoute instanceof RouteParameter);

					if (!$paramRoute->validate($value)) {
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
					$route = self::getRoute($type, array_slice($path, $counter), $params, $routes);
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

		// Check optional parameters at the end of the path
		$optionalParams = array_filter(
			$routes,
			static fn($node) => $node instanceof RouteParameter && $node->optional,
		);
		foreach ($optionalParams as $paramRoutes) {
			assert($paramRoutes instanceof RouteParameter);
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
			return reset($routes[$type->value]);
		}

		// Route exists, but the method for this route doesn't
		throw new MethodNotAllowedException(
			'Method ' . $type->value . ' is not allowed for path /' . implode('/', $path)
		);
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

		if (!empty($routeParam->default)) {
			$params[$name] = $routeParam->default;
		}
		try {
			$route = self::getRoute($type, $path, $params, $routeParam->routes);
			if (isset($route)) {
				return $route;
			}
		} catch (MethodNotAllowedException) {
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


		if (isset($matches[2])) {
			$params[$name] = $matches[2];
		}
		try {
			$route = self::getRoute($type, array_slice($path, 1), $params, $routeParam->routes);
			if (isset($route)) {
				return $route;
			}
		} catch (MethodNotAllowedException) {
		}
		unset($params[$name]);
		return null;
	}

	/**
	 * Include all files from the /routes directory to initialize the Route objects
	 *
	 * Route loading implements cache for faster load times. Cache will expire after 1 day or
	 * when any of the route config files changes.
	 *
	 * @throws ReflectionException
	 * @see Route
	 * @codeCoverageIgnore
	 */
	public function setup(): void {
		// Cache requests
		try {
			[self::$availableRoutes, self::$namedRoutes] = $this->cache
				->load(
					'routes',
					[$this, 'loadRoutes'],
					[
						Cache::Expire => '30 days',
						Cache::Tags   => ['core', 'routes'],
					]
				);
		} catch (Throwable $e) {
			if ($e->getMessage() !== 'Serialization of \'Closure\' is not allowed') {
				$this->loadRoutes(); // Fallback
			}
		}
	}

	/**
	 * Load defined routes
	 *
	 *
	 * @return array{0:array<RouteInterface|array<RouteInterface>>,1:array<string,RouteInterface>} [availableRoutes, namedRoutes]
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
			}
			else if (file_exists($file)) {
				$routeFiles[] = [$file];
			}
		}
		$routeFiles = array_merge(...$routeFiles);

		// Load from controllers
		$controllerFiles = [];
		foreach ($this->controllers as $file) {
			if (is_dir($file)) {
				$this->loadRoutesFromControllersDir($file, $controllerFiles);
			}
			else if (file_exists($file)) {
				$this->loadRoutesFromControllerFile($file, $controllerFiles);
			}
		}

		// Load from files
		foreach ($routeFiles as $file) {
			require $file;
		}

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

		/** @var string $classFile */
		foreach ($Regex as [$classFile]) {
			$this->loadRoutesFromControllerFile($classFile, $files);
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
				if (!empty($read[0])) {
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
		foreach ($reflection->getMethods() as $method) {
			// Find attributes of type Route
			$attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			foreach ($attributes as $attribute) {
				/** @var RouteAttribute $routeAttr */
				$routeAttr = $attribute->newInstance(); // Must instantiate a new attribute object

				// Create normal web route
				$route = Route::create($routeAttr->method, $routeAttr->path, [$controller, $method->getName()]);
				$this->register($route);
				if (!empty($routeAttr->name)) {
					$test = $this->getRouteByName($routeAttr->name);
					if ($test !== null && !$route->compare($test)) {
						throw new DuplicateNamedRouteException($test, $route);
					}
					$this->registerNamed($route);
					$route->setName($routeAttr->name); // Optional argument
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
		$routes = &self::$availableRoutes;
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
			if (!isset($routes[$lowerName])) {
				if ($isParam) {
					// Create parameter route
					$routes[$lowerName] = new RouteParameter(
						          $paramName,
						optional: !empty($matches['optname']),
						default : $matches['default'] ?? null,
					);
				}
				else {
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
			}
			elseif (is_array($routes[$lowerName])) {
				$routes = &$routes[$lowerName];
			}
		}

		// Create a pre-leaf node in the radix tree for the route for the HTTP method.
		if (!isset($routes[$type->value])) {
			$routes[$type->value] = [];
		}

		if (is_array($routes[$type->value])) {
			$routes = &$routes[$type->value];
		}
		elseif ($routes[$type->value] instanceof RouteParameter) {
			$routes = &$routes[$type->value]->routes;
		}

		if (isset($routes[0]) && $routes[0] instanceof RouteInterface) {
			if ($routes[0]->compare($route)) {
				return;
			}
			throw new DuplicateRouteException($routes[0], $route);
		}
		$routes[0] = $route;
	}

	public function addParameterValidators(Route $route): void {
		// Go through route's path and add validators to all found parameters
		$routes = self::$availableRoutes;
		foreach ($route->getPath() as $part) {
			if (!isset($routes[$part])) {
				throw new \RuntimeException('Route part was not found. Is the route registered?');
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

	/**
	 * Unregister all routes
	 */
	public function unregisterAll(): void {
		self::$availableRoutes = [];
		self::$namedRoutes = [];
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
	 * @param RequestMethod                                                    $method
	 * @param string                                                           $pathString
	 * @param callable|array{0: class-string|object, 1: string}|RouteInterface $handler
	 *
	 * @return Route
	 */
	public function route(RequestMethod $method, string $pathString, callable|array|RouteInterface $handler): Route {
		if ($handler instanceof RouteInterface) {
			$route = AliasRoute::createAlias($method, $pathString, $handler);
		}
		else {
			$route = Route::create($method, $pathString, $handler);
		}
		$route->setRouter($this);
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

	public function group(string $path = ''): RouteGroup {
		return new RouteGroup($this, $path);
	}

}