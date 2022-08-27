<?php

namespace Lsr\Core\Routing;

use Lsr\Core\App;
use Lsr\Core\Caching\Cache;
use Lsr\Core\CliController;
use Lsr\Core\Controller;
use Lsr\Core\Routing\Attributes\Cli;
use Lsr\Core\Routing\Attributes\Route as RouteAttribute;
use Lsr\Enums\RequestMethod;
use Lsr\Helpers\Tools\Timer;
use Lsr\Interfaces\ControllerInterface;
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
	/** @var array<string, RouteInterface> Structure holding all set routes */
	public static array $availableRoutes = [];
	/** @var array<string, RouteInterface> Array of named routes with their names as array keys */
	public static array $namedRoutes = [];

	public function __construct(private readonly Cache $cache) {
	}

	/**
	 * Compare 2 different paths
	 *
	 * @param string[]      $path1
	 * @param string[]|null $path2 Defaults to current request path
	 *
	 * @return bool
	 */
	public static function comparePaths(array $path1, ?array $path2 = null) : bool {
		if (!isset($path2)) {
			/** @phpstan-ignore-next-line */
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
	 * @param RequestMethod        $type   [GET, POST, DELETE, PUT]
	 * @param string[]             $path   URL path as an array
	 * @param array<string, mixed> $params URL parameters in a key-value array
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
	 * @throws ReflectionException
	 * @see Route
	 */
	public function setup() : void {
		Timer::start('core.setup.router');
		// Do not cache CLI requests
		if (PHP_SAPI === 'cli') {
			$dep = [];
			$this->loadRoutes($dep);
			return;
		}

		// Cache normal requests
		try {
			/** @phpstan-ignore-next-line */
			[self::$availableRoutes, self::$namedRoutes] = $this->cache->load('routes', [$this, 'loadRoutes']);
		} catch (Throwable $e) {
			if ($e->getMessage() !== 'Serialization of \'Closure\' is not allowed') {
				$dep = [];
				$this->loadRoutes($dep); // Fallback
			}
		}
		Timer::stop('core.setup.router');
	}

	/**
	 * Load defined routes
	 *
	 * @param array<string,mixed> $dependency
	 *
	 * @return RouteInterface[][] [availableRoutes, namedRoutes]
	 * @throws ReflectionException
	 */
	public function loadRoutes(array &$dependency) : array {
		$dependency[Cache::EXPIRE] = '1 days';   // Set expire times
		$routeFiles = glob(ROOT.'routes/*.php'); // Find all config files
		if ($routeFiles === false) {
			$routeFiles = [];
		}

		// Load from controllers
		$controllerFiles = $this->loadRoutesFromControllers();

		$dependency[Cache::FILES] = array_merge($routeFiles, $controllerFiles); // Set expire files
		$dependency[Cache::Tags] = ['core'];

		// Load from files
		foreach ($routeFiles as $file) {
			require $file;
		}

		return [self::$availableRoutes, self::$namedRoutes];
	}

	/**
	 * Recursively scans for controller classes in src/Controllers directory and loads its routes
	 *
	 * @return string[] Found controller files
	 * @throws ReflectionException
	 */
	private function loadRoutesFromControllers() : array {
		$Directory = new RecursiveDirectoryIterator(ROOT.'src/Controllers/');
		$Iterator = new RecursiveIteratorIterator($Directory);
		$Regex = new RegexIterator($Iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

		$files = [];
		/** @var string $classFile */
		foreach ($Regex as [$classFile]) {
			$className = '\App\\'.str_replace([ROOT.'src/', '.php', '/'], ['', '', '\\'], $classFile);
			if (class_exists($className) && (is_subclass_of($className, Controller::class) || is_subclass_of($className, CliController::class))) {
				$files[] = $classFile;
				$this->loadRoutesFromController($className);
			}
		}
		return $files;
	}

	/**
	 * Scan controller's methods using reflection API to find any Route attributes
	 *
	 * @param class-string<ControllerInterface>|ControllerInterface $controller
	 *
	 * @return void
	 * @throws ReflectionException
	 */
	private function loadRoutesFromController(string|ControllerInterface $controller) : void {
		Timer::startIncrementing('core.setup.router.controllers');
		// Initiate reflection class and get methods
		$reflection = new ReflectionClass($controller);
		foreach ($reflection->getMethods() as $method) {
			// Find attributes of type Route
			$attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			foreach ($attributes as $attribute) {
				/** @var RouteAttribute $routeAttr */
				$routeAttr = $attribute->newInstance(); // Must instantiate a new attribute object

				// CLI route must be handled separately
				if ($routeAttr->method === RequestMethod::CLI) {
					$route = CliRoute::cli($routeAttr->path, [$controller, $method->getName()]);
					if ($routeAttr instanceof Cli) {
						// Set additional cli route information
						// Must be set using a special Cli attribute
						$route
							->usage($routeAttr->usage)
							->description($routeAttr->description)
							->addArgument(...$routeAttr->arguments);
					}
					continue;
				}

				// Create normal web route
				$route = Route::create($routeAttr->method, $routeAttr->path, [$controller, $method->getName()]);
				if (!empty($routeAttr->name)) {
					$route->name($routeAttr->name); // Optional argument
				}
			}
		}
		Timer::stop('core.setup.router.controllers');
	}

	/**
	 * Add a new route into availableRoutes array
	 *
	 * @param RouteInterface $route Route object
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