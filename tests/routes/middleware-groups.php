<?php

declare(strict_types=1);

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;

$shared = new NamedMiddleware('shared');

$this->get('/ordered', [DummyController::class, 'action'])
    ->middleware(new NamedMiddleware('before'), ' Web ', $shared, new NamedMiddleware('after'))
    ->name('middleware-ordered');

$this->middlewareGroup('web', $shared);
$this->middlewareGroup(' WEB ', $shared, new NamedMiddleware('second'));

$this->group('/grouped')
    ->middlewareAll('web')
    ->get('', [DummyController::class, 'action'])
    ->name('middleware-grouped');
