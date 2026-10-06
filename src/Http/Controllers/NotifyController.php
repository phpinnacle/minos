<?php

namespace PHPinnacle\Minos\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use PHPinnacle\Minos\Contracts\QueuedGateway;
use PHPinnacle\Minos\Contracts\WebhookGateway;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Services\PaymentManager;
use PHPinnacle\Minos\Services\ProviderRegistry;

class NotifyController extends Controller
{
    public function __invoke(
        string $id,
        Request $request,
        ProviderRegistry $providers,
        PaymentManager $payments,
    ): Response {
        $transaction = Transaction::query()->findOrFail($id);
        $gateway = $providers->get($transaction->method->provider);

        abort_unless($gateway instanceof WebhookGateway && $gateway->acceptsWebhook($transaction, $request), 403);

        $continuation = $gateway->handle(new Notification(
            id: $transaction->id,
            order: $transaction->source_id,
            method: $transaction->method,
            payer: new Payer($transaction->payer_id, $transaction->payer_type),
            payload: $request->input(),
        ));

        if ($gateway instanceof QueuedGateway) {
            if ($transaction->external_id === null) {
                $transaction->handle(Continuation::pending($continuation->externalId, $continuation->metadata));
            }

            $payments->synchronize($transaction);
        } else {
            $transaction->handle($continuation);
        }

        return response()->noContent();
    }
}
