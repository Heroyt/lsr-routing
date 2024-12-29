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

class Router
{
	/** @var array<RouteInterface|array<RouteInterface>> Structure holding all set routes */
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
	 * @param RequestMethod        $type   [GET, POST, DELETE, PUT]
	 * @param string[]             $path   URL path as an array
	 * @param array<string, mixed> $params URL parameters in a key-value array
	 * @param array<RouteInterface|array<RouteInterface>>|null        $routes Available routes that should be processed
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
			$counter++;
			// Check if path key exists and if it does, move into it.
			if (isset($routes[$value]) && is_array($routes[$value])) {
				$routes = $routes[$value];
				continue;
			}

			// Get all parameter parts available for the current path
			$paramRoutes = array_filter(
				$routes,
				static function (string $key) {
					return preg_match('/({[^}]+})\/?/', $key) > 0;
				},
				ARRAY_FILTER_USE_KEY
			);

			// Exactly one available parameter found, set its value and move into it.
			if (count($paramRoutes) === 1) {
				$name = substr(array_keys($paramRoutes)[0], 1, -1); // Remove the {} symbols from the name
				$key = array_key_first($paramRoutes);
				assert(is_array($paramRoutes[$key]));
				$routes = $paramRoutes[$key];                       // Move into the parameter routes
				$params[$name] = $value;                            // Set the parameter value
				continue;
			}

			// More than one parameter found → check all possible paths
			if (count($paramRoutes) > 1) {
				foreach ($paramRoutes as $paramKey => $paramRoute) {
					$name = substr($paramKey, 1, -1);
					assert(is_array($paramRoute));
					$routes = $paramRoute;
					$params[$name] = $value;

					// Recurse
					$route = self::getRoute($type, array_slice($path, $counter), $params, $routes);
					if (isset($route)) { // Found
						return $route;
					}
					// Not found → the parameter was invalid → remove the parameter value and try the next parameter.
					unset($params[$name]);
				}
			}

			// No route found
			return null;
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
		foreach ($route->getPath() as $name) {
			$name = strtolower($name);
			if (!isset($routes[$name])) {
				$routes[$name] = [];
			}
			assert(is_array($routes[$name]));
			$routes = &$routes[$name];
		}
		if (!isset($routes[$type->value])) {
			$routes[$type->value] = [];
		}

		assert(is_array($routes[$type->value]));
		$routes = &$routes[$type->value];
		if (isset($routes[0])) {
			if ($routes[0]->compare($route)) {
				return;
			}
			throw new DuplicateRouteException($routes[0], $route);
		}
		$routes[0] = $route;
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