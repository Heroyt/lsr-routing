<?php

use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;

$this->get('/loaded', [DummyController::class, 'action'])->name('get-loaded');
$this->post('/loaded', [DummyController::class, 'action']);
$this->delete('/loaded', [DummyController::class, 'action'])->name('delete-loaded');
$this->get('/loaded/{id}', [DummyController::class, 'action'])->localize('cs', 'nahrano/{id}');
$this->post('/loaded/{id}', [DummyController::class, 'action']);

$this->group('/settings')
	->get('/', [DummyController::class, 'action'])->name('settings')->localize('cs', 'nastaveni')
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

$this->get('[lang=cs]/optional', [DummyController::class, 'action']);
$this->get('[lang=cs]/optional2', [DummyController::class, 'action']);
$this->get('optional-no-default/[param]/hi', [DummyController::class, 'action']);
$this->get('optional-no-default/[param]/hello', [DummyController::class, 'action']);
