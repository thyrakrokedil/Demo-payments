<?php

declare(strict_types=1);

use function DI\autowire;

use DI\Container;

use function DI\get;

use MockPay\Application\CallbackSigner;
use MockPay\Domain\CallbackAttemptRepository;
use MockPay\Domain\CallbackTransport;
use MockPay\Domain\Clock;
use MockPay\Domain\IdGenerator;
use MockPay\Domain\PaymentRepository;
use MockPay\Http\JsonResponder;
use MockPay\Http\Middleware\BasicAuthMiddleware;
use MockPay\Http\PaymentPresenter;
use MockPay\Http\ProblemDetailsErrorHandler;
use MockPay\Infrastructure\CurlCallbackTransport;
use MockPay\Infrastructure\Persistence\PdoCallbackAttemptRepository;
use MockPay\Infrastructure\Persistence\PdoFactory;
use MockPay\Infrastructure\Persistence\PdoPaymentRepository;
use MockPay\Infrastructure\Persistence\SqliteMigrator;
use MockPay\Infrastructure\RandomIdGenerator;
use MockPay\Infrastructure\SystemClock;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Returns the PHP-DI definitions for the given settings.
 *
 * @return callable(array<string, mixed>): array<string, mixed>
 */
return static function (array $settings): array {
    return [
        'settings' => $settings,

        Psr17Factory::class => autowire(Psr17Factory::class),
        ResponseFactoryInterface::class => get(Psr17Factory::class),
        StreamFactoryInterface::class => get(Psr17Factory::class),
        ServerRequestFactoryInterface::class => get(Psr17Factory::class),
        UploadedFileFactoryInterface::class => get(Psr17Factory::class),
        UriFactoryInterface::class => get(Psr17Factory::class),

        PDO::class => static function (Container $container): PDO {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');

            $path = is_string($settings['db.path'] ?? null) ? $settings['db.path'] : ':memory:';
            $pdo = PdoFactory::create($path);

            if (true === ($settings['db.migrate_on_boot'] ?? false)) {
                $dir = is_string($settings['db.migrations_dir'] ?? null)
                    ? $settings['db.migrations_dir']
                    : dirname(__DIR__) . '/database/migrations';

                (new SqliteMigrator($pdo, $dir))->migrate();
            }

            return $pdo;
        },

        PaymentRepository::class => autowire(PdoPaymentRepository::class),
        CallbackAttemptRepository::class => autowire(PdoCallbackAttemptRepository::class),
        Clock::class => autowire(SystemClock::class),
        IdGenerator::class => autowire(RandomIdGenerator::class),

        CallbackTransport::class => static function (Container $container): CallbackTransport {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');

            return new CurlCallbackTransport(
                is_int($settings['callbacks.timeout'] ?? null) ? $settings['callbacks.timeout'] : 5,
            );
        },

        // The Basic auth password doubles as the merchant's API key that signs callbacks.
        CallbackSigner::class => static function (Container $container): CallbackSigner {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');

            return new CallbackSigner(
                is_string($settings['auth.password'] ?? null) ? $settings['auth.password'] : 'password',
            );
        },

        PaymentPresenter::class => static function (Container $container): PaymentPresenter {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');

            return new PaymentPresenter(
                is_string($settings['app.public_url'] ?? null) ? $settings['app.public_url'] : 'http://localhost:8888',
            );
        },

        LoggerInterface::class => static function (Container $container): LoggerInterface {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');
            $name = is_string($settings['log.level'] ?? null) ? strtolower($settings['log.level']) : 'info';

            $levels = [
                'debug' => Level::Debug,
                'info' => Level::Info,
                'notice' => Level::Notice,
                'warning' => Level::Warning,
                'error' => Level::Error,
                'critical' => Level::Critical,
                'alert' => Level::Alert,
                'emergency' => Level::Emergency,
            ];

            $logger = new Logger('mockpay');
            $logger->pushHandler(new StreamHandler('php://stderr', $levels[$name] ?? Level::Info));

            return $logger;
        },

        BasicAuthMiddleware::class => static function (Container $container): BasicAuthMiddleware {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');

            /** @var JsonResponder $responder */
            $responder = $container->get(JsonResponder::class);

            return new BasicAuthMiddleware(
                is_string($settings['auth.username'] ?? null) ? $settings['auth.username'] : 'username',
                is_string($settings['auth.password'] ?? null) ? $settings['auth.password'] : 'password',
                $responder,
            );
        },

        ProblemDetailsErrorHandler::class => static function (Container $container): ProblemDetailsErrorHandler {
            /** @var array<string, mixed> $settings */
            $settings = $container->get('settings');

            /** @var JsonResponder $responder */
            $responder = $container->get(JsonResponder::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            return new ProblemDetailsErrorHandler($responder, $logger, true === ($settings['app.debug'] ?? false));
        },
    ];
};
