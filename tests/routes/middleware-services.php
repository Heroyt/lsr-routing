<?php

declare(strict_types=1);

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;

$this->middlewareGroup('services', $this->serviceRef(NamedMiddleware::class));
$this->get('/service/{id}', [DummyController::class, 'action'])
    ->middleware('services', $this->serviceRef('middleware.audit'))
    ->param('id', $this->serviceRef('validator.accept'))
    ->name('middleware-services');
