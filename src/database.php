<?php

require_once __DIR__ .'/../vendor/autoload.php';
require_once __DIR__ .'/load-env.php';

use Libsql\Database;

$db = (new Database(url: $_ENV['TURSO_DATABASE_URL'], authToken: $_ENV['TURSO_AUTH_TOKEN']));
$db_connection = $db->connect();

$cache_db = new Database(':memory:');
$cache_db_connection = $cache_db->connect();