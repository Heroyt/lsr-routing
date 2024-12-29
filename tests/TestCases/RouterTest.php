<?php

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Caching\Cache;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\LocalizedRoute;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Nette\Caching\Storages\DevNullStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{

	private static Router $router;

	public function __construct(?string $name = null) {
		$this::getRouter()->setup();
		parent::__construct($name);
	}

	public static function getRouter(): Router {
		if (!isset(self::$router)) {
			self::$router = new Router(
				new Cache(new DevNullStorage()),
				[
					ROOT . 'routes/test.php',
				]
			);
		}
		return self::$router;
	}

	public static function getRoutes(): array {
		return [
			/*[
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
			],*/
			[
				self::getRouter()->get('/settings/modes/{id}/variations', [DummyController::class, 'action']),
				RequestMethod::GET,
				['settings', 'modes', '1', 'variations'],
				['id' => '1'],
				true,
			],
		];
	}

	/**
	 * @return array{0:array<int|string, string>,1:array<int|string, string>,2:bool}[]
	 */
	public static function getPathsToCompare(): array {
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

	public function testLoadRoutes(): void {
		$this::getRouter()->unregisterAll();
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

		[$availableRoutes, $namedRoutes] = $this::getRouter()->loadRoutes();

		self::assertNotEmpty($availableRoutes['loaded']['GET']);
		self::assertNotEmpty($availableRoutes['loaded']['POST']);
		self::assertNotEmpty($availableRoutes['loaded']['DELETE']);
		self::assertNotEmpty($availableRoutes['loaded']['{id}']['GET']);
		self::assertNotEmpty($availableRoutes['nahrano']['{id}']['GET']);
		self::assertNotEmpty($availableRoutes['loaded']['{id}']['POST']);
		self::assertNotEmpty($availableRoutes['settings']['POST']);

		// Localized routes
		self::assertNotEmpty($availableRoutes['settings']['GET']);
		self::assertNotEmpty($availableRoutes['settings']['GET'][0]->localizedRoutes['cs']);
		self::assertNotEmpty($availableRoutes['nastaveni']['GET']);
		self::assertInstanceOf(LocalizedRoute::class, $availableRoutes['nastaveni']['GET'][0]);

		self::assertNotEmpty($availableRoutes['settings']['gate']['GET']);
		self::assertNotEmpty($availableRoutes['settings']['modes']['GET']);
		self::assertNotEmpty($availableRoutes['settings']['modes']['{system}']['GET']);
		self::assertNotEmpty($namedRoutes['get-loaded']);
		self::assertNotEmpty($namedRoutes['delete-loaded']);
	}

	/**
	 * @param RouteInterface $route
	 * @param RequestMethod $method
	 * @param string[] $path
	 * @param array<string,mixed> $expectedParams
	 * @param bool $expected
	 *
	 * @return void
	 */
	#[Depends('testLoadRoutes')] #[DataProvider('getRoutes')]
	public function testGetRoute(RouteInterface $route, RequestMethod $method, array $path, array $expectedParams, bool $expected): void {
		$params = [];
		$routeGot = Router::getRoute($method, $path, $params);
		self::assertSame(
			$expected,
			$routeGot !== null && $route->compare($routeGot),
			$method->value . ' ' . implode('/', $path) . ': ' . json_encode(
				$routeGot,
				JSON_THROW_ON_ERROR
			) . PHP_EOL
		);
		self::assertEquals($expectedParams, $params);
	}

	/**
	 *
	 * @param array<int|string, string> $path1
	 * @param array<int|string, string> $path2
	 * @param bool                      $expected
	 *
	 * @return void
	 */
	#[DataProvider('getPathsToCompare')]
	public function testComparePaths(array $path1, array $path2, bool $expected): void {
		self::assertSame($expected, Router::comparePaths($path1, $path2));
	}

	public function testLocalizedRouteRedirect() : void {
		$request = new \Nyholm\Psr7\ServerRequest('GET', '/nahrano/10');

		$params = [];
		$route = Router::getRoute(RequestMethod::GET, ['nahrano', '10'], $params);
		self::assertInstanceOf(LocalizedRoute::class, $route);
		self::assertEquals(10, $params['id']);

		$request = $request->withAttribute('lang', 'en')->withAttribute('id', $params['id']);

		$response = $route->redirect($request);
		self::assertEquals(300, $response->getStatusCode());
		self::assertEquals('/loaded/10', $response->getHeaderLine('Location'));
	}

}
