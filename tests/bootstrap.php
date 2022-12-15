<?php
/** @noinspection AutoloadingIssuesInspection */

/** @noinspection PhpIllegalPsrClassPathInspection */

use Lsr\Core\App;
use Lsr\Core\DB;

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
foreach (array_merge(glob(TMP_DIR.'*.php'), glob(TMP_DIR.'di/*')) as $file) {
	unlink($file);
}

require_once ROOT.'../vendor/autoload.php';

App::init();

DB::init();
DB::getConnection()->query("
			CREATE TABLE test ( 
			    id_model integer PRIMARY KEY autoincrement NOT NULL , 
			    name char(60) NOT NULL 
			);
		");

DB::insert('test', ['id_model' => 1, 'name' => 'test1']);
DB::insert('test', ['id_model' => 2, 'name' => 'test2']);
DB::insert('test', ['id_model' => 3, 'name' => 'test3']);