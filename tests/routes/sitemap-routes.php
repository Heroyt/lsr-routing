<?php

declare(strict_types=1);

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;

$GLOBALS['sitemap-route-loads'] = ($GLOBALS['sitemap-route-loads'] ?? 0) + 1;

$this->get('/sitemap/implicit', [DummyController::class, 'action'])->name('sitemap-implicit')
    ->priority(0.3)->meta(['audience' => 'public']);
$this->get('/sitemap/default', [DummyController::class, 'action'])->name('sitemap-default')->sitemap();
$this->get('/sitemap/excluded', [DummyController::class, 'action'])->name('sitemap-excluded')
    ->sitemap('private')->sitemapExclude();
$this->post('/sitemap/post', [DummyController::class, 'action'])->sitemap('post-only');

$group = $this->group('/sitemap/news')->sitemapAll('news')->priorityAll(0.4)
    ->changefreqAll('weekly')->metaAll(['section' => 'news', 'nested' => ['inherited' => true]]);
$nested = $group->group('/nested')->metaAll(['nested' => ['child' => true], 'nullable' => null]);
$nested->get('/{id}', [DummyController::class, 'action'])->name('sitemap-localized')
    ->localize('cs_CZ')->localize('en_US', '/en/sitemap/news/{id}')
    ->localize('x-default', '/choose/sitemap/news/{id}')->redirectFrom('/legacy/sitemap/news/{id}');
$group->priorityAll(0.6)->changefreqAll('monthly')->metaAll(['late' => ['one', 2, false, null]]);

$this->group('/metadata')->meta(['policy' => 'internal'])
    ->post('/submit', [DummyController::class, 'action'])->name('metadata.submit')->meta(['audit' => true])
    ->get('/page', [DummyController::class, 'action'])->name('metadata.page')
    ->localize('cs')->localize('en', '/en/metadata/page')->sitemapExclude();
