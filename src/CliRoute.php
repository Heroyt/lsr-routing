<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use Lsr\Core\App;
use Lsr\Core\Requests\Request;
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
	public $helpPrint = null;
	/** @var array{name:string,isOptional:bool,description:string,suggestions:array}[] */
	public array $arguments = [];
	/** @var callable|array $handler Route callback */
	protected $handler;

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
	 * @return CliRoute
	 */
	public static function cli(string $pathString, callable|array $handler) : CliRoute {
		return self::create(RequestMethod::CLI, $pathString, $handler);
	}

	/**
	 * Create a new route
	 *
	 * @param RequestMethod  $type       [GET, POST, DELETE, PUT]
	 * @param string         $pathString Path
	 * @param callable|array $handler    Callback
	 *
	 * @return CliRoute
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
	 * @param Request|null $request
	 */
	public function handle(?RequestInterface $request = null) : void {
		if (is_array($this->handler)) {
			if (class_exists($this->handler[0])) {
				[$class, $func] = $this->handler;
				/** @var ControllerInterface $controller */
				$controller = App::getContainer()->getByType($class);

				$controller->init($request);
				$controller->$func($request);
			}
		}
		else {
			call_user_func($this->handler, $request);
		}
	}


	/**
	 * @return array|callable
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
	 * @param array{name:string,isOptional:bool,description:string,suggestions:array} ...$argument
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
}
