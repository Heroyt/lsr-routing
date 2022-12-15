<?php

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\App;
use Lsr\Core\Requests\Request;
use Lsr\Core\Requests\Uri;
use Lsr\Core\Routing\Exceptions\DuplicateNamedRouteException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\Middleware\DummyMiddleware;
use Lsr\Core\Routing\Tests\Mockup\Models\TestModel;
use Lsr\Core\Routing\Tests\Mockup\Test2;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class RouteTest extends TestCase
{

	private readonly Router $router;

	public function __construct(?string $name = null, array $data = [], $dataName = '') {
		/** @noinspection PhpFieldAssignmentTypeMismatchInspection */
		$this->router = App::getService('routing');
		parent::__construct($name, $data, $dataName);
	}

	public function getRoutes() : array {
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
	public function getCreateRoutes() : array {
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
	public function getCreateRoutesDuplicates() : array {
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

	/**
	 * @dataProvider getRoutes
	 * @depends      testCompare
	 *
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	public function testGet(string $route, array $routeArray) : void {
		$routeObj = Route::get($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::GET, $routeArray)));
	}

	/**
	 * @dataProvider getRoutes
	 * @depends      testCompare
	 *
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	public function testUpdate(string $route, array $routeArray) : void {
		$routeObj = Route::update($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::PUT, $routeArray)));

	}

	/**
	 * @dataProvider getCreateRoutes
	 * @depends      testCompare
	 *
	 * @param RequestMethod $method
	 * @param string        $route
	 * @param string[]      $routeArray
	 *
	 * @return void
	 */
	public function testCreate(RequestMethod $method, string $route, array $routeArray) : void {
		$routeObj = Route::create($method, $route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute($method, $routeArray)));
	}

	/**
	 * @dataProvider getCreateRoutesDuplicates
	 * @depends      testCompare
	 *
	 * @param RequestMethod $method
	 * @param string        $route
	 * @param string[]      $routeArray
	 *
	 * @return void
	 */
	public function testCreateDuplicate(RequestMethod $method, string $route, array $routeArray) : void {
		$routeObj = Route::create($method, $route, static function() {
			echo 'Hello!';
		});

		// test successful insertion
		self::assertTrue($routeObj->compare($this->router::getRoute($method, $routeArray)));

		// Test duplicate
		$this->expectException(DuplicateRouteException::class);
		$this->expectExceptionMessage('Duplicate route "'.$method->value.' '.$route.'"');
		Route::create($method, $route, static function() {
			echo 'Hello!';
		});
	}

	/**
	 * @dataProvider getRoutes
	 * @depends      testCompare
	 *
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	public function testPost(string $route, array $routeArray) : void {
		$routeObj = Route::post($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::POST, $routeArray)));
	}

	public function getHandle() : array {
		return [
			[
				'/handle', '/handle', [$this, 'dummyRouteCallback'], 'Hello!'
			],
			[
				'/handle2', '/handle2', static function() {
				echo 'Hello, world!';
			}, 'Hello, world!'
			],
			[
				'/handle3',
				'/handle3',
				[DummyController::class, 'action'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR)
			],
			[
				'/handle3/{id}',
				'/handle3/1',
				[DummyController::class, 'actionWithParams'],
				'Controller init'.PHP_EOL.'action: <1> '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR)
			],
			[
				'/handle3/{id}',
				'/handle3/69',
				[DummyController::class, 'actionWithParams'],
				'Controller init'.PHP_EOL.'action: <69> '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR)
			],
			[
				'/handle3/test',
				'/handle3/test',
				[DummyController::class, 'actionWithParams2'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'This is a DI TEST class',
			],
			[
				'/handle3/model/{id}',
				'/handle3/model/1',
				[DummyController::class, 'actionWithModel'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'test1',
			],
			[
				'/handle3/model2/{modelId}',
				'/handle3/model2/2',
				[DummyController::class, 'actionWithModel'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'test2',
			],
			[
				'/handle3/model3/{model1Id}/{model2Id}',
				'/handle3/model3/3/1',
				[DummyController::class, 'actionWithMultipleModels'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'test3'.PHP_EOL.'test1',
			],
			[
				'/handle3/model4',
				'/handle3/model4',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'empty',
			],
			[
				'/handle3/model5/{a}',
				'/handle3/model5/1',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'empty',
			],
			[
				'/handle3/model6/{id}',
				'/handle3/model6/1',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'test1',
			],
			[
				'/handle3/model6/{id}',
				'/handle3/model6/99',
				[DummyController::class, 'actionWithOptionalModel'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR).PHP_EOL.'empty',
			],
			[
				'/handle3/service',
				'/handle3/service',
				[DummyController::class, 'actionWithInvalidOptionalService'],
				'Controller init'.PHP_EOL.'action: '.json_encode(['controller' => 'Middleware'], JSON_THROW_ON_ERROR),
			],
		];
	}

	public function getHandleInvalid() : array {
		return [
			[
				'/handle4/{id}',
				'/handle4/1',
				[DummyController::class, 'actionWithInvalidParams'],
				'Unsupported route handler method type in '.DummyController::class.'::actionWithInvalidParams(). Only built-in types, RequestInterface and Model classes are supported.',
			],
			[
				'/handle5/{id}',
				'/handle5/1',
				[DummyController::class, 'actionWithInvalidParams2'],
				'Unsupported route handler method type in '.DummyController::class.'::actionWithInvalidParams2(object $id). Only built-in types, RequestInterface and Model classes are supported.',
			],
			[
				'/handle6',
				'/handle6',
				[DummyController::class, 'actionWithModel'],
				'Cannot instantiate Model for route. No ID route parameter. /handle6 - argument: '.TestModel::class.' $model. Expecting parameter "id" or "modelId".',
			],
			[
				'/handle6/{id}',
				'/handle6/99',
				[DummyController::class, 'actionWithModel'],
				'Cannot instantiate Model for route. Model not found. /handle6/{id} - argument: '.TestModel::class.' $model.',
			],
			[
				'/handle7',
				'/handle7',
				[DummyController::class, 'actionWithInvalidService'],
				'Service of type '.Test2::class.' not found. Did you add it to configuration file?',
			],
		];
	}

	/**
	 * @dataProvider getHandle
	 * @depends      testGet
	 *
	 * @param string                                           $routePath
	 * @param string                                           $requestPath
	 * @param array{0:class-string|object, 1: string}|callable $handler
	 * @param string                                           $expected
	 *
	 * @return void
	 */
	public function testHandle(string $routePath, string $requestPath, array|callable $handler, string $expected) : void {
		$route = Route::get($routePath, $handler);
		$request = new Request(new Uri('http://localhost'.$requestPath));
		ob_start();
		$route->handle($request);
		$response = ob_get_clean();
		self::assertSame($expected, $response);
	}

	/**
	 * @dataProvider getHandleInvalid
	 * @depends      testGet
	 *
	 * @param string         $routePath
	 * @param string         $requestPath
	 * @param array|callable $handler
	 * @param string         $exceptionMessage
	 *
	 * @return void
	 */
	public function testHandleInvalid(string $routePath, string $requestPath, array|callable $handler, string $exceptionMessage) : void {
		$route = Route::get($routePath, $handler);
		$request = new Request(new Uri('http://localhost'.$requestPath));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($exceptionMessage);
		ob_start();
		try {
			$route->handle($request);
		} catch (Throwable $e) {
			ob_get_clean();
			throw $e;
		}
		ob_get_clean();
	}

	public function getRoutesNames() : array {
		return [
			[
				RequestMethod::GET, '/named', 'named-route',
			],
			[
				RequestMethod::POST, '/named', 'named-route-post',
			],
		];
	}

	public function getRoutesNamesDuplicates() : array {
		return [
			[
				'duplicate-route-1',
				RequestMethod::GET, '/named/duplicate', [$this, 'dummyRouteCallback'],
				RequestMethod::POST, '/named/duplicate', [$this, 'dummyRouteCallback'],
			],
			[
				'duplicate-route-2',
				RequestMethod::POST, '/named/asd', [$this, 'dummyRouteCallback'],
				RequestMethod::POST, '/named/dasa', [$this, 'dummyRouteCallback'],
			],
			[
				'duplicate-route-3',
				RequestMethod::POST, '/named/pppppp', [$this, 'dummyRouteCallback'],
				RequestMethod::POST, '/named/asdad', [$this, 'getRoutes'],
			],
		];
	}

	/**
	 * @dataProvider getRoutesNames
	 * @depends      testCreate
	 *
	 * @param RequestMethod $method
	 * @param string        $route
	 * @param string        $name
	 *
	 * @return void
	 */
	public function testName(RequestMethod $method, string $route, string $name) : void {
		$route = Route::create($method, $route, [$this, 'dummyRouteCallback'])->name($name);
		self::assertTrue($route->compare($this->router->getRouteByName($name)));
	}

	/**
	 * @dataProvider getRoutesNamesDuplicates
	 * @depends      testCreate
	 *
	 * @param string                                           $name
	 * @param RequestMethod                                    $method1
	 * @param string                                           $route1
	 * @param array{0:class-string|object, 1: string}|callable $callback1
	 * @param RequestMethod                                    $method2
	 * @param string                                           $route2
	 * @param array{0:class-string|object, 1: string}|callable $callback2
	 *
	 * @return void
	 * @throws DuplicateRouteException
	 */
	public function testNameDuplicate(string $name, RequestMethod $method1, string $route1, array|callable $callback1, RequestMethod $method2, string $route2, array|callable $callback2) : void {
		$route = Route::create($method1, $route1, $callback1)->name($name);

		// Test successful insert
		self::assertTrue($route->compare($this->router->getRouteByName($name)));

		// Inserting the same route twice should be OK
		Route::create($method1, $route1, $callback1)->name($name);

		// Inserting a different route with the same name should throw an exception
		$this->expectException(DuplicateNamedRouteException::class);
		$this->expectExceptionMessage('Duplicate named route "'.$name.': '.$method1->value.' '.$route1.'"');
		Route::create($method2, $route2, $callback2)->name($name);
	}

	/**
	 * @dataProvider getRoutes
	 * @depends      testCompare
	 *
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	public function testPut(string $route, array $routeArray) : void {
		$routeObj = Route::put($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::PUT, $routeArray)));
	}

	public function getMiddlewareData() : array {
		return [
			[
				['test' => 1],
			],
		];
	}

	/**
	 * @dataProvider getMiddlewareData
	 * @depends      testGet
	 * @depends      testHandle
	 *
	 * @param array $data
	 *
	 * @return void
	 */
	public function testMiddleware(array $data) : void {
		$route = Route::get('/middleware', [$this, 'dummyRequestRouteCallback'])
									->middleware(new DummyMiddleware($data));

		$request = new Request(new Uri('http://localhost/middleware'));
		$request->request = ['this', 'will', 'be', 'overwritten'];

		ob_start();
		$route->handle($request);
		$response = ob_get_clean();

		self::assertSame(json_encode($data, JSON_THROW_ON_ERROR), $response);
	}

	/**
	 * @dataProvider getRoutes
	 * @depends      testCompare
	 *
	 * @param string   $route
	 * @param string[] $routeArray
	 *
	 * @return void
	 */
	public function testDelete(string $route, array $routeArray) : void {
		$routeObj = Route::delete($route, [$this, 'dummyRouteCallback']);

		self::assertTrue($routeObj->compare($this->router::getRoute(RequestMethod::DELETE, $routeArray)));

	}

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

	public function getRoutesToCompare() : array {
		return [
			[
				$this->createRoute(RequestMethod::GET, '/', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::GET, '/', [$this, 'dummyRouteCallback']),
				true,
			],
			[
				$this->createRoute(RequestMethod::GET, '/', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::GET, '/test', [$this, 'dummyRouteCallback']),
				false,
			],
			[
				$this->createRoute(RequestMethod::POST, '/', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::GET, '/', [$this, 'dummyRouteCallback']),
				false,
			],
			[
				$this->createRoute(RequestMethod::POST, '/test', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::POST, '/test', [$this, 'dummyRouteCallback']),
				true,
			],
			[
				$this->createRoute(RequestMethod::POST, '/test/{id}', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::POST, '/test/{id}', [$this, 'dummyRouteCallback']),
				true,
			],
			[
				$this->createRoute(RequestMethod::POST, '/test/{id}', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::POST, '/test/{id}', [$this, 'getRoutes']),
				false,
			],
			[
				$this->createRoute(RequestMethod::POST, '/test', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::POST, '/test', [$this, 'getRoutes']),
				false,
			],
			[
				$this->createRoute(RequestMethod::POST, '/test/{id}', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::POST, '/test/{objId}', [$this, 'dummyRouteCallback']),
				true,
			],
			[
				$this->createRoute(RequestMethod::GET, '/test/hello/{id}', [$this, 'dummyRouteCallback']),
				$this->createRoute(RequestMethod::GET, '/test/hello/{id}', [$this, 'dummyRouteCallback']),
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
	private function createRoute(RequestMethod $method, string $pathString, array|callable $handler) : RouteInterface {
		$route = new Route($method, $handler);
		$route->path = array_filter(explode('/', $pathString), 'not_empty');
		$route->readablePath = $pathString;
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
		self::assertSame($same, Route::compareRoutePaths($path1, $path2));
	}

	public function dummyRouteCallback() : void {
		echo 'Hello!';
	}

	public function dummyRequestRouteCallback(Request $request) : void {
		echo json_encode($request->request, JSON_THROW_ON_ERROR);
	}
}
