<?php

declare(strict_types=1);

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;
use Lsr\Core\Routing\Tests\Mockup\UnserializableMiddleware;

$this->get('/unserializable', [DummyController::class, 'action'])
    ->middleware(new UnserializableMiddleware())
    ->name('unserializable');
