<?php

declare(strict_types=1);

use MockPay\Http\Middleware\JsonBodyParserMiddleware;
use MockPay\Http\Middleware\RequestLoggingMiddleware;
use MockPay\Http\ProblemDetailsErrorHandler;
use Slim\App;

/**
 * Registers the global middleware. Slim runs middleware LIFO, so the last one
 * added is the outermost: the error middleware must therefore be added last in
 * order to also catch the 404/405 raised by the routing middleware.
 *
 * @return callable(App<Psr\Container\ContainerInterface>): void
 */
return static function (App $app): void {
    $container = $app->getContainer();

    if (null === $container) {
        throw new RuntimeException('The Slim app must be created from a container.');
    }

    /** @var array<string, mixed> $settings */
    $settings = $container->get('settings');
    $debug = true === ($settings['app.debug'] ?? false);

    /** @var RequestLoggingMiddleware $logging */
    $logging = $container->get(RequestLoggingMiddleware::class);
    /** @var JsonBodyParserMiddleware $bodyParser */
    $bodyParser = $container->get(JsonBodyParserMiddleware::class);
    /** @var ProblemDetailsErrorHandler $errorHandler */
    $errorHandler = $container->get(ProblemDetailsErrorHandler::class);

    $app->add($logging);
    $app->add($bodyParser);
    $app->addRoutingMiddleware();

    $errorMiddleware = $app->addErrorMiddleware($debug, false, false);
    $errorMiddleware->setDefaultErrorHandler($errorHandler);
};
