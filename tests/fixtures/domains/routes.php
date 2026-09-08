<?php

declare(strict_types=1);

use Lsr\Core\Routing\AliasRoute;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;
use Lsr\Enums\RequestMethod;

$GLOBALS['domain-route-loads'] = ($GLOBALS['domain-route-loads'] ?? 0) + 1;
$shared = new NamedMiddleware('domain-shared');

$this->get('/shared', [DummyController::class, 'action'])->name('domain.fallback');
$this->post('/shared', [DummyController::class, 'action'])->name('domain.fallback.post');
$this->domain('primary')->get('/shared', [DummyController::class, 'action'])
    ->middleware($shared)->name('domain.primary');
$this->domain('secondary')->get('/shared', [DummyController::class, 'action'])
    ->middleware($shared)->name('domain.secondary');
$this->domain('primary')->get('/article/{id}', [DummyController::class, 'action'])
    ->name('domain.article')->localize('cs')->localize('en', '/article-en/{id}')
    ->redirectFrom('/old-article/{id}');
$article = $this->getRouteByName('domain.article');
assert($article instanceof Route);
$crossDomain = AliasRoute::createAlias(RequestMethod::GET, '/cross-domain/{id}', $article);
$crossDomain->setDomain('secondary');
$this->register($crossDomain);
$this->domain('merged')->get('/merged', [DummyController::class, 'action'])->name('domain.merged');
$this->domain('primary.example')->get('/literal', [DummyController::class, 'action'])->name('domain.literal');
$this->domain('123')->get('/numeric', [DummyController::class, 'action'])->name('domain.numeric');
$this->domain('456')->get('/numeric-alias', [DummyController::class, 'action'])->name('domain.numeric-alias');

$this->declareDomain('PRIMARY.EXAMPLE.', 'primary');
$this->declareDomain('secondary.example', 'secondary');
$this->declareDomain('primary.example', 'merged');
$this->declareDomain('primary.example', '456');
