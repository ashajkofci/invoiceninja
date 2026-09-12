<?php

$publicPath = getcwd();
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$file = realpath($publicPath.$uri);
$publicPrefix = rtrim(realpath($publicPath) ?: $publicPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

if ($uri !== '/' && $file && str_starts_with($file, $publicPrefix) && is_file($file)) {
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'wasm') {
        header('Content-Type: application/wasm');
        header('Content-Length: '.filesize($file));
        readfile($file);

        return true;
    }

    return false;
}

$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
