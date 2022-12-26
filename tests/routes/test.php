<?php

use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;

Route::get('/loaded', [DummyController::class, 'action'])->name('get-loaded');
Route::post('/loaded', [DummyController::class, 'action']);
Route::delete('/loaded', [DummyController::class, 'action'])->name('delete-loaded');
Route::get('/loaded/{id}', [DummyController::class, 'action']);
Route::post('/loaded/{id}', [DummyController::class, 'action']);

Route::group('/settings')
		 ->get('/', [DummyController::class, 'action'])->name('settings')
		 ->post('/', [DummyController::class, 'action'])
		 ->get('/gate', [DummyController::class, 'action'])->name('settings-gate')
		 ->post('/gate', [DummyController::class, 'action'])
		 ->get('/vests', [DummyController::class, 'action'])->name('settings-vests')
		 ->post('/vests', [DummyController::class, 'action'])
		 ->get('/print', [DummyController::class, 'action'])->name('settings-print')
		 ->post('/print', [DummyController::class, 'action'])
		 ->group('/modes')
		 ->get('/', [DummyController::class, 'action'])
		 ->get('/{system}', [DummyController::class, 'action'])
		 ->post('/{system}', [DummyController::class, 'action'])
		 ->get('/{id}/variations', [DummyController::class, 'action'])
		 ->get('/{id}/settings', [DummyController::class, 'action'])
		 ->get('/{id}/names', [DummyController::class, 'action'])
		 ->post('/{id}/names', [DummyController::class, 'action'])
		 ->get('/variations', [DummyController::class, 'action']);
