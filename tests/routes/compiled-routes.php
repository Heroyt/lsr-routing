<?php

declare(strict_types=1);

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;
use Lsr\Core\Routing\Tests\Mockup\NamedValidator;

$GLOBALS['compiled-route-loads'] = ($GLOBALS['compiled-route-loads'] ?? 0) + 1;
$shared = new NamedMiddleware('compiled-shared');

$this->get('/compiled/{id}', [DummyController::class, 'action'])
    ->middleware($shared)
    ->param('id', new NamedValidator('compiled-validator'))
    ->name('compiled-route')
    ->localize('cs')
    ->localize('en', '/compiled-en/{id}')
    ->redirectFrom('/compiled-legacy/{id}');

$this->get('/compiled-second', [DummyController::class, 'action'])
    ->middleware($shared)
    ->name('compiled-second');

$this->get('/compiled-closure', static fn () => null)
    ->name('compiled-closure');
