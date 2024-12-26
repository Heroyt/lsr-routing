<?php

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Caching\Cache;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\Models\TestModel;
use Lsr\Core\Routing\Tests\Mockup\Model2;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Nette\Caching\Storages\DevNullStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{

	private readonly Router $router;

	public function __construct(?string $name = null) {
		$this->router = new Router(new Cache(new DevNullStorage()));
		parent::__construct($name);
	}

	public static function getRoutes(): array {
		return [
			[
				'/',
				[],
			],
			[
				'/test',
				['test'],
			],
			[
				'/test/hello',
				['test', 'hello'],
			],
			[
				'/test/hello/{id}',
				['test', 'hello', '1'],
			],
			[
				'/test/hello/{id}',
				['test', 'hello', '2'],
			],
		];
	}

	/**
	 * @return array{0:RequestMethod,1:string,2:string[]}[][]
	 */
	public static function getCreateRoutes(): array {
		return [
			[
				RequestMethod::POST,
				'/aaa',
				['aaa'],
			],
			[
				RequestMethod::UPDATE,
				'/test/bbb',
				['test', 'bbb'],
			],
			[
				RequestMethod::GET,
				'/test/hey',
				['test', 'hey'],
			],
		];
	}

	/**
	 * @return array{0:RequestMethod,1:string,2:string[]}[][]
	 */
	public static function getCreateRoutesDuplicates(): array {
		return [
			[
				RequestMethod::GET,
				'/aaa',
				['aaa'],
			],
			[
				RequestMethod::POST,
				'/test/bbb',
				['test', 'bbb'],
			],
			[
				RequestMethod::PUT,
				'/test/hey',
				['test', 'hey'],
			],
		];
	}

	public static function getHandle(): array {
		return [
			[
				'/handle',
				'/handle',
				[self::class, 'dummyRouteCallback'],
				'Hello!',
			],
			[
				'/handle2',
				'/handle2',
				static function () {
					echo 'Hello, world!';
				},
				'Hello, world!',
			],
			[
				'/handle3',
				'/handle3',
				[DummyController::class, 'action'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR),
			],
			[
				'/handle3/{id}',
				'/handle3/1',
				[DummyController::class, 'actionWithParams'],
				'Controller init' . PHP_EOL . 'action: <1> ' . json_encode(['controller' => 'Middleware'],
				                                                           JSON_THROW_ON_ERROR),
			],
			[
				'/handle3/{id}',
				'/handle3/69',
				[DummyController::class, 'actionWithParams'],
				'Controller init' . PHP_EOL . 'action: <69> ' . json_encode(['controller' => 'Middleware'],
				                                                            JSON_THROW_ON_ERROR),
			],
			[
				'/handle3/test',
				'/handle3/test',
				[DummyController::class, 'actionWithParams2'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR
				) . PHP_EOL . 'This is a DI TEST class',
			],
			[
				'/handle3/model/{id}',
				'/handle3/model/1',
				[DummyController::class, 'actionWithModel'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR) . PHP_EOL . 'test1',
			],
			[
				'/handle3/model2/{modelId}',
				'/handle3/model2/2',
				[DummyController::class, 'actionWithModel'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR) . PHP_EOL . 'test2',
			],
			[
				'/handle3/model3/{model1Id}/{model2Id}',
				'/handle3/model3/3/1',
				[DummyController::class, 'actionWithMultipleModels'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR
				) . PHP_EOL . 'test3' . PHP_EOL . 'test1',
			],
			[
				'/handle3/model4',
				'/handle3/model4',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR) . PHP_EOL . 'empty',
			],
			[
				'/handle3/model5/{a}',
				'/handle3/model5/1',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR) . PHP_EOL . 'empty',
			],
			[
				'/handle3/model6/{id}',
				'/handle3/model6/1',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR) . PHP_EOL . 'test1',
			],
			[
				'/handle3/model6/{id}',
				'/handle3/model6/99',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR) . PHP_EOL . 'empty',
			],
			[
				'/handle3/service',
				'/handle3/service',
				[DummyController::class, 'actionWithInvalidOptionalService'],
				'Controller init' . PHP_EOL . 'action: ' . json_encode(['controller' => 'Middleware'],
				                                                       JSON_THROW_ON_ERROR),
			],
		];
	}

	public static function getHandleInvalid(): array {
		return [
			[
				'/handle4/{id}',
				'/handle4/1',
				[DummyController::class, 'actionWithInvalidParams'],
				'Unsupported route handler method type in ' . DummyController::class . '::actionWithInvalidParams(). Only built-in types, RequestInterface and Model classes are supported.',
			],
			[
				'/handle5/{id}',
				'/handle5/1',
				[DummyController::class, 'actionWithInvalidParams2'],
				'Unsupported route handler method type in ' . DummyController::class . '::actionWithInvalidParams2(object $id). Only built-in types, RequestInterface and Model classes are supported.',
			],
			[
				'/handle6',
				'/handle6',
				[DummyController::class, 'actionWithModel'],
				'Cannot instantiate Model for route. No ID route parameter. /handle6 - argument: ' . TestModel::class . ' $model. Expecting parameter "id" or "modelId".',
			],
			[
				'/handle6/{id}',
				'/handle6/99',
				[DummyController::class, 'actionWithModel'],
				'Cannot instantiate Model for route. Model not found. /handle6/{id} - argument: ' . TestModel::class . ' $model.',
			],
			[
				'/handle7',
				'/handle7',
				[DummyController::class, 'actionWithInvalidService'],
				'Service of type ' . Model2::class . ' not found. Did you add it to configuration file?',
			],
		];
	}

	public static function getRoutesNames(): array {
		return [
			[
				RequestMethod::GET,
				'/named',
				'named-route',
			],
			[
				RequestMethod::POST,
				'/named',
				'named-route-post',
			],
		];
	}

	public static function getRoutesNamesDuplicates(): array {
		return [
			[
				'duplicate-route-1',
				RequestMethod::GET,
				'/named/duplicate',
				[self::class, 'dummyRouteCallback'],
				RequestMethod::POST,
				'/named/duplicate',
				[self::class, 'dummyRouteCallback'],
			],
			[
				'duplicate-route-2',
				RequestMethod::POST,
				'/named/asd',
				[self::class, 'dummyRouteCallback'],
				RequestMethod::POST,
				'/named/dasa',
				[self::class, 'dummyRouteCallback'],
			],
			[
				'duplicate-route-3',
				RequestMethod::POST,
				'/named/pppppp',
				[self::class, 'dummyRouteCallback'],
				RequestMethod::POST,
				'/named/asdad',
				[self::class, 'getRoutes'],
			],
		];
	}

	public static function getMiddlewareData(): array {
		return [
			[
				['test' => 1],
			],
		];
	}

	public static function getRoutesToCompare(): array {
		return [
			[
				self::createRoute(RequestMethod::GET, '/', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::GET, '/', [self::class, 'dummyRouteCallback']),
				true,
			],
			[
				self::createRoute(RequestMethod::GET, '/', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::GET, '/test', [self::class, 'dummyRouteCallback']),
				false,
			],
			[
				self::createRoute(RequestMethod::POST, '/', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::GET, '/', [self::class, 'dummyRouteCallback']),
				false,
			],
			[
				self::createRoute(RequestMethod::POST, '/test', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::POST, '/test', [self::class, 'dummyRouteCallback']),
				true,
			],
			[
				self::createRoute(RequestMethod::POST, '/test/{id}', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::POST, '/test/{id}', [self::class, 'dummyRouteCallback']),
				true,
			],
			[
				self::createRoute(RequestMethod::POST, '/test/{id}', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::POST, '/test/{id}', [self::class, 'getRoutes']),
				false,
			],
			[
				self::createRoute(RequestMethod::POST, '/test', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::POST, '/test', [self::class, 'getRoutes']),
				false,
			],
			[
				self::createRoute(RequestMethod::POST, '/test/{id}', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::POST, '/test/{objId}', [self::class, 'dummyRouteCallback']),
				true,
			],
			[
				self::createRoute(RequestMethod::GET, '/test/hello/{id}', [self::class, 'dummyRouteCallback']),
				self::createRoute(RequestMethod::GET, '/test/hello/{id}', [self::class, 'dummyRouteCallback']),
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
	private static function createRoute(RequestMethod $method, string $pathString, array|callable $handler): RouteInterface {
		return Route::create($method, $pathString, $handler);
	}

	public static function getPaths(): array {
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

	#[Depends('testCompare')] #[DataProvider('getRoutes')]
	public function testGet(string $route, array $routeArray): void {
		$routeObj = $this->router->get($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::GET, $routeArray)));
	}

	/**
	 * @param string   $route
	 * @param string[] $routeArray
	 */
	#[DataProvider('getRoutes')] #[Depends('testCompare')]
	public function testUpdate(string $route, array $routeArray): void {
		$routeObj = $this->router->update($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::UPDATE, $routeArray)));
	}

	/**
	 * @param RequestMethod $method
	 * @param string        $route
	 * @param string[]      $routeArray
	 */
	#[Depends('testCompare')] #[DataProvider('getCreateRoutes')]
	public function testCreate(RequestMethod $method, string $route, array $routeArray): void {
		$routeObj = $this->router->route($method, $route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute($method, $routeArray)));
	}

	/**
	 *
	 * @param RequestMethod $method
	 * @param string        $route
	 * @param string[]      $routeArray
	 *
	 * @return void
	 */
	#[Depends('testCompare')] #[DataProvider('getCreateRoutesDuplicates')]
	public function testCreateDuplicate(RequestMethod $method, string $route, array $routeArray): void {
		$routeObj = $this->router->route($method, $route, static function () {
			echo 'Hello!';
		});

		// test successful insertion
		self::assertTrue($routeObj->compare($this->router::getRoute($method, $routeArray)));

		// Test duplicate
		$this->expectException(DuplicateRouteException::class);
		$this->expectExceptionMessage('Duplicate route "' . $method->value . ' ' . $route . '"');
		$this->router->route($method, $route, static function () {
			echo 'Hello!';
		});
	}

	/**
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	#[Depends('testCompare')] #[DataProvider('getRoutes')]
	public function testPost(string $route, array $routeArray): void {
		$routeObj = $this->router->post($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::POST, $routeArray)));
	}

	#[Depends('testCreate')] #[DataProvider('getRoutesNames')]
	public function testName(RequestMethod $method, string $route, string $name): void {
		$route = $this->router->route($method, $route, [$this, 'dummyRouteCallback'])->name($name);
		self::assertTrue($route->compare($this->router->getRouteByName($name)));
	}

	#[Depends('testCreate')]
	#[DataProvider('getRoutesNamesDuplicates')]
	public function testNameDuplicate(string $name, RequestMethod $method1, string $route1, array|callable $callback1, RequestMethod $method2, string $route2, array|callable $callback2): void {
		$route = $this->router->route($method1, $route1, $callback1)->name($name);

		// Test successful insert
		self::assertTrue($route->compare($this->router->getRouteByName($name)));

		// Inserting the same route twice should be OK
		$this->router->route($method1, $route1, $callback1)->name($name);

		// Inserting a different route with the same name should throw an exception
		$this->expectException(DuplicateNamedRouteException::class);
		$this->expectExceptionMessage('Duplicate named route "' . $name . ': ' . $method1->value . ' ' . $route1 . '"');
		$this->router->route($method2, $route2, $callback2)->name($name);
	}

	/**
	 * @param string[] $routeArray
	 */
	#[Depends('testCompare')] #[DataProvider('getRoutes')]
	public function testPut(string $route, array $routeArray): void {
		$routeObj = $this->router->put($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::PUT, $routeArray)));
	}

	/**
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	#[Depends('testCompare')] #[DataProvider('getRoutes')]
	public function testDelete(string $route, array $routeArray): void {
		$routeObj = $this->router->delete($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::DELETE, $routeArray)));

	}

	#[Depends('testCompareRoutePaths')] #[DataProvider('getRoutesToCompare')]
	public function testCompare(RouteInterface $route1, RouteInterface $route2, bool $same): void {
		self::assertSame(
			$same,
			$route1->compare($route2),
			'Failed to compare routes:' . PHP_EOL .
			$route1->getMethod()->value . ' ' . $route1->getReadable() . ' ' . $this->printCallable(
				$route1->getHandler()
			) . PHP_EOL .
			$route2->getMethod()->value . ' ' . $route2->getReadable() . ' ' . $this->printCallable(
				$route2->getHandler()
			) . PHP_EOL
		);
		self::assertSame(
			$same,
			$route2->compare($route1),
			'Failed to compare routes:' . PHP_EOL .
			$route2->getMethod()->value . ' ' . $route2->getReadable() . ' ' . $this->printCallable(
				$route2->getHandler()
			) . PHP_EOL .
			$route1->getMethod()->value . ' ' . $route1->getReadable() . ' ' . $this->printCallable(
				$route1->getHandler()
			) . PHP_EOL
		);
	}

	/**
	 * @param array{0:class-string|object,1:string}|callable $callable
	 *
	 * @return string
	 */
	private function printCallable(array|callable $callable): string {
		if (is_array($callable)) {
			$str = '';
			if (is_object($callable[0])) {
				$str .= $callable[0]::class;
			}
			else {
				$str .= $callable[0];
			}
			$str .= '::' . $callable[1] . '()';
			return $str;
		}
		if (is_string($callable)) {
			return $callable;
		}
		return 'Closure()';
	}

	/**
	 *
	 * @param string[] $path1
	 * @param string[] $path2
	 * @param bool     $same
	 *
	 * @return void
	 */
	#[DataProvider('getPaths')]
	public function testCompareRoutePaths(array $path1, array $path2, bool $same): void {
		self::assertSame($same, Route::compareRoutePaths($path1, $path2));
	}

	public function dummyRouteCallback(): void {
		echo 'Hello!';
	}

	public function dummyRequestRouteCallback(Request $request): void {
		echo json_encode($request->request, JSON_THROW_ON_ERROR);
	}
}
