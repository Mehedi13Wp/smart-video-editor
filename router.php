<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = __DIR__ . '/public';
$file = realpath($public . rawurldecode($path));

if ($path !== '/' && $file !== false && is_file($file) &&
    str_starts_with($file, realpath($public) . DIRECTORY_SEPARATOR)) {
    return false;
}

require $public . '/index.php';
