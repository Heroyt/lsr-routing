<?php

use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;

Route::get('/loaded', [DummyController::class, 'action'])->name('get-loaded');
Route::post('/loaded', [DummyController::class, 'action']);
Route::delete('/loaded', [DummyController::class, 'action'])->name('delete-loaded');
Route::get('/loaded/{id}', [DummyController::class, 'action']);
Route::post('/loaded/{id}', [DummyController::class, 'action']);
