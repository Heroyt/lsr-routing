<?php
/**
 * @file  config/constants.php
 * @brief Constants that need to be imported into DI container
 */

if (!defined('ROOT')) {
	define('ROOT', dirname(__DIR__).'/');
}

return [
	'parameters' => [
		'constants' => [
			'appDir'  => ROOT,
			'tempDir' => TMP_DIR,
		]
	]
];