<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use InvalidArgumentException;
use Lsr\Core\Routing\Exceptions\DuplicateRouteException;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\Sitemap\SitemapChangeFrequency;
use Lsr\Core\Routing\Sitemap\SitemapEntry;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SitemapTest extends TestCase
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

    #[DataProvider('inclusionPolicies')]
    public function testDiscoveryIncludesOnlyEligibleLogicalGetRoots(bool $defaultIncluded): void
    {
        $router = new Router(sitemapDefaultIncluded: $defaultIncluded);
        $implicit = $router->get('/implicit', [DummyController::class, 'action'])
            ->priority(0.5)->changefreq('daily')->meta(['audience' => 'public']);
        $explicit = $router->get('/explicit', [DummyController::class, 'action'])->sitemap()
            ->localize('cs')->localize('en', '/en/explicit')->redirectFrom('/legacy');
        $router->get('/excluded', [DummyController::class, 'action'])->sitemapExclude();
        $router->post('/explicit', [DummyController::class, 'action'])->sitemap();
        $router->head('/explicit', [DummyController::class, 'action'])->sitemap();

        self::assertEqualsCanonicalizing(
            $defaultIncluded ? [$implicit, $explicit] : [$explicit],
            $router->getSitemapRoutes(),
        );
        self::assertEqualsCanonicalizing(
            $defaultIncluded ? ['/implicit', '/explicit', '/en/explicit'] : ['/explicit', '/en/explicit'],
            array_map(static fn(SitemapEntry $entry): string => $entry->route->getReadable(), $router->getSitemapEntries()),
        );
    }

    public static function inclusionPolicies(): iterable
    {
        yield 'opt in' => [false];
        yield 'opt out' => [true];
    }

    public function testNestedOverridesStayLiveWhenAncestorsChangeAfterRouteCreation(): void
    {
        $router = new Router();
        $outer = $router->group('/outer')->sitemapAll('public')->priorityAll(0.2)
            ->changefreqAll('weekly')->metaAll(['outer' => true, 'nested' => ['parent' => true]]);
        $inner = $outer->group('/inner')->priorityAll(0.6)->metaAll(['inner' => true]);
        $inner->get('/before', [DummyController::class, 'action'])->name('before');
        $inner->get('/explicit', [DummyController::class, 'action'])->name('explicit')
            ->sitemap('special')->priority(0.9)->changefreq(SitemapChangeFrequency::NEVER)
            ->meta(['nested' => ['child' => true], 'nullable' => null]);
        $outer->sitemapExcludeAll()->priorityAll(0.3)->changefreqAll('daily')
            ->metaAll(['outer' => false, 'nullable' => 'parent']);
        $inner->get('/after', [DummyController::class, 'action'])->name('after');
        $before = $router->getRouteByName('before');
        $after = $router->getRouteByName('after');
        $explicit = $router->getRouteByName('explicit');
        self::assertInstanceOf(Route::class, $before);
        self::assertInstanceOf(Route::class, $after);
        self::assertInstanceOf(Route::class, $explicit);

        self::assertEquals($before->getSitemapMetadata(), $after->getSitemapMetadata());
        self::assertSame([], $router->getSitemapRoutes('public'));
        self::assertSame([$explicit], $router->getSitemapRoutes('special'));
        self::assertSame(0.6, $before->getSitemapMetadata()->priority);
        self::assertSame(SitemapChangeFrequency::DAILY, $before->getSitemapMetadata()->changefreq);
        self::assertSame(0.9, $explicit->getSitemapMetadata()->priority);
        self::assertSame(SitemapChangeFrequency::NEVER, $explicit->getSitemapMetadata()->changefreq);
        self::assertEquals(
            ['outer' => false, 'nested' => ['child' => true], 'nullable' => null, 'inner' => true],
            $explicit->getMeta(),
        );

        $inner->sitemapAll()->priorityAll(0.7);
        self::assertEqualsCanonicalizing([$before, $after], $router->getSitemapRoutes('public'));
        self::assertSame(0.7, $before->getSitemapMetadata()->priority);
        self::assertSame(0.7, $after->getSitemapMetadata()->priority);
        self::assertSame(0.9, $explicit->getSitemapMetadata()->priority);
    }

    public function testGroupFluentDeclarationsTargetActiveRouteButAllUpdatesDefaults(): void
    {
        $router = new Router();
        $group = $router->group('/group')->sitemap('catalog')->priority(0.2)
            ->changefreq('weekly')->meta(['shared' => 'initial']);
        $group->get('/first', [DummyController::class, 'action'])->name('first')
            ->sitemapExclude()->priority(0.8)->changefreq('never')->meta(['shared' => 'local']);
        $group->get('/second', [DummyController::class, 'action'])->name('second');
        $group->sitemapAll('updated')->priorityAll(0.4)->changefreqAll('daily')->metaAll(['shared' => 'updated']);
        $group->get('/third', [DummyController::class, 'action'])->name('third');
        $first = $router->getRouteByName('first');
        $second = $router->getRouteByName('second');
        $third = $router->getRouteByName('third');
        self::assertInstanceOf(Route::class, $first);
        self::assertInstanceOf(Route::class, $second);
        self::assertInstanceOf(Route::class, $third);

        self::assertEqualsCanonicalizing([$second, $third], $router->getSitemapRoutes('updated'));
        self::assertEquals($second->getSitemapMetadata(), $third->getSitemapMetadata());
        self::assertSame(0.4, $second->getSitemapMetadata()->priority);
        self::assertSame(SitemapChangeFrequency::DAILY, $second->getSitemapMetadata()->changefreq);
        self::assertSame(['shared' => 'updated'], $second->getMeta());
        self::assertSame(0.8, $first->getSitemapMetadata()->priority);
        self::assertSame(SitemapChangeFrequency::NEVER, $first->getSitemapMetadata()->changefreq);
        self::assertSame(['shared' => 'local'], $first->getMeta());
        $first->sitemap();
        self::assertContains($first, $router->getSitemapRoutes('updated'));
    }

    public function testNamedDiscoveryListsOnlyOccupiedNamesAndNullSelectsDefault(): void
    {
        $router = new Router();
        $default = $router->get('/default', [DummyController::class, 'action'])->sitemap();
        $named = $router->get('/named', [DummyController::class, 'action'])->sitemap('news')->sitemap();
        $router->get('/hidden', [DummyController::class, 'action'])->sitemap('hidden')->sitemapExclude();
        $router->post('/post', [DummyController::class, 'action'])->sitemap('post-only');

        self::assertSame([$default], $router->getSitemapRoutes());
        self::assertSame([$named], $router->getSitemapRoutes('news'));
        self::assertEqualsCanonicalizing([null, 'news'], $router->getSitemapNames());
        $named->sitemapExclude();
        self::assertSame([null], $router->getSitemapNames());
        $named->sitemap()->sitemap('renamed');
        self::assertSame([], $router->getSitemapRoutes('news'));
        self::assertSame([$named], $router->getSitemapRoutes('renamed'));
    }

    public function testLocalizedEntriesShareNormalizedAlternatesAndFollowParentMutations(): void
    {
        $router = new Router();
        $root = $router->get('/page/{id}', [DummyController::class, 'action'])->sitemap()
            ->localize('cs_CZ')->localize('en_US', '/en/page/{id}')
            ->localize('x-default', '/choose/page/{id}')->redirectFrom('/old/{id}', 'en_US');
        $root->priority(0.7)->changefreq('monthly')->meta(['kind' => 'page']);
        $alternates = [
            'cs-cz' => $root,
            'en-us' => $root->getRouteForLocale('en_US'),
            'x-default' => $root->getRouteForLocale('x-default'),
        ];
        $entries = $router->getSitemapEntries();
        self::assertEqualsCanonicalizing(
            ['/page/{id}', '/en/page/{id}', '/choose/page/{id}'],
            array_map(static fn(SitemapEntry $entry): string => $entry->route->getReadable(), $entries),
        );
        foreach ($entries as $entry) {
            self::assertEqualsCanonicalizing(array_keys($alternates), array_keys($entry->alternates));
            foreach ($alternates as $locale => $route) {
                self::assertSame($route, $entry->alternates[$locale]);
            }
            self::assertContains($entry->route, $entry->alternates);
            self::assertEquals($root->getSitemapMetadata(), $entry->metadata);
            self::assertEquals($entry->metadata, $entry->route->getSitemapMetadata());
        }
        self::assertSame([$root], $router->getSitemapRoutes());
        $root->sitemapExclude();
        self::assertSame([], $router->getSitemapEntries());
    }

    public function testUnlocalizedParentIsAnEntryWithoutInventingAnAlternate(): void
    {
        $router = new Router();
        $root = $router->get('/page', [DummyController::class, 'action'])->sitemap()
            ->localize('en_GB', '/en/page');
        $entries = $router->getSitemapEntries();
        self::assertEqualsCanonicalizing(
            ['/page', '/en/page'],
            array_map(static fn(SitemapEntry $entry): string => $entry->route->getReadable(), $entries),
        );
        foreach ($entries as $entry) {
            self::assertSame(['en-gb' => $root->getRouteForLocale('en_GB')], $entry->alternates);
        }
    }

    public function testUnregisteredTranslationIsRemovedFromEveryAlternateMap(): void
    {
        $router = new Router();
        $root = $router->get('/page', [DummyController::class, 'action'])->sitemap()
            ->localize('cs')->localize('en', '/en/page')->localize('de', '/de/page');
        $english = $root->getRouteForLocale('en');
        self::assertInstanceOf(Route::class, $english);
        $router->unregister($english);

        $entries = $router->getSitemapEntries();
        self::assertSame(
            ['/page', '/de/page'],
            array_map(static fn(SitemapEntry $entry): string => $entry->route->getReadable(), $entries),
        );
        foreach ($entries as $entry) {
            self::assertSame(['cs' => $root, 'de' => $root->getRouteForLocale('de')], $entry->alternates);
        }
    }

    public function testNormalizedLocaleCollisionCannotSilentlyReplaceAnAlternate(): void
    {
        $router = new Router();
        $router->get('/page', [DummyController::class, 'action'])->sitemap()
            ->localize('en_US')->localize('en-us', '/other-page');
        $this->expectException(InvalidArgumentException::class);
        $router->getSitemapEntries();
    }

    public function testMetadataSnapshotsDetachCallerReferencesAndLaterDeclarations(): void
    {
        $router = new Router();
        $value = 'original';
        $nested = ['value' => &$value];
        $data = ['nested' => &$nested];
        $route = $router->get('/page', [DummyController::class, 'action'])->meta($data);
        $snapshot = $route->getMeta();
        $value = 'external mutation';
        $nested['extra'] = true;
        self::assertSame(['nested' => ['value' => 'original']], $route->getMeta());
        $route->meta(['nested' => ['value' => 'new declaration']]);
        self::assertSame(['nested' => ['value' => 'original']], $snapshot);
        self::assertSame(['nested' => ['value' => 'new declaration']], $route->getMeta());
    }

    public function testUnregisterAndFailedDuplicateRegistrationDoNotLeavePhantomRoutes(): void
    {
        $router = new Router(sitemapDefaultIncluded: true);
        $root = $router->get('/page', [DummyController::class, 'action'])
            ->localize('en', '/en/page')->redirectFrom('/old');
        try {
            $router->get('/page', [DummyController::class, 'actionWithParams']);
            self::fail('A conflicting route must not register.');
        } catch (DuplicateRouteException) {
            self::assertSame([$root], $router->getSitemapRoutes());
        }
        $router->unregister($root);
        self::assertSame([], $router->getSitemapRoutes());
        self::assertSame([], $router->getSitemapEntries());
        self::assertSame([], $router->getSitemapNames());
        $router->get('/next', [DummyController::class, 'action'])->sitemap('next');
        $router->unregisterAll();
        self::assertSame([], $router->getSitemapRoutes('next'));
        self::assertSame([], $router->getSitemapNames());
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationsAreRejected(string $method, mixed $value): void
    {
        $route = (new Router())->get('/page', [DummyController::class, 'action']);
        $this->expectException(InvalidArgumentException::class);
        $route->{$method}($value);
    }

    public static function invalidDeclarations(): iterable
    {
        yield 'negative priority' => ['priority', -0.1];
        yield 'priority above one' => ['priority', 1.1];
        yield 'non finite priority' => ['priority', INF];
        yield 'not a number priority' => ['priority', NAN];
        yield 'unknown frequency' => ['changefreq', 'sometimes'];
        yield 'empty sitemap name' => ['sitemap', ''];
        yield 'blank sitemap name' => ['sitemap', " \t\n"];
        yield 'numeric metadata key' => ['meta', ['value']];
        yield 'nested object' => ['meta', ['nested' => ['object' => new stdClass()]]];
    }

    public function testMetadataRejectsClosuresResourcesAndCycles(): void
    {
        $route = (new Router())->get('/page', [DummyController::class, 'action']);
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $cyclic = [];
        $cyclic['self'] = &$cyclic;
        try {
            foreach ([['callback' => static fn() => null], ['stream' => $resource], ['nested' => $cyclic]] as $metadata) {
                try {
                    $route->meta($metadata);
                    self::fail('Non-cacheable metadata must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame([], $route->getMeta());
                }
            }
        } finally {
            fclose($resource);
            unset($cyclic['self']);
        }
    }

    public function testAttributesApplyToEveryMethodRouteAndExclusionWins(): void
    {
        $router = new Router(controllers: [ROOT . 'src/SitemapController.php'], sitemapDefaultIncluded: true);
        $router->setup();
        self::assertEqualsCanonicalizing(
            ['/attribute/one', '/attribute/two'],
            array_map(static fn(Route $route): string => $route->getReadable(), $router->getSitemapRoutes('articles')),
        );
        self::assertSame([], $router->getSitemapRoutes('excluded'));
        self::assertSame(['articles'], $router->getSitemapNames());
        foreach ($router->getSitemapRoutes('articles') as $route) {
            self::assertSame(0.6, $route->getSitemapMetadata()->priority);
            self::assertSame(SitemapChangeFrequency::WEEKLY, $route->getSitemapMetadata()->changefreq);
            self::assertSame(['section' => 'articles'], $route->getMeta());
        }
    }
}
