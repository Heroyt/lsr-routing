<?php
/** @noinspection AutoloadingIssuesInspection */

/** @noinspection PhpIllegalPsrClassPathInspection */


const ROOT = __DIR__.'/';
const PRIVATE_DIR = ROOT.'private/';
const TMP_DIR = ROOT.'tmp/';
const LOG_DIR = ROOT.'logs/';
const LANGUAGE_DIR = ROOT.'languages/';
const TEMPLATE_DIR = ROOT.'templates/';
const LANGUAGE_FILE_NAME = 'translations';
const DEFAULT_LANGUAGE = 'cs_CZ';
const CHECK_TRANSLATIONS = true;
const PRODUCTION = true;
const ASSETS_DIR = ROOT.'assets/';

// Remove cache
if (file_exists(TMP_DIR.'db.db')) {
	unlink(TMP_DIR.'db.db');
}
if (file_exists(TMP_DIR.'cache.db')) {
	unlink(TMP_DIR.'cache.db');
}
if (file_exists(ROOT.'routes/test.php')) {
	unlink(ROOT.'routes/test.php');
}
if (file_exists(ROOT.'src/Controllers/DummyController2.php')) {
	unlink(ROOT.'src/Controllers/DummyController2.php');
}
foreach (array_merge(glob(TMP_DIR.'*.php'), glob(TMP_DIR.'di/*')) as $file) {
	unlink($file);
}

require_once ROOT.'../vendor/autoload.php';

file_put_contents(ROOT.'routes/test.php', "<?php
use Lsr\Core\Routing\Tests\Mockup\Controllers\DummyController;

\$this->get('/loaded', [DummyController::class, 'action'])->name('get-loaded');
\$this->post('/loaded', [DummyController::class, 'action']);
\$this->delete('/loaded', [DummyController::class, 'action'])->name('delete-loaded');
\$this->get('/loaded/{id}', [DummyController::class, 'action'])->localize('cs', 'nahrano/{id}');
\$this->post('/loaded/{id}', [DummyController::class, 'action']);

\$this->group('/settings')
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
");

file_put_contents(ROOT.'src/Controllers/DummyController2.php', '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

}\'');