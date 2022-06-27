<?php

namespace Lsr\Core\Routing;

use Lsr\Core\App;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Nette\Caching\Cache;

class Router
{
	/** @var RouteInterface[] Structure holding all set routes */
	public static array $availableRoutes = [];
	/** @var array<string, RouteInterface> Array of named routes with their names as array keys */
	public static array $namedRoutes = [];

	public function __construct(private readonly Cache $cache) {
	}

	/**
	 * Compare 2 different paths
	 *
	 * @param array      $path1
	 * @param array|null $path2 Defaults to current request path
	 *
	 * @return bool
	 */
	public static function comparePaths(array $path1, ?array $path2 = null) : bool {
		if (!isset($path2)) {
			$path2 = App::getRequest()->path;
		}
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
	 * @param RequestMethod $type   [GET, POST, DELETE, PUT]
	 * @param array         $path   URL path as an array
	 * @param array         $params URL parameters in a key-value array
	 *
	 * @return RouteInterface|null
	 */
	public static function getRoute(RequestMethod $type, array $path, array &$params = []) : ?RouteInterface {
		$routes = self::$availableRoutes;
		foreach ($path as $value) {
			if (isset($routes[$value])) {
				$routes = $routes[$value];
				continue;
			}

			$paramRoutes = array_filter($routes, static function(string $key) {
				return preg_match('/({[^}]+})/', $key) > 0;
			},                          ARRAY_FILTER_USE_KEY);
			if (count($paramRoutes) === 1) {
				$name = substr(array_keys($paramRoutes)[0], 1, -1);
				$routes = reset($paramRoutes);
				$params[$name] = $value;
				continue;
			}

			return null;
		}
		if (isset($routes[$type->value]) && count($routes[$type->value]) !== 0) {
			return reset($routes[$type->value]);
		}
		return null;
	}

	/**
	 * Include all files from the /routes directory to initialize the Route objects
	 *
	 * Route loading implements cache for faster load times. Cache will expire after 1 day or
	 * when any of the route config files changes.
	 *
	 * @see Route
	 */
	public function setup() : void {
		try {
			[self::$availableRoutes, self::$namedRoutes] = $this->cache->load('routes', [$this, 'loadRoutes']);
		} catch (\Throwable $e) {
			$dep = [];
			$this->loadRoutes($dep); // Fallback
		}
	}

	/**
	 * Load defined routes
	 *
	 * @param array $dependency
	 *
	 * @return array[] [availableRoutes, namedRoutes]
	 */
	public function loadRoutes(array &$dependency) : array {
		$dependencies[Cache::EXPIRE] = '1 days'; // Set expire times
		$routeFiles = glob(ROOT.'routes/*.php'); // Find all config files
		$dependency[Cache::FILES] = $routeFiles; // Set expire files

		foreach ($routeFiles as $file) {
			require $file;
		}

		return [self::$availableRoutes, self::$namedRoutes];
	}

	/**
	 * Add a new route into availableRoutes array
	 *
	 * @param RouteInterface $route Route object
	 *
	 * @noinspection PhpUndefinedFieldInspection
	 */
	public function register(RouteInterface $route) : void {
		$routes = &self::$availableRoutes;
		$type = $route->getMethod();
		foreach ($route->getPath() as $name) {
			$name = strtolower($name);
			if (!isset($routes[$name])) {
				$routes[$name] = [];
			}
			$routes = &$routes[$name];
		}
		if (!isset($routes[$type->value])) {
			$routes[$type->value] = [];
		}
		$routes = &$routes[$type->value];
		$routes[] = $route;
	}

	/**
	 * Add a named class
	 *
	 * @param RouteInterface $route
	 *
	 * @return void
	 */
	public function registerNamed(RouteInterface $route) : void {
		self::$namedRoutes[$route->getName()] = $route;
	}

	/**
	 * Get named Route object if it exists
	 *
	 * @param string $name
	 *
	 * @return RouteInterface|null
	 */
	public function getRouteByName(string $name) : ?RouteInterface {
		return self::$namedRoutes[$name] ?? null;
	}

}