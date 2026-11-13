<?php

declare(strict_types=1);

namespace MockPay;

use DI\Container;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Wires the container and the Slim app. Shared by the web entry point, the CLI and the tests.
 */
final class Bootstrap
{
    /**
     * @param array<string, mixed> $overrides settings keys (e.g. 'db.path') and/or
     *                                        container definitions keyed by class name
     */
    public static function createContainer(array $overrides = []): ContainerInterface
    {
        /** @var array<string, mixed> $settings */
        $settings = require \dirname(__DIR__) . '/config/settings.php';

        $definitionOverrides = [];

        foreach ($overrides as $key => $value) {
            if (\array_key_exists($key, $settings)) {
                $settings[$key] = $value;

                continue;
            }

            $definitionOverrides[$key] = $value;
        }

        /** @var callable(array<string, mixed>): array<string, mixed> $definitionsFactory */
        $definitionsFactory = require \dirname(__DIR__) . '/config/container.php';

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions($definitionsFactory($settings));

        $container = $builder->build();

        foreach ($definitionOverrides as $key => $value) {
            $container->set($key, $value);
        }

        return $container;
    }

    /**
     * @return App<ContainerInterface>
     */
    public static function createApp(ContainerInterface $container): App
    {
        if ($container instanceof Container && $container->has(ResponseFactoryInterface::class)) {
            /** @var ResponseFactoryInterface $responseFactory */
            $responseFactory = $container->get(ResponseFactoryInterface::class);
            AppFactory::setResponseFactory($responseFactory);
        }

        $app = AppFactory::createFromContainer($container);

        /** @var callable(App<ContainerInterface>): void $middleware */
        $middleware = require \dirname(__DIR__) . '/config/middleware.php';
        $middleware($app);

        /** @var callable(App<ContainerInterface>): void $routes */
        $routes = require \dirname(__DIR__) . '/config/routes.php';
        $routes($app);

        return $app;
    }
}
