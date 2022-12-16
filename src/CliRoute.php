<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use Lsr\Core\App;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\ControllerInterface;
use Lsr\Interfaces\RequestInterface;
use Lsr\Interfaces\RouteInterface;

class CliRoute implements RouteInterface
{

	/** @var string[] Current URL path as an array (exploded using the "/") */
	public array $path = [];
	/** @var string Route's usage to print */
	public string $usage = '';
	/** @var string Route's description to print */
	public string $description = '';
	/** @var callable|null Command's help information to print */
	public $helpPrint;
	/** @var array{name:string,isOptional:bool,description:string,suggestions?:string[],template?:string}[] */
	public array $arguments = [];
	/** @var callable|array{0: class-string|object, 1: string} $handler Route callback */
	protected $handler;

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
	 * Create a new GET route
	 *
	 * @param string                                            $pathString path
	 * @param callable|array{0: class-string|object, 1: string} $handler    callback
	 *
	 * @return CliRoute
	 * @throws DuplicateRouteException
	 */
	public static function cli(string $pathString, callable|array $handler) : CliRoute {
		return self::create(RequestMethod::CLI, $pathString, $handler);
	}

	/**
	 * Create a new route
	 *
	 * @param RequestMethod                                     $type       [GET, POST, DELETE, PUT]
	 * @param string                                            $pathString Path
	 * @param callable|array{0: class-string|object, 1: string} $handler    Callback
	 *
	 * @return CliRoute
	 * @throws DuplicateRouteException
	 */
	public static function create(RequestMethod $type, string $pathString, callable|array $handler) : CliRoute {
		$route = new self($type, $handler);
		$route->path = array_filter(explode('/', $pathString), 'not_empty');

		// Register route
		/** @var Router $router */
		$router = App::getService('routing');
		$router->register($route);

		return $route;
	}

	/**
	 * Handle a Request - calls any set Middleware and calls a route callback
	 *
	 * @param RequestInterface $request
	 */
	public function handle(RequestInterface $request) : void {
		if (is_array($this->handler)) {
			if (is_object($this->handler[0]) || class_exists($this->handler[0])) {
				[$class, $func] = $this->handler;
				/** @var ControllerInterface $controller */
				$controller = is_object($class) ? $class : App::getContainer()->getByType($class);

				if (method_exists($controller, 'init')) {
					$controller->init($request);
				}
				$controller->$func($request);
			}
		}
		else {
			call_user_func($this->handler, $request);
		}
	}


	/**
	 * @return array{0: class-string|object, 1: string}|callable
	 */
	public function getHandler() : callable|array {
		return $this->handler;
	}

	/**
	 * @param string $usage
	 *
	 * @return CliRoute
	 */
	public function usage(string $usage) : CliRoute {
		$this->usage = $usage;
		return $this;
	}

	/**
	 * @param string $description
	 *
	 * @return CliRoute
	 */
	public function description(string $description) : CliRoute {
		$this->description = $description;
		return $this;
	}

	/**
	 * @param callable $help
	 *
	 * @return CliRoute
	 */
	public function help(callable $help) : CliRoute {
		$this->helpPrint = $help;
		return $this;
	}

	/**
	 * @param array{name:string,isOptional:bool,description:string,suggestions?:string[],template?:string} ...$argument
	 *
	 * @return $this
	 */
	public function addArgument(array ...$argument) : CliRoute {
		foreach ($argument as $arg) {
			$this->arguments[] = $arg;
		}
		return $this;
	}

	public function getReadable() : string {
		return empty($this->usage) ? implode('/', $this->path) : $this->usage;
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
		return '';
	}

	/**
	 * Get route's request method
	 *
	 * @return RequestMethod
	 */
	public function getMethod() : RequestMethod {
		return $this->type;
	}

	public function compare(RouteInterface $route) : bool {
		return
			$this->getMethod() === $route->getMethod() &&
			static::compareRoutePaths($this->getPath(), $route->getPath()) &&
			self::compareHandlers($this->getHandler(), $route->getHandler());
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
}
