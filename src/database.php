<?php

require_once __DIR__ .'/../vendor/autoload.php';

use Libsql\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__.'/../');
$dotenv->safeLoad();

$db = (new Database(url: $_ENV['TURSO_DATABASE_URL'], authToken: $_ENV['TURSO_AUTH_TOKEN']));
$db_connection = $db->connect();

$cache_db = new Database(':memory:');
$cache_db_connection = $cache_db->connect();