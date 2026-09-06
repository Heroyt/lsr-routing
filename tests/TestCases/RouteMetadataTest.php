<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Enums\RequestMethod;
use PHPUnit\Framework\TestCase;

final class RouteMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
    }

    protected function tearDown(): void
    {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
    }

    public function testNonGetRoutesExposeLiveGroupMetadataWithoutSitemapDeclarations(): void
    {
        $router = new Router();
        $group = $router->group('/api')->meta(['policy' => 'initial', 'nested' => ['parent' => true]]);
        $nested = $group->group('/jobs')->meta(['section' => 'jobs']);
        $nested->post('/submit', [DummyController::class, 'action'])
            ->name('submit')->meta(['nested' => ['child' => true], 'nullable' => null]);
        $nested->delete('/cancel', [DummyController::class, 'action'])->name('cancel');
        $group->metaAll(['policy' => 'updated', 'nullable' => 'parent']);

        $submit = Router::getRoute(RequestMethod::POST, ['api', 'jobs', 'submit']);
        $cancel = $router->getRouteByName('cancel');
        self::assertInstanceOf(Route::class, $submit);
        self::assertInstanceOf(Route::class, $cancel);
        self::assertEquals(
            ['policy' => 'updated', 'nested' => ['child' => true], 'section' => 'jobs', 'nullable' => null],
            $submit->getMeta(),
        );
        self::assertEquals(
            ['policy' => 'updated', 'nested' => ['parent' => true], 'section' => 'jobs', 'nullable' => 'parent'],
            $cancel->getMeta(),
        );
        self::assertSame([], $router->getSitemapNames());
    }

    public function testApplicationKeysCannotOverrideSitemapSettingsAndExclusionKeepsMetadata(): void
    {
        $router = new Router();
        $route = $router->get('/page', [DummyController::class, 'action'])
            ->sitemap('public')->priority(0.7)->changefreq('weekly');
        $sitemap = $route->getSitemapMetadata();
        $data = ['included' => false, 'name' => 'application', 'priority' => 'urgent', 'changefreq' => 'on-demand'];
        $route->meta($data);
        self::assertEquals($sitemap, $route->getSitemapMetadata());
        self::assertSame([$route], $router->getSitemapRoutes('public'));
        $route->sitemapExclude();
        self::assertSame($data, $route->getMeta());
        self::assertSame([], $router->getSitemapNames());
    }

    public function testMetadataAttributeWorksWithoutSitemapAttributeOnPostRoutes(): void
    {
        $router = new Router(controllers: [ROOT . 'src/SitemapController.php']);
        $router->setup();
        $route = $router->getRouteByName('attribute.audit');
        self::assertInstanceOf(Route::class, $route);
        self::assertSame(['audit' => 'write'], $route->getMeta());
        self::assertNotContains($route, $router->getSitemapRoutes());
    }
}
