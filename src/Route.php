<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use InvalidArgumentException;
use Lsr\Core\App;
use Lsr\Core\Requests\Request;
use Lsr\Core\Router;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\ControllerInterface;
use Lsr\Interfaces\RequestInterface;
use Lsr\Interfaces\RouteInterface;

class Route implements RouteInterface
{

	/** @var string[] Current URL path as an array (exploded using the "/") */
	public array $path = [];
	/** @var string URL in a string format */
	public string $readablePath = '';

	/** @var callable|array $handler Route callback */
	protected $handler;
	/** @var Middleware[] Route's middleware objects */
	protected array  $middleware = [];
	protected string $routeName  = '';


	/**
	 * Route constructor.
	 *
	 * @param RequestMethod  $type
	 * @param callable|array $handler
	 */
	public function __construct(protected RequestMethod $type, callable|array $handler) {
		$this->handler = $handler;
	}

	/**
	 * Create a new GET route
	 *
	 * @param string         $pathString path
	 * @param callable|array $handler    callback
	 *
	 * @return Route
	 */
	public static function get(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::GET, $pathString, $handler);
	}

	/**
	 * Create a new route
	 *
	 * @param RequestMethod  $type       [GET, POST, DELETE, PUT]
	 * @param string         $pathString Path
	 * @param callable|array $handler    Callback
	 *
	 * @return Route
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
	 * Create a new POST route
	 *
	 * @param string         $pathString
	 * @param callable|array $handler
	 *
	 * @return Route
	 */
	public static function post(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::POST, $pathString, $handler);
	}

	/**
	 * Create a new UPDATE route
	 *
	 * @param string         $pathString
	 * @param callable|array $handler
	 *
	 * @return Route
	 */
	public static function update(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::UPDATE, $pathString, $handler);
	}

	/**
	 * Create a new DELETE route
	 *
	 * @param string         $pathString
	 * @param callable|array $handler
	 *
	 * @return Route
	 */
	public static function delete(string $pathString, callable|array $handler) : Route {
		return self::create(RequestMethod::DELETE, $pathString, $handler);
	}

	/**
	 * Handle a Request - calls any set Middleware and calls a route callback
	 *
	 * @param Request|null $request
	 */
	public function handle(?RequestInterface $request = null) : void {
		if (!isset($request)) {
			throw new InvalidArgumentException('Request cannot be null.');
		}

		// Route-wide middleware
		foreach ($this->middleware as $middleware) {
			$middleware->handle($request);
		}

		if (is_array($this->handler)) {
			if (class_exists($this->handler[0])) {
				[$class, $func] = $this->handler;
				/** @var ControllerInterface $controller */
				$controller = App::getContainer()->getByType($class);

				// Class-wide middleware
				foreach ($controller->middleware as $middleware) {
					$middleware->handle($request);
				}

				$controller->init($request);
				$controller->$func($request);
			}
		}
		else {
			call_user_func($this->handler, $request);
		}
	}

	/**
	 * Adds a middleware object to the Route
	 *
	 * @param Middleware[] $middleware
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
	 */
	public function name(string $name) : Route {
		// Test for duplicate names
		/** @var Router $router */
		$router = App::getService('routing');
		$test = $router->getRouteByName($name);
		if ($test !== null && $test !== $this) {
			throw new InvalidArgumentException('Route of this name already exists. ('.$name.')');
		}

		// Register named route
		$this->routeName = $name;
		$router->registerNamed($this);
		return $this;
	}

	/**
	 * @return array|callable
	 */
	public function getHandler() : callable|array {
		return $this->handler;
	}

	public function getReadable() : string {
		return $this->readablePath;
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
	 * Get route's name
	 *
	 * @return string Can be empty if no name is set
	 */
	public function getName() : string {
		return $this->routeName;
	}

	/**
	 * @return RequestMethod
	 */
	public function getMethod() : RequestMethod {
		return $this->type;
	}
}
