<?php

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\App;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{

	public function testLoadRoutes() : void {
		/** @var Router $router */
		$router = App::getService('routing');

		self::assertFalse(isset(Router::$availableRoutes['loaded']['GET']));
		self::assertFalse(isset(Router::$availableRoutes['loaded']['POST']));
		self::assertFalse(isset(Router::$availableRoutes['loaded']['DELETE']));
		self::assertFalse(isset(Router::$availableRoutes['loaded']['{id}']['GET']));
		self::assertFalse(isset(Router::$availableRoutes['loaded']['{id}']['POST']));
		self::assertFalse(isset(Router::$availableRoutes['registered']['action']['GET']));
		self::assertFalse(isset(Router::$availableRoutes['registered']['action']['POST']));
		self::assertFalse(isset(Router::$availableRoutes['registered']['action']['DELETE']));
		self::assertFalse(isset(Router::$availableRoutes['registered']['action']['PUT']));
		self::assertFalse(isset(Router::$availableRoutes['registered']['action2']['PUT']));
		self::assertFalse(isset(Router::$availableRoutes['cli']['action']['CLI']));
		self::assertFalse(isset(Router::$namedRoutes['get-loaded']));
		self::assertFalse(isset(Router::$namedRoutes['delete-loaded']));
		self::assertFalse(isset(Router::$namedRoutes['registered-post']));

		$dependency = [];
		[$availableRoutes, $namedRoutes] = $router->loadRoutes($dependency);

		self::assertNotEmpty($availableRoutes['loaded']['GET']);
		self::assertNotEmpty($availableRoutes['loaded']['POST']);
		self::assertNotEmpty($availableRoutes['loaded']['DELETE']);
		self::assertNotEmpty($availableRoutes['loaded']['{id}']['GET']);
		self::assertNotEmpty($availableRoutes['loaded']['{id}']['POST']);
		self::assertNotEmpty($availableRoutes['registered']['action']['GET']);
		self::assertNotEmpty($availableRoutes['registered']['action']['POST']);
		self::assertNotEmpty($availableRoutes['registered']['action']['DELETE']);
		self::assertNotEmpty($availableRoutes['registered']['action']['PUT']);
		self::assertNotEmpty($availableRoutes['registered']['action2']['PUT']);
		self::assertNotEmpty($availableRoutes['cli']['action']['CLI']);
		self::assertNotEmpty($namedRoutes['get-loaded']);
		self::assertNotEmpty($namedRoutes['delete-loaded']);
		self::assertNotEmpty($namedRoutes['registered-post']);
	}

	public function getRoutes() : array {
		return [
			[
				Route::get('/router/route', [DummyController::class, 'action']),
				RequestMethod::GET,
				['router', 'route'],
				[],
				true,
			],
			[
				Route::get('/router/route', [DummyController::class, 'action']),
				RequestMethod::POST,
				['router', 'route'],
				[],
				false,
			],
			[
				Route::get('/router/route/{id}', [DummyController::class, 'action']),
				RequestMethod::GET,
				['router', 'route', '2'],
				['id' => '2'],
				true,
			],
			[
				Route::get('/router/param/{id}', [DummyController::class, 'action']),
				RequestMethod::GET,
				['router', 'param', '2'],
				['id' => '2'],
				true,
			],
			[
				Route::post('/router/param/{id}', [DummyController::class, 'action']),
				RequestMethod::POST,
				['router', 'param', '2'],
				['id' => '2'],
				true,
			],
			[
				Route::post('/router/param/{id}', [DummyController::class, 'action']),
				RequestMethod::PUT,
				['router', 'param', '2'],
				['id' => '2'],
				false,
			],
			[
				Route::get('/router/route/{id}/test', [DummyController::class, 'action']),
				RequestMethod::GET,
				['router', 'route', '2', 'test'],
				['id' => '2'],
				true,
			],
			[
				Route::get('/router/route/{id}/test2', [DummyController::class, 'action']),
				RequestMethod::GET,
				['router', 'route', '2', 'test2'],
				['id' => '2'],
				true,
			],
			[
				Route::get('/router/route/{name}/hello', [DummyController::class, 'action']),
				RequestMethod::GET,
				['router', 'route', 'hello', 'hello'],
				['name' => 'hello'],
				true,
			],
			[
				Route::get('/router/route/{id}/test2', [DummyController::class, 'action']),
				RequestMethod::GET,
				['invalid'],
				[],
				false,
			],
		];
	}

	/**
	 * @dataProvider getRoutes
	 *
	 * @param RouteInterface      $route
	 * @param RequestMethod       $method
	 * @param string[]            $path
	 * @param array<string,mixed> $expectedParams
	 * @param bool                $expected
	 *
	 * @return void
	 */
	public function testGetRoute(RouteInterface $route, RequestMethod $method, array $path, array $expectedParams, bool $expected) : void {
		$params = [];
		$routeGot = Router::getRoute($method, $path, $params);
		self::assertSame($expected, $routeGot !== null && $route->compare($routeGot), $method->value.' '.implode('/', $path).': '.json_encode($routeGot, JSON_THROW_ON_ERROR).PHP_EOL);
		self::assertEquals($expectedParams, $params);
	}

	/**
	 * @return array{0:array<int|string, string>,1:array<int|string, string>,2:bool}[]
	 */
	public function getPathsToCompare() : array {
		return [
			[
				['test', '1'],
				['test', '1'],
				true,
			],
			[
				['test', '1'],
				['test', '2'],
				false,
			],
			[
				['test', '1', 'hello' => 'world'],
				['test', '1'],
				true,
			],
			[
				['test', '1'],
				['test', '1', 'hello' => 'world'],
				true,
			],
			[
				['test', '1'],
				['test'],
				false,
			],
		];
	}

	/**
	 * @dataProvider getPathsToCompare
	 *
	 * @param array<int|string, string> $path1
	 * @param array<int|string, string> $path2
	 * @param bool                      $expected
	 *
	 * @return void
	 */
	public function testComparePaths(array $path1, array $path2, bool $expected) : void {
		self::assertSame($expected, Router::comparePaths($path1, $path2));

		// @phpstan-ignore-next-line
		App::getRequest()->path = $path2;
		self::assertSame($expected, Router::comparePaths($path1));
	}

}
