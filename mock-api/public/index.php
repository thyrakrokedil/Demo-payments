<?php

declare(strict_types=1);

use MockPay\Bootstrap;

// When used as a router script for `php -S`, let the built-in server serve
// existing static files itself.
if (\PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);

    if (is_string($path) && '/' !== $path && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$container = Bootstrap::createContainer();
$app = Bootstrap::createApp($container);

$app->run();
