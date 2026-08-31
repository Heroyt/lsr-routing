<?php

declare(strict_types=1);

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;

$this->get('/missing-one', [DummyController::class, 'action'])
    ->middleware('missing-one');
$this->get('/missing-two', [DummyController::class, 'action'])
    ->middleware('missing-two');
