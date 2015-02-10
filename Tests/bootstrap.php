<?php

ini_set('memory_limit', '256M');

if (file_exists(__DIR__ . '/config.php')) {
	require_once __DIR__ . '/config.php';
}

function setupConst($name, $default=null)
{
	defined($name) || define($name, getenv($name)? getenv($name) : $default);
}

setupConst('GOOGLE_ID');
setupConst('GOOGLE_NAME');
setupConst('EMAIL');
setupConst('ACCESS_TOKEN');
setupConst('REFRESH_TOKEN');

require_once __DIR__ . '/../vendor/autoload.php';
