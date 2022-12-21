<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use Lsr\Core\App;
use Lsr\Core\Caching\Cache;
use Lsr\Core\Exceptions\ModelNotFoundException;
use Lsr\Core\Models\Model;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Enums\RequestMethod;
use Lsr\Helpers\Tools\Strings;
use Lsr\Interfaces\ControllerInterface;
use Lsr\Interfaces\RequestInterface;
use Lsr\Interfaces\RouteInterface;
use Nette\Caching\Cache as CacheParent;
use Nette\DI\MissingServiceException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use RuntimeException;
use Throwable;

class Route implements RouteInterface
{

	/** @var string[] Current URL path as an array (exploded using the "/") */
	public array $path = [];
	/** @var string URL in a string format */
	public string $readablePath = '';

	/** @var callable|array{0: class-string|object, 1: string} $handler Route callback */
	protected $handler;
	/** @var Middleware[] Route's middleware objects */
	protected array  $middleware = [];
	protected string $routeName  = '';


	/**
	 * Route constructor.
	 *
	 * @param RequestMethod                                     $type
	 * @param callable|array{0: class-string|object, 1: string} $handler
	 */
	public function __construct(protected RequestMethod $type, callable|array $handler) {
		$this->handler = $handler;
	}

	/**
	 * Create a new POST route
	 *
	 * @param string                                            $pathString
	 * @param callable|array{0: class-string|object, 1: string} $handler
	 *
	 * @return Route
	 * @throws DuplicateRouteException
	 */
	public static function post(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::POST, $pathString, $handler);
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
	public static function create(RequestMethod $type, string $pathString, callable|array $handler) : Route {
		$route = new self($type, $handler);
		$route->path = array_filter(explode('/', $pathString), 'not_empty');
		$route->readablePath = $pathString;

		// Register route
		/** @var Router $router */
		$router = App::getService('routing');
		$router->register($route);

		return $route;
	}

	/**
	 * Create a new UPDATE route
	 *
	 * @param string                                            $pathString
	 * @param callable|array{0: class-string|object, 1: string} $handler
	 *
	 * @return Route
	 * @throws DuplicateRouteException
	 */
	public static function update(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::PUT, $pathString, $handler);
	}

	/**
	 * Create a new PUT route
	 *
	 * @param string                                            $pathString
	 * @param callable|array{0: class-string|object, 1: string} $handler
	 *
	 * @return Route
	 * @throws DuplicateRouteException
	 */
	public static function put(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::PUT, $pathString, $handler);
	}

	/**
	 * Create a new DELETE route
	 *
	 * @param string                                            $pathString
	 * @param callable|array{0: class-string|object, 1: string} $handler
	 *
	 * @return Route
	 * @throws DuplicateRouteException
	 */
	public static function delete(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::DELETE, $pathString, $handler);
	}

	/**
	 * Handle a Request - calls any set Middleware and calls a route callback
	 *
	 * @param RequestInterface $request
	 *
	 * @throws Throwable
	 */
	public function handle(RequestInterface $request) : void {
		// Route-wide middleware
		foreach ($this->middleware as $middleware) {
			$middleware->handle($request);
		}

		if (is_array($this->handler)) {
			if (is_object($this->handler[0]) || class_exists($this->handler[0])) {
				[$class, $func] = $this->handler;
				/** @var ControllerInterface $controller */
				$controller = is_object($class) ? $class : App::getContainer()->getByType($class);

				// Class-wide middleware
				if (isset($controller->middleware)) {
					/** @var Middleware $middleware */
					foreach ($controller->middleware as $middleware) {
						$middleware->handle($request);
					}
				}

				if (method_exists($controller, 'init')) {
					$controller->init($request);
				}
				$args = $this->getHandlerArgs($request);
				$controller->$func(...$args);
			}
		}
		else {
			call_user_func($this->handler, $request);
		}
	}

	/**
	 * @param RequestInterface $request
	 *
	 * @return array<string, int|string|float|bool|RequestInterface|Model>
	 * @throws Throwable
	 */
	private function getHandlerArgs(RequestInterface $request) : array {
		/** @var Cache $cache */
		$cache = App::getService('cache');
		/** @var array<string,array{optional:bool,type:string|class-string,nullable:bool}> $args */
		$args = $cache->load('route.'.$this->type->value.'.'.$this->readablePath.'.args', function(array &$dependency) {
			$dependency[CacheParent::EXPIRE] = '1 days';   // Set expire times
			$dependency[CacheParent::Tags] = ['routes', 'core'];

			$reflection = new ReflectionMethod(...$this->handler);
			$arguments = $reflection->getParameters();
			$args = [];
			foreach ($arguments as $argument) {
				$name = $argument->getName();
				$optional = $argument->isOptional();

				/** @var ReflectionType|null $type */
				$type = $argument->getType();

				if (!$type instanceof ReflectionNamedType) {
					throw new RunTimeException('Unsupported route handler method type in '.implode('::', $this->handler).'(). Only built-in types, RequestInterface and Model classes are supported.');
				}

				$args[$name] = [
					'optional' => $optional,
					'type'     => $type->getName(),
					'nullable' => $type->allowsNull(),
				];
			}
			return $args;
		});

		$argsValues = [];

		foreach ($args as $name => $type) {
			if (class_exists($type['type'])) {
				// Check for request
				$implements = class_implements($type['type']);
				if ($type['type'] === RequestInterface::class || isset($implements[RequestInterface::class])) {
					$argsValues[$name] = $request;
					continue;
				}

				// Check for model
				if (is_subclass_of($type['type'], Model::class)) {
					// Find ID
					$paramName = Strings::toCamelCase($name.'_id');
					$id = $request->getParam($paramName);
					if (!isset($id)) {
						$id = $request->getParam(strtolower($paramName));
					}
					if (!isset($id)) {
						$id = $request->getParam('id');
					}
					if (!isset($id)) {
						if ($type['optional']) {
							continue;
						}
						throw new RuntimeException('Cannot instantiate Model for route. No ID route parameter. '.$this->readablePath.' - argument: '.$type['type'].' $'.$name.'. Expecting parameter "id" or "'.$paramName.'".');
					}
					try {
						$model = $type['type']::get((int) $id);
					} catch (ModelNotFoundException $e) {
						if (!$type['nullable']) {
							// TODO: Handle 404 error
							throw new RuntimeException('Cannot instantiate Model for route. Model not found. '.$this->readablePath.' - argument: '.$type['type'].' $'.$name.'.', previous: $e);
						}
						$model = null;
					}
					$argsValues[$name] = $model;
					continue;
				}

				// Try to get class from DI
				try {
					$class = App::getContainer()->getByType($type['type']);
				} catch (MissingServiceException $e) {
					if (!$type['nullable']) {
						throw $e;
					}
					$class = null;
				}
				$argsValues[$name] = $class;
				continue;
			}

			// Basic types
			$argsValues[$name] = match ($type['type']) {
				'string' => (string) $request->getParam($name),
				'integer', 'int' => (int) $request->getParam($name),
				'double', 'float' => (float) $request->getParam($name),
				'boolean', 'bool' => (bool) $request->getParam($name),
				default => throw new RunTimeException('Unsupported route handler method type in '.implode('::', $this->handler).'('.$type['type'].' $'.$name.'). Only built-in types, RequestInterface and Model classes are supported.'),
			};
		}

		return $argsValues;
	}

	/**
	 * Get route's name
	 *
	 * @return string Can be empty if no name is set
	 */
	public function getName() : string {
		return $this->routeName;
	}

	/**
	 * Create a new GET route
	 *
	 * @param string                                            $pathString path
	 * @param callable|array{0: class-string|object, 1: string} $handler    callback
	 *
	 * @return Route
	 * @throws DuplicateRouteException
	 */
	public static function get(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::GET, $pathString, $handler);
	}

	/**
	 * Adds a middleware object to the Route
	 *
	 * @param Middleware ...$middleware
	 */
	public function middleware(Middleware ...$middleware) : Route {
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
	public function name(string $name) : Route {
		// Test for duplicate names
		/** @var Router $router */
		$router = App::getService('routing');
		$test = $router->getRouteByName($name);
		if ($test !== null && !$this->compare($test)) {
			throw new DuplicateNamedRouteException($test, $this);
		}

		// Register named route
		$this->routeName = $name;
		$router->registerNamed($this);
		return $this;
	}

	public function compare(RouteInterface $route) : bool {
		return
			$this->getMethod() === $route->getMethod() &&
			static::compareRoutePaths($this->getPath(), $route->getPath()) &&
			self::compareHandlers($this->getHandler(), $route->getHandler());
	}

	/**
	 * @return RequestMethod
	 */
	public function getMethod() : RequestMethod {
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
	public static function compareRoutePaths(array $path1, array $path2) : bool {
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
	public function getPath() : array {
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
	public static function compareHandlers(array|callable $handler1, array|callable $handler2) : bool {
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
	public function getHandler() : callable|array {
		return $this->handler;
	}

	public function getReadable() : string {
		return $this->readablePath;
	}

	public static function group(string $path = '') : RouteGroup {
		return new RouteGroup($path);
	}
}
