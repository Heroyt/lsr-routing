<?php

namespace Lsr\Core\Routing\TestCases;

use Lsr\Core\Requests\CliRequest;
use Lsr\Core\Routing\CliRoute;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use PHPUnit\Framework\TestCase;

class CliRouteTest extends TestCase
{

	public function getPaths() : array {
		return [
			[
				['test'],
				['test'],
				true,
			],
			[
				[],
				['test'],
				false,
			],
			[
				['hello'],
				['test'],
				false,
			],
			[
				['test', 'hi'],
				['test', 'hi'],
				true,
			],
			[
				['test', 'hello', '{id}'],
				['test', 'hello', '{id}'],
				true,
			],
			[
				['test', 'hello', '{id}'],
				['test', 'hello', '{objId}'],
				true,
			],
			[
				['test', 'hello', '{id}'],
				['test', 'hello', 'id'],
				false,
			],
		];
	}

	/**
	 * @dataProvider getPaths
	 *
	 * @param string[] $path1
	 * @param string[] $path2
	 * @param bool     $same
	 *
	 * @return void
	 */
	public function testCompareRoutePaths(array $path1, array $path2, bool $same) : void {
		self::assertSame($same, CliRoute::compareRoutePaths($path1, $path2));
	}

	public function testHelp() : void {
		$help = 'This is a help string';
		$route = CliRoute::cli('/help', [DummyController::class, 'action'])
										 ->help(static function() use ($help) {
											 return $help;
										 });
		self::assertSame($help, ($route->helpPrint)());
	}

	public function dummyRouteCallback() : void {
		echo 'Hello!';
	}


	public function getHandle() : array {
		return [
			[
				'/cli/handle', '/cli/handle', [$this, 'dummyRouteCallback'], 'Hello!'
			],
			[
				'/cli/handle2', '/cli/handle2', static function() {
				echo 'Hello, world!';
			}, 'Hello, world!'
			],
			[
				'/cli/handle3',
				'/cli/handle3',
				[DummyController::class, 'cliAction'],
				'Controller init'.PHP_EOL.'cli action'
			],
		];
	}

	/**
	 * @dataProvider getHandle
	 * @depends      testCli
	 *
	 * @param string                                           $routePath
	 * @param string                                           $requestPath
	 * @param array{0:class-string|object, 1: string}|callable $handler
	 * @param string                                           $expected
	 *
	 * @return void
	 */
	public function testHandle(string $routePath, string $requestPath, array|callable $handler, string $expected) : void {
		$route = CliRoute::cli($routePath, $handler);
		$request = new CliRequest($requestPath);
		ob_start();
		$route->handle($request);
		$response = ob_get_clean();
		self::assertSame($expected, $response);
	}

	public function testUsage() : void {
		$route = CliRoute::cli('/usage', [DummyController::class, 'action']);
		self::assertSame('usage', $route->getReadable());
		$route->usage('run usage');
		self::assertSame('run usage', $route->getReadable());
	}

	public function getRoutes() : array {
		return [
			[
				'test',
				['test'],
			],
			[
				'test/aaa',
				['test', 'aaa'],
			],
		];
	}

	/**
	 * @dataProvider getRoutes
	 *
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	public function testCli(string $route, array $routeArray) : void {
		$route = CliRoute::cli($route, [DummyController::class, 'action']);
		self::assertTrue($route->compare(Router::getRoute(RequestMethod::CLI, $routeArray)));
	}

	public function testDescription() : void {
		$description = 'This is a description';
		$route = CliRoute::cli('/desc', [DummyController::class, 'action'])
										 ->description($description);
		self::assertSame($description, $route->description);
	}

	public function testName() : void {
		$route = CliRoute::cli('/help', [DummyController::class, 'action']);
		self::assertSame('', $route->getName());
	}


	public function getRoutesToCompare() : array {
		$handler = static fn() => 'Hello';
		return [
			[
				$this->createRoute('/', $handler),
				$this->createRoute('/', $handler),
				true,
			],
			[
				$this->createRoute('/', [DummyController::class, 'action']),
				$this->createRoute('/', [DummyController::class, 'action']),
				true,
			],
			[
				$this->createRoute('/', [DummyController::class, 'action']),
				$this->createRoute('/test', [DummyController::class, 'action']),
				false,
			],
			[
				$this->createRoute('/test', [DummyController::class, 'action']),
				$this->createRoute('/test', [DummyController::class, 'action']),
				true,
			],
			[
				$this->createRoute('/test/{id}', [DummyController::class, 'action']),
				$this->createRoute('/test/{id}', [DummyController::class, 'action']),
				true,
			],
			[
				$this->createRoute('/test2/{id}', [DummyController::class, 'action']),
				$this->createRoute('/test2/{id}', [$this, 'getRoutes']),
				false,
			],
			[
				$this->createRoute('/test', [DummyController::class, 'action']),
				$this->createRoute('/test', [$this, 'getRoutes']),
				false,
			],
			[
				$this->createRoute('/test/{id}', [DummyController::class, 'action']),
				$this->createRoute('/test/{objId}', [DummyController::class, 'action']),
				true,
			],
			[
				$this->createRoute('/test/hello/{id}', [DummyController::class, 'action']),
				$this->createRoute('/test/hello/{id}', [DummyController::class, 'action']),
				true,
			],
		];
	}

	/**
	 * @param RequestMethod                                  $method
	 * @param string                                         $pathString
	 * @param array{0:class-string|object,1:string}|callable $handler
	 *
	 * @return RouteInterface
	 */
	private function createRoute(string $pathString, array|callable $handler) : RouteInterface {
		$route = new CliRoute(RequestMethod::CLI, $handler);
		$route->path = array_filter(explode('/', $pathString), 'not_empty');
		return $route;
	}

	/**
	 * @dataProvider getRoutesToCompare
	 * @depends      testCompareRoutePaths
	 *
	 * @param RouteInterface $route1
	 * @param RouteInterface $route2
	 * @param bool           $same
	 *
	 * @return void
	 */
	public function testCompare(RouteInterface $route1, RouteInterface $route2, bool $same) : void {
		self::assertSame(
			$same,
			$route1->compare($route2),
			'Failed to compare routes:'.PHP_EOL.
			$route1->getMethod()->value.' '.$route1->getReadable().' '.$this->printCallable($route1->getHandler()).PHP_EOL.
			$route2->getMethod()->value.' '.$route2->getReadable().' '.$this->printCallable($route2->getHandler()).PHP_EOL
		);
		self::assertSame(
			$same,
			$route2->compare($route1),
			'Failed to compare routes:'.PHP_EOL.
			$route2->getMethod()->value.' '.$route2->getReadable().' '.$this->printCallable($route2->getHandler()).PHP_EOL.
			$route1->getMethod()->value.' '.$route1->getReadable().' '.$this->printCallable($route1->getHandler()).PHP_EOL
		);
	}

	/**
	 * @param array{0:class-string|object,1:string}|callable $callable
	 *
	 * @return string
	 */
	private function printCallable(array|callable $callable) : string {
		if (is_array($callable)) {
			$str = '';
			if (is_object($callable[0])) {
				$str .= $callable[0]::class;
			}
			else {
				$str .= $callable[0];
			}
			$str .= '::'.$callable[1].'()';
			return $str;
		}
		if (is_string($callable)) {
			return $callable;
		}
		return 'Closure()';
	}

	public function testAddArgument() : void {
		$arguments = [
			[
				'name'        => 'arg1',
				'isOptional'  => false,
				'description' => 'First argument',
			],
			[
				'name'        => 'arg2',
				'isOptional'  => true,
				'description' => 'Second argument',
				'suggestions' => [
					'aaaa',
					'bbbb',
				]
			],
		];
		$route = CliRoute::cli('/arguments', [DummyController::class, 'action'])
										 ->addArgument(...$arguments);
		self::assertEquals($arguments, $route->arguments);
	}

}
