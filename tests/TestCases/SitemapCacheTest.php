<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Sitemap\SitemapChangeFrequency;
use Lsr\Core\Routing\Sitemap\SitemapEntry;
use Lsr\Core\Routing\Sitemap\SitemapMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SitemapCacheTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
        unset($GLOBALS['sitemap-route-loads']);
    }

    protected function tearDown(): void
    {
        Router::$availableRoutes = [];
        Router::$namedRoutes = [];
        unset($GLOBALS['sitemap-route-loads']);
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    #[DataProvider('inclusionPolicies')]
    public function testWarmCachePreservesDiscoveryInheritanceAndLocalizedEntries(bool $defaultIncluded): void
    {
        $source = ROOT . 'routes/sitemap-routes.php';
        $cache = new CompiledRouteCache($this->temporaryCacheFile(), routeSources: [$source]);
        $cold = new Router([$source], compiledRouteCache: $cache, sitemapDefaultIncluded: $defaultIncluded);
        $cold->setup();
        $expected = $this->snapshot($cold);
        self::assertEqualsCanonicalizing([null, 'news'], $cold->getSitemapNames());
        self::assertSame(
            ['/sitemap/news/nested/{id}'],
            array_map(static fn(Route $route): string => $route->getReadable(), $cold->getSitemapRoutes('news')),
        );
        self::assertEqualsCanonicalizing(
            ['/sitemap/news/nested/{id}', '/en/sitemap/news/{id}', '/choose/sitemap/news/{id}'],
            array_map(static fn(SitemapEntry $entry): string => $entry->route->getReadable(), $cold->getSitemapEntries('news')),
        );
        $root = $cold->getRouteByName('sitemap-localized');
        self::assertInstanceOf(Route::class, $root);
        self::assertSame(0.6, $root->getSitemapMetadata()->priority);
        self::assertSame(SitemapChangeFrequency::MONTHLY, $root->getSitemapMetadata()->changefreq);
        self::assertEquals(
            ['section' => 'news', 'nested' => ['child' => true], 'nullable' => null, 'late' => ['one', 2, false, null]],
            $root->getMeta(),
        );

        $warm = new Router([$source], compiledRouteCache: $cache, sitemapDefaultIncluded: $defaultIncluded);
        $warm->setup();
        self::assertSame(1, $GLOBALS['sitemap-route-loads'], 'The second router must hydrate without loading route sources.');
        self::assertEquals($expected, $this->snapshot($warm));
    }

    #[DataProvider('inclusionPolicies')]
    public function testCacheReuseResolvesUndeclaredInclusionAgainstTheNewRouterPolicy(bool $coldDefault): void
    {
        $source = ROOT . 'routes/sitemap-routes.php';
        $cache = new CompiledRouteCache($this->temporaryCacheFile(), routeSources: [$source]);
        $cold = new Router([$source], compiledRouteCache: $cache, sitemapDefaultIncluded: $coldDefault);
        $cold->setup();
        $warm = new Router([$source], compiledRouteCache: $cache, sitemapDefaultIncluded: !$coldDefault);
        $warm->setup();

        self::assertSame(1, $GLOBALS['sitemap-route-loads'], 'Changing inclusion policy must reuse the compiled declarations.');
        self::assertEqualsCanonicalizing(
            $coldDefault ? ['/sitemap/default'] : ['/sitemap/default', '/sitemap/implicit'],
            array_map(static fn(Route $route): string => $route->getReadable(), $warm->getSitemapRoutes()),
        );
        self::assertSame([], $warm->getSitemapRoutes('private'));
        self::assertEqualsCanonicalizing([null, 'news'], $warm->getSitemapNames());
        $expected = $this->snapshot($warm);
        $uncached = new Router([$source], sitemapDefaultIncluded: !$coldDefault);
        $uncached->setup();
        self::assertEquals($this->snapshot($uncached), $expected);
    }

    public function testHydratedLocalizedFamilyFollowsLaterRootDeclarations(): void
    {
        $source = ROOT . 'routes/sitemap-routes.php';
        $cache = new CompiledRouteCache($this->temporaryCacheFile(), routeSources: [$source]);
        (new Router([$source], compiledRouteCache: $cache))->setup();
        $router = new Router([$source], compiledRouteCache: $cache);
        $router->setup();
        self::assertSame(1, $GLOBALS['sitemap-route-loads']);
        $root = $router->getRouteByName('sitemap-localized');
        self::assertInstanceOf(Route::class, $root);
        $root->sitemap('archive')->priority(0.1)->changefreq('yearly')->meta(['section' => 'archive']);
        self::assertSame([], $router->getSitemapEntries('news'));
        self::assertEqualsCanonicalizing(
            ['/sitemap/news/nested/{id}', '/en/sitemap/news/{id}', '/choose/sitemap/news/{id}'],
            array_map(static fn(SitemapEntry $entry): string => $entry->route->getReadable(), $router->getSitemapEntries('archive')),
        );
        foreach ($router->getSitemapEntries('archive') as $entry) {
            self::assertEquals($root->getSitemapMetadata(), $entry->metadata);
            self::assertEquals($entry->metadata, $entry->route->getSitemapMetadata());
            self::assertSame($root->getMeta(), $entry->route->getMeta());
            self::assertContains($entry->route, $entry->alternates);
        }
        $root->sitemapExclude();
        self::assertSame([], $router->getSitemapEntries('archive'));
    }

    public function testCachePreservesMetadataOnNonSitemapRoutesAndLocalizedPaths(): void
    {
        $source = ROOT . 'routes/sitemap-routes.php';
        $cache = new CompiledRouteCache($this->temporaryCacheFile(), routeSources: [$source]);
        (new Router([$source], compiledRouteCache: $cache))->setup();
        $warm = new Router([$source], compiledRouteCache: $cache);
        $warm->setup();
        self::assertSame(1, $GLOBALS['sitemap-route-loads']);

        $post = $warm->getRouteByName('metadata.submit');
        $page = $warm->getRouteByName('metadata.page');
        self::assertInstanceOf(Route::class, $post);
        self::assertInstanceOf(Route::class, $page);
        self::assertSame(['policy' => 'internal', 'audit' => true], $post->getMeta());
        self::assertSame(['policy' => 'internal'], $page->getMeta());
        self::assertNotContains($page, $warm->getSitemapRoutes());

        $english = $page->getRouteForLocale('en');
        self::assertInstanceOf(Route::class, $english);
        self::assertSame($page->getMeta(), $english->getMeta());
        $page->meta(['policy' => 'updated']);
        self::assertSame(['policy' => 'updated'], $english->getMeta());
        self::assertSame(['policy' => 'internal', 'audit' => true], $post->getMeta());
    }

    public static function inclusionPolicies(): iterable
    {
        yield 'opt in' => [false];
        yield 'opt out' => [true];
    }

    /**
     * @return list<array{
     *     name: ?string,
     *     routes: list<string>,
     *     entries: array<string, array{
     *         metadata: SitemapMetadata, meta: array<string,mixed>, alternates: array<string,string>
     *     }>
     * }>
     */
    private function snapshot(Router $router): array
    {
        $names = $router->getSitemapNames();
        sort($names);
        $snapshot = [];
        foreach ($names as $name) {
            $routes = array_map(static fn(Route $route): string => $route->getReadable(), $router->getSitemapRoutes($name));
            sort($routes);
            $entries = [];
            foreach ($router->getSitemapEntries($name) as $entry) {
                $alternates = array_map(static fn(Route $route): string => $route->getReadable(), $entry->alternates);
                ksort($alternates);
                $entries[$entry->route->getReadable()] = [
                    'metadata' => $entry->metadata,
                    'meta' => $entry->route->getMeta(),
                    'alternates' => $alternates,
                ];
            }
            ksort($entries);
            $snapshot[] = ['name' => $name, 'routes' => $routes, 'entries' => $entries];
        }
        return $snapshot;
    }

    private function temporaryCacheFile(): string
    {
        $directory = sys_get_temp_dir() . '/lsr-sitemap-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $file = $directory . '/routes.php';
        $this->temporaryPaths[] = $directory;
        $this->temporaryPaths[] = $file . '.lock';
        $this->temporaryPaths[] = $file;
        return $file;
    }
}
