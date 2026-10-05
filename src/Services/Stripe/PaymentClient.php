<?php

namespace PHPinnacle\Minos\Services\Stripe;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use LogicException;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\GatewayRequest;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Transaction;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use UnexpectedValueException;

readonly class PaymentClient
{
    public function __construct(
        private StripeClient $stripe,
    ) {}

    /** @param array{api_key: string} $settings */
    public static function create(array $settings): self
    {
        return new self(Cashier::stripe(['api_key' => $settings['api_key']]));
    }

    public function prepare(Intent $intent): GatewayRequest
    {
        if ($intent->returnUrl === null || $intent->returnUrl === '') {
            throw new InvalidArgumentException('Stripe payments require a return URL.');
        }

        if ($intent->instrument !== null || $intent->recurring) {
            throw new InvalidArgumentException(
                'Stripe accepts one-time payments with card details collected by Stripe.',
            );
        }

        $amount = $intent->total();
        $payload = [
            'reference' => $intent->id,
            'description' => $intent->description,
            'amount' => $amount->amount,
            'currency' => strtolower($amount->currency),
            'return_url' => $intent->returnUrl,
            'cancel_url' => $intent->cancelUrl,
            'email' => $intent->payer->email,
        ];

        return new GatewayRequest($payload, CarbonImmutable::now()->addHours(23));
    }

    public function derive(Transaction $transaction): GatewayRequest
    {
        if (in_array($transaction->type, [TransactionType::CAPTURE, TransactionType::VOID], true)) {
            if ($transaction->amount->amount !== $transaction->parent->amount->amount) {
                throw new InvalidArgumentException('Stripe requires a full capture or void of the authorization.');
            }

            return new GatewayRequest([
                'payment_intent' => $transaction->parent->external_id,
                'amount' => $transaction->amount->amount,
            ], CarbonImmutable::now()->addHours(23));
        }

        return new GatewayRequest([
            'payment_intent' => $transaction->parent->type === TransactionType::CAPTURE
                ? $transaction->parent->external_id
                : $transaction->parent->metadata['payment_intent'],
            'amount' => $transaction->amount->amount,
            'metadata' => [
                'transaction_id' => $transaction->id,
                'reason' => $transaction->reason,
            ],
        ], CarbonImmutable::now()->addHours(23));
    }

    /** @param array{reference: string, description: string, amount: int, currency: string, return_url: string, cancel_url: ?string, email: ?string} $payload */
    public function payment(array $payload, string $idempotencyKey): Continuation
    {
        $request = [
            'mode' => 'payment',
            'client_reference_id' => $payload['reference'],
            'success_url' => $payload['return_url'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $payload['currency'],
                    'unit_amount' => $payload['amount'],
                    'product_data' => ['name' => $payload['description']],
                ],
                'quantity' => 1,
            ]],
        ];

        if ($payload['cancel_url'] !== null) {
            $request['cancel_url'] = $payload['cancel_url'];
        }

        if ($payload['email'] !== null) {
            $request['customer_email'] = $payload['email'];
        }

        return $this->session($this->stripe->checkout->sessions->create(
            $request,
            ['idempotency_key' => $idempotencyKey],
        ));
    }

    /** @param array{reference: string, description: string, amount: int, currency: string, return_url: string, cancel_url: ?string, email: ?string} $payload */
    public function authorize(array $payload, string $idempotencyKey): Continuation
    {
        $request = [
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'description' => $payload['description'],
            'payment_method_types' => ['card'],
            'capture_method' => 'manual',
            'metadata' => ['transaction_id' => $payload['reference']],
        ];

        if ($payload['email'] !== null) {
            $request['receipt_email'] = $payload['email'];
        }

        return $this->authorizationResult($this->stripe->paymentIntents->create(
            $request,
            ['idempotency_key' => $idempotencyKey],
        ), includeClientSecret: true);
    }

    /** @param array{payment_intent: string, amount: int} $payload */
    public function capture(array $payload, string $idempotencyKey): Continuation
    {
        return $this->paymentIntentResult(
            $this->stripe->paymentIntents->capture(
                $payload['payment_intent'],
                ['amount_to_capture' => $payload['amount']],
                ['idempotency_key' => $idempotencyKey],
            ),
            TransactionType::CAPTURE,
        );
    }

    /** @param array{payment_intent: string} $payload */
    public function void(array $payload, string $idempotencyKey): Continuation
    {
        return $this->paymentIntentResult(
            $this->stripe->paymentIntents->cancel(
                $payload['payment_intent'],
                [],
                ['idempotency_key' => $idempotencyKey],
            ),
            TransactionType::VOID,
        );
    }

    /** @param array<string, mixed> $payload */
    public function refund(array $payload, string $idempotencyKey): Continuation
    {
        return $this->refundResult($this->stripe->refunds->create(
            $payload,
            ['idempotency_key' => $idempotencyKey],
        ));
    }

    public function synchronize(Transaction $transaction): Continuation
    {
        if ($transaction->external_id === null) {
            throw new LogicException('The Stripe operation has no remote identifier.');
        }

        return match ($transaction->type) {
            TransactionType::PAYMENT => $this->session(
                $this->stripe->checkout->sessions->retrieve($transaction->external_id),
            ),
            TransactionType::AUTHORIZE => $this->authorizationResult(
                $this->stripe->paymentIntents->retrieve($transaction->external_id),
                wasAuthorized: $transaction->status === TransactionStatus::Success,
            ),
            TransactionType::CAPTURE, TransactionType::VOID => $this->paymentIntentResult(
                $this->stripe->paymentIntents->retrieve($transaction->external_id),
                $transaction->type,
            ),
            TransactionType::REFUND => $this->refundResult($this->stripe->refunds->retrieve($transaction->external_id)),
        };
    }

    private function session(Session $session): Continuation
    {
        $data = $session->toArray();

        if (
            !is_string($data['id'] ?? null)
            || $data['id'] === ''
            || !is_string($data['status'] ?? null)
            || !is_string($data['payment_status'] ?? null)
        ) {
            throw new UnexpectedValueException('Stripe returned an incomplete Checkout Session.');
        }

        $status = $this->paymentStatus($data);

        if ($status === TransactionStatus::Success && !is_string($data['payment_intent'] ?? null)) {
            throw new UnexpectedValueException('Stripe returned a paid Checkout Session without a PaymentIntent.');
        }

        if ($data['status'] === 'open' && (!is_string($data['url'] ?? null) || $data['url'] === '')) {
            throw new UnexpectedValueException('Stripe returned an open Checkout Session without a URL.');
        }

        $metadata = [];

        if (is_string($data['url'] ?? null)) {
            $metadata['redirect'] = $data['url'];
        }

        if (is_string($data['payment_intent'] ?? null)) {
            $metadata['payment_intent'] = $data['payment_intent'];
        }

        return new Continuation(
            status: $status,
            externalId: $data['id'],
            expiresAt: is_int($data['expires_at'] ?? null)
                ? CarbonImmutable::createFromTimestamp($data['expires_at'])
                : null,
            response: $data,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $data */
    private function paymentStatus(array $data): TransactionStatus
    {
        if ($data['payment_status'] === 'paid') {
            return TransactionStatus::Success;
        }

        if ($data['status'] === 'expired') {
            return TransactionStatus::Failure;
        }

        if ($data['status'] === 'open') {
            return TransactionStatus::Pending;
        }

        if ($data['status'] !== 'complete') {
            throw new UnexpectedValueException('Stripe returned an unknown Checkout Session status.');
        }

        if (!is_string($data['payment_intent'] ?? null)) {
            return TransactionStatus::Pending;
        }

        return match ($this->paymentIntentStatus($data['payment_intent'])) {
            'requires_payment_method', 'canceled' => TransactionStatus::Failure,
            'requires_confirmation', 'requires_action', 'processing' => TransactionStatus::Pending,
            default => throw new UnexpectedValueException('Stripe returned an unexpected PaymentIntent status.'),
        };
    }

    private function authorizationResult(
        PaymentIntent $paymentIntent,
        bool $wasAuthorized = false,
        bool $includeClientSecret = false,
    ): Continuation {
        $data = $paymentIntent->toArray();

        if (!is_string($data['id'] ?? null) || $data['id'] === '' || !is_string($data['status'] ?? null)) {
            throw new UnexpectedValueException('Stripe returned an incomplete PaymentIntent.');
        }

        $status = match ($data['status']) {
            'requires_capture' => TransactionStatus::Success,
            'canceled' => $wasAuthorized ? TransactionStatus::Success : TransactionStatus::Failure,
            'succeeded' => $wasAuthorized
                ? TransactionStatus::Success
                : throw new UnexpectedValueException('Stripe captured the authorization before it was recorded.'),
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'processing',
                => TransactionStatus::Pending,
            default => throw new UnexpectedValueException('Stripe returned an unknown PaymentIntent status.'),
        };

        $metadata = [];

        if ($includeClientSecret) {
            if (!is_string($data['client_secret'] ?? null) || $data['client_secret'] === '') {
                throw new UnexpectedValueException('Stripe returned a PaymentIntent without a client secret.');
            }

            $metadata['client_secret'] = $data['client_secret'];
        }

        return new Continuation(status: $status, externalId: $data['id'], response: $data, metadata: $metadata);
    }

    private function paymentIntentStatus(string $id): string
    {
        $status = $this->stripe->paymentIntents->retrieve($id)->toArray()['status'] ?? null;

        if (!is_string($status)) {
            throw new UnexpectedValueException('Stripe returned an incomplete PaymentIntent.');
        }

        return $status;
    }

    private function paymentIntentResult(PaymentIntent $paymentIntent, TransactionType $type): Continuation
    {
        $data = $paymentIntent->toArray();

        if (!is_string($data['id'] ?? null) || $data['id'] === '' || !is_string($data['status'] ?? null)) {
            throw new UnexpectedValueException('Stripe returned an incomplete PaymentIntent.');
        }

        $status = match ($type) {
            TransactionType::CAPTURE => match ($data['status']) {
                'succeeded' => TransactionStatus::Success,
                'canceled' => TransactionStatus::Failure,
                'processing', 'requires_capture' => TransactionStatus::Pending,
                default => throw new UnexpectedValueException('Stripe returned an unexpected capture status.'),
            },
            TransactionType::VOID => match ($data['status']) {
                'canceled' => TransactionStatus::Success,
                'succeeded' => TransactionStatus::Failure,
                'requires_capture' => TransactionStatus::Pending,
                default => throw new UnexpectedValueException('Stripe returned an unexpected void status.'),
            },
            default => throw new InvalidArgumentException('The Stripe operation is not a capture or void.'),
        };

        return new Continuation(status: $status, externalId: $data['id'], response: $data);
    }

    private function refundResult(Refund $refund): Continuation
    {
        $data = $refund->toArray();

        if (!is_string($data['id'] ?? null) || $data['id'] === '' || !is_string($data['status'] ?? null)) {
            throw new UnexpectedValueException('Stripe returned an incomplete refund.');
        }

        return new Continuation(
            status: match ($data['status']) {
                'succeeded' => TransactionStatus::Success,
                'failed' => TransactionStatus::Failure,
                'canceled' => TransactionStatus::Cancel,
                'pending', 'requires_action' => TransactionStatus::Pending,
                default => throw new UnexpectedValueException('Stripe returned an unknown refund status.'),
            },
            externalId: $data['id'],
            response: $data,
        );
    }
}
