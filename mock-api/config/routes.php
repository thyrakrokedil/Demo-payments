<?php

declare(strict_types=1);

use MockPay\Http\Action\ActivatePaymentAction;
use MockPay\Http\Action\CancelPaymentAction;
use MockPay\Http\Action\CreatePaymentAction;
use MockPay\Http\Action\GetPaymentAction;
use MockPay\Http\Action\HealthAction;
use MockPay\Http\Action\ListCallbackAttemptsAction;
use MockPay\Http\Action\ListPaymentsAction;
use MockPay\Http\Action\RefundPaymentAction;
use MockPay\Http\Action\ShowPaymentPageAction;
use MockPay\Http\Action\SubmitPaymentPageAction;
use MockPay\Http\Action\UpdatePaymentAction;
use MockPay\Http\Middleware\BasicAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * The route table. The actions are referenced by class name so Slim resolves
 * them lazily from the container when the route is actually hit.
 *
 * @return callable(App<Psr\Container\ContainerInterface>): void
 */
return static function (App $app): void {
    $app->get('/health', HealthAction::class);

    // Not a static closure: Slim binds the group callable to the container.
    $app->group('/payments', function (RouteCollectorProxy $group): void {
        $group->post('', CreatePaymentAction::class);
        $group->get('', ListPaymentsAction::class);
        $group->get('/{id}', GetPaymentAction::class);
        $group->patch('/{id}', UpdatePaymentAction::class);
        $group->post('/{id}/cancel', CancelPaymentAction::class);
        $group->post('/{id}/activate', ActivatePaymentAction::class);
        $group->post('/{id}/refund', RefundPaymentAction::class);
        $group->get('/{id}/callbacks', ListCallbackAttemptsAction::class);
    })->add(BasicAuthMiddleware::class);

    // The customer-facing test payment page: public, like a hosted checkout.
    $app->get('/pay/{id}', ShowPaymentPageAction::class);
    $app->post('/pay/{id}/{outcome:pay|fail|cancel}', SubmitPaymentPageAction::class);
};
