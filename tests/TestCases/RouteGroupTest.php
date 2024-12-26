<?php

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Caching\Cache;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use Nette\Caching\Storages\DevNullStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RouteGroupTest extends TestCase
{

	private static Router $router;

	public function testGetPath(): void {
		$group = self::getRouter()->group('path');

		self::assertSame('path', $group->getPath());
	}

	public static function getRouter(): Router {
		if (!isset(self::$router)) {
			self::$router = new Router(new Cache(new DevNullStorage()));
		}
		return self::$router;
	}

	public function testGroup(): void {
		self::getRouter()->group('/grouped')
		    ->get('/', [DummyController::class, 'action'])->name('grouped-get')
		    ->post('/', [DummyController::class, 'action'])
		    ->get('hello', [DummyController::class, 'action'])->name('grouped-get-hello')
		    ->get('{id}', [DummyController::class, 'actionWithParams'])->name('grouped-get-id')
		    ->delete('{id}', [DummyController::class, 'actionWithParams'])
		    ->put('{id}/aaa', [DummyController::class, 'actionWithParams'])
		    ->update('{id}/bbb', [DummyController::class, 'actionWithParams']);

		$route1 = Router::getRoute(RequestMethod::GET, ['grouped']);
		$route2 = Router::getRoute(RequestMethod::POST, ['grouped']);
		$route3 = Router::getRoute(RequestMethod::GET, ['grouped', 'hello']);
		$route4 = Router::getRoute(RequestMethod::GET, ['grouped', '1']);
		$route5 = Router::getRoute(RequestMethod::DELETE, ['grouped', '99']);
		$route6 = Router::getRoute(RequestMethod::PUT, ['grouped', '1', 'aaa']);
		$route7 = Router::getRoute(RequestMethod::PUT, ['grouped', '1', 'bbb']);

		// Test that the groups exist
		self::assertNotNull($route1);
		self::assertNotNull($route2);
		self::assertNotNull($route3);
		self::assertNotNull($route4);
		self::assertNotNull($route5);
		self::assertNotNull($route6);
		self::assertNotNull($route7);

		// Test names
		self::assertEquals('grouped-get', $route1->getName());
		self::assertEquals('', $route2->getName());
		self::assertEquals('grouped-get-hello', $route3->getName());
		self::assertEquals('grouped-get-id', $route4->getName());
		self::assertEquals('', $route5->getName());
		self::assertEquals('', $route6->getName());
		self::assertEquals('', $route7->getName());
	}

	public function testNameInvalid(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot call RouteGroup::name() without first creating a route in the group.');
		self::getRouter()->group()
		    ->name('asda');
	}

	public function testNestedGroups(): void {
		self::getRouter()->group('parent')
		    ->get('/', [DummyController::class, 'action'])
		    ->group('nested1')
		    ->get('/', [DummyController::class, 'action'])
		    ->endGroup()
		    ->group('nested2')
		    ->post('/', [DummyController::class, 'action'])
		    ->group('more')
		    ->get('/', [DummyController::class, 'action'])
		    ->get('{id}', [DummyController::class, 'actionWithParams']);

		$route1 = Router::getRoute(RequestMethod::GET, ['parent']);
		$route2 = Router::getRoute(RequestMethod::GET, ['parent', 'nested1']);
		$route3 = Router::getRoute(RequestMethod::POST, ['parent', 'nested2']);
		$route4 = Router::getRoute(RequestMethod::GET, ['parent', 'nested2', 'more']);
		$route5 = Router::getRoute(RequestMethod::GET, ['parent', 'nested2', 'more', '1']);

		// Test that the groups exist
		self::assertNotNull($route1);
		self::assertNotNull($route2);
		self::assertNotNull($route3);
		self::assertNotNull($route4);
		self::assertNotNull($route5);
	}

	public function testNestedGroupsInvalidEnd(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot end group, because it has no parent.');
		self::getRouter()->group('parent')
		    ->endGroup();
	}

}