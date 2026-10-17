<?php

/* -------------- Translations --------------*/

// every file in i18n/ is one panel language, named by its locale code
$translations = [];

foreach (glob(__DIR__ . '/../i18n/*.php') as $file) {
	$translations[basename($file, '.php')] = require $file;
}

return $translations;
