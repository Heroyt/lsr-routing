<?php

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Caching\Cache;
use Lsr\Core\Routing\LocalizedRoute;
use Lsr\Core\Routing\RouteParameter;
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
			[
				self::getRouter()->get('/settings/modes/{id}/variations', [DummyController::class, 'action']),
				RequestMethod::GET,
				['settings', 'modes', '1', 'variations'],
				['id' => '1'],
				true,
			],
			[
				self::getRouter()->get('[lang=cs]/optional', [DummyController::class, 'action']),
				RequestMethod::GET,
				['cs', 'optional'],
				['lang' => 'cs'],
				true,
			],
			[
				self::getRouter()->get('[lang=cs]/optional', [DummyController::class, 'action']),
				RequestMethod::GET,
				['en', 'optional'],
				['lang' => 'en'],
				true,
			],
			[
				self::getRouter()->get('[lang=cs]/optional', [DummyController::class, 'action']),
				RequestMethod::GET,
				['optional'],
				['lang' => 'cs'],
				true,
			],
			[
				self::getRouter()->get('[lang=cs]/optional2', [DummyController::class, 'action']),
				RequestMethod::GET,
				['optional2'],
				['lang' => 'cs'],
				true,
			],
			[
				self::getRouter()->get('optional-no-default/[param]/hi', [DummyController::class, 'action']),
				RequestMethod::GET,
				['optional-no-default', '123', 'hi'],
				['param' => '123'],
				true,
			],
			[
				self::getRouter()->get('optional-no-default/[param]/hi', [DummyController::class, 'action']),
				RequestMethod::GET,
				['optional-no-default', 'hi'],
				[],
				true,
			],
			[
				self::getRouter()->get('optional-no-default/[param]/hello', [DummyController::class, 'action']),
				RequestMethod::GET,
				['optional-no-default', 'hello'],
				[],
				true,
			],
			[
				self::getRouter()->get('optional-no-default/[param]/hello', [DummyController::class, 'action']),
				RequestMethod::GET,
				['optional-no-default', '123', 'hello'],
				['param' => '123'],
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

	#[Depends('testLoadRoutes')]
	public function testLoadValidatedRoutes(): void {
		self::assertNotEmpty(Router::$availableRoutes['validated']);
		self::assertNotEmpty(Router::$availableRoutes['validated']['[lang=cs]']);
		self::assertInstanceOf(RouteParameter::class, Router::$availableRoutes['validated']['[lang=cs]']);

		// Check that the validator is registered
		self::assertCount(1, Router::$availableRoutes['validated']['[lang=cs]']->validators);


		self::assertNotEmpty(Router::$availableRoutes['validated2']);
		self::assertNotEmpty(Router::$availableRoutes['validated2']['{id}']);
		self::assertInstanceOf(RouteParameter::class, Router::$availableRoutes['validated2']['{id}']);
		// Check that the validator is registered
		self::assertCount(1, Router::$availableRoutes['validated2']['{id}']->validators);

		self::assertNotEmpty(Router::$availableRoutes['validated2']['{slug}']);
		self::assertInstanceOf(RouteParameter::class, Router::$availableRoutes['validated2']['{slug}']);
		// Check that no validator is registered
		self::assertCount(0, Router::$availableRoutes['validated2']['{slug}']->validators);
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
			$method->value . ' ' . implode('/', $path) . ': ' .
			json_encode(['found' => $routeGot, 'compare' => $routeGot !== null ? $route->compare($routeGot) : null],
			            JSON_THROW_ON_ERROR) . PHP_EOL
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

	#[Depends('testLoadValidatedRoutes')]
	public function testValidatedOptionalRoutes(): void {
		$method = RequestMethod::GET;

		$params = [];
		$route = Router::getRoute($method, ['validated'], $params);
		self::assertNotNull($route);
		self::assertEquals(['lang' => 'cs'], $params); // Default lang is 'cs'

		// Test valid values
		foreach (['cs', 'en', 'de'] as $lang) {
			$params = [];
			$route = Router::getRoute($method, ['validated', $lang], $params);
			self::assertNotNull($route);
			self::assertEquals(['lang' => $lang], $params);

			$params = [];
			$route = Router::getRoute($method, ['validated', $lang, 'optional'], $params);
			self::assertNotNull($route);
			self::assertEquals(['lang' => $lang], $params);

			$params = [];
			$route = Router::getRoute($method, ['validated', $lang, 'optional2'], $params);
			self::assertNotNull($route);
			self::assertEquals(['lang' => $lang], $params);
		}

		// Test invalid values
		foreach (['1', 'abcd', 'hello', 'sk'] as $lang) {
			$params = [];
			$route = Router::getRoute($method, ['validated', $lang], $params);
			self::assertNull(
				$route,
				'Invalid lang should not match: ' . $lang . ', with parameters: ' . json_encode($params)
			);
			self::assertEquals(['lang' => 'cs'], $params);

			$params = [];
			$route = Router::getRoute($method, ['validated', $lang, 'optional'], $params);
			self::assertNull(
				$route,
				'Invalid lang should not match: ' . $lang . ', with parameters: ' . json_encode($params)
			);
			self::assertEquals(['lang' => 'cs'], $params);

			$params = [];
			$route = Router::getRoute($method, ['validated', $lang, 'optional2'], $params);
			self::assertNull(
				$route,
				'Invalid lang should not match: ' . $lang . ', with parameters: ' . json_encode($params)
			);
			self::assertEquals(['lang' => 'cs'], $params);
		}
	}

	#[Depends('testLoadValidatedRoutes')]
	public function testValidatedRequiredRoutes(): void {
		$method = RequestMethod::GET;

		// Test numeric IDs
		foreach (['1', '2', '99', 8] as $param) {
			$params = [];
			$route = Router::getRoute($method, ['validated2', $param], $params);
			self::assertNotNull($route);
			self::assertEquals(['id' => $param], $params);
		}

		// Test non-numeric IDs
		foreach (['abc', '1a', '2b', '99c'] as $param) {
			$params = [];
			$route = Router::getRoute($method, ['validated2', $param], $params);
			self::assertNotNull($route);
			self::assertEquals(['slug' => $param], $params);
		}
	}

}
