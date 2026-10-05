<?php

namespace PHPinnacle\Minos\Services\BePaid;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Instruments\CardToken;
use PHPinnacle\Minos\Instruments\EncryptedCard;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\GatewayRequest;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Transaction;

readonly class CardClient
{
    private const string BASE_URL = 'https://gateway.bepaid.by';

    private const string TRANSACTION_PAYMENT = 'transactions/payments';

    private const string TRANSACTION_AUTHORIZE = 'transactions/authorizations';

    private const string TRANSACTION_CAPTURE = 'transactions/captures';

    private const string TRANSACTION_VOID = 'transactions/voids';

    private const string TRANSACTION_REFUND = 'transactions/refunds';

    public function __construct(
        private string $shopId,
        private string $privateKey,
        private bool $test = false,
        private int $timeout = 0,
    ) {}

    public static function make(string $shopId, string $privateKey, bool $test = false, int $timeout = 0): self
    {
        return new self($shopId, $privateKey, $test, $timeout);
    }

    /**
     * @param array{shop_id: string, secret_key: string, test_mode?: bool, timeout?: int|numeric-string} $settings
     */
    public static function create(array $settings): self
    {
        return self::make(
            $settings['shop_id'],
            $settings['secret_key'],
            (bool) ($settings['test_mode'] ?? false),
            (int) ($settings['timeout'] ?? 0),
        );
    }

    public function payment(Intent $intent): Continuation
    {
        return $this->request(self::TRANSACTION_PAYMENT, $this->payload($intent), $intent->id);
    }

    public function authorize(Intent $intent): Continuation
    {
        return $this->request(self::TRANSACTION_AUTHORIZE, $this->payload($intent), $intent->id);
    }

    public function capture(Transaction $transaction): Continuation
    {
        return $this->request(self::TRANSACTION_CAPTURE, $this->transactionPayload($transaction), $transaction->id);
    }

    public function void(Transaction $transaction): Continuation
    {
        return $this->request(self::TRANSACTION_VOID, $this->transactionPayload($transaction), $transaction->id);
    }

    public function refund(Transaction $transaction): Continuation
    {
        return $this->request(self::TRANSACTION_REFUND, $this->transactionPayload($transaction), $transaction->id);
    }

    public function prepare(Intent $intent): GatewayRequest
    {
        if (!$intent->instrument instanceof CardToken || $intent->instrument->verificationValue !== null) {
            throw new InvalidArgumentException('Queued card payments require a saved token without CVC data.');
        }

        // bePaid retains idempotency keys for 24 hours. Leave time for delivery and clock skew.
        return new GatewayRequest($this->payload($intent), CarbonImmutable::now()->addHours(23));
    }

    public function derive(Transaction $transaction): GatewayRequest
    {
        return new GatewayRequest($this->transactionPayload($transaction), CarbonImmutable::now()->addHours(23));
    }

    /** @param array<string, mixed> $payload */
    public function execute(Transaction $transaction, array $payload): Continuation
    {
        $endpoint = match ($transaction->type) {
            TransactionType::PAYMENT => self::TRANSACTION_PAYMENT,
            TransactionType::AUTHORIZE => self::TRANSACTION_AUTHORIZE,
            TransactionType::CAPTURE => self::TRANSACTION_CAPTURE,
            TransactionType::VOID => self::TRANSACTION_VOID,
            TransactionType::REFUND => self::TRANSACTION_REFUND,
        };

        return $this->request($endpoint, $payload, $transaction->id);
    }

    public function synchronize(Transaction $transaction): Continuation
    {
        if ($transaction->external_id === null) {
            throw new LogicException('Wait for the operation identifier before requesting provider state.');
        }

        $response = $this
            ->http()
            ->get(self::BASE_URL . '/transactions/' . rawurlencode($transaction->external_id))
            ->throw()
            ->json();

        return $this->continuation($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Transaction $transaction): array
    {
        $payload = [
            'parent_uid' => $transaction->parent->external_id,
            'amount' => $transaction->amount->amount,
            'tracking_id' => $transaction->id,
            'test' => $this->test,
            'custom_fields' => [
                'custom_field_1' => [
                    'label' => 'Номер транзакции',
                    'value' => $transaction->number,
                ],
                'custom_field_2' => [
                    'label' => 'Номер исходной транзакции',
                    'value' => $transaction->parent->number,
                ],
            ],
        ];

        if ($transaction->type === TransactionType::REFUND) {
            $payload['reason'] = Str::limit($transaction->reason, 250, '…');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Intent $intent): array
    {
        $total = $intent->total();
        $contract = $intent->recurring ? ['recurring'] : [];
        $instrument = $intent->instrument;
        $payload = [
            'amount' => $total->amount,
            'currency' => $total->currency,
            'description' => $intent->description,
            'tracking_id' => $intent->id,
            'test' => $this->test,
            'language' => $intent->payer->language ?? 'ru',
            'notification_url' => $intent->notifyUrl,
            'return_url' => $intent->returnUrl,
            'additional_data' => [
                'contract' => $contract,
            ],
            'customer' => array_filter([
                'ip' => $intent->payer->ipAddress,
                'email' => $intent->payer->email,
                'external_id' => $intent->payer->id,
            ]),
            'billing_address' => array_filter([
                'first_name' => $intent->payer->firstName,
                'last_name' => $intent->payer->lastName,
                'country' => $intent->payer->country,
                'phone' => $intent->payer->phone,
            ]),
            'credit_card' => [
                'skip_three_d_secure_verification' => false,
            ],
            'custom_fields' => [
                'custom_field_1' => [
                    'label' => 'Номер транзакции',
                    'value' => $intent->number,
                ],
            ],
        ];

        if ($this->timeout > 0) {
            $payload['expired_at'] = Date::now()->addSeconds($this->timeout)->format(DATE_ATOM);
        }

        if ($intent->source->number !== null) {
            $payload['custom_fields']['custom_field_2'] = [
                'label' => 'Номер заказа',
                'value' => $intent->source->number,
            ];
        }

        switch (true) {
            case $instrument instanceof CardToken:
                $creditCard = CreditCard::usable($instrument->id, $intent->method, $intent->payer);
                $payload['credit_card']['token'] = $creditCard->token;

                if ($instrument->verificationValue !== null) {
                    $payload['encrypted_credit_card']['verification_value'] = $instrument->verificationValue;
                }

                break;
            case $instrument instanceof EncryptedCard:
                $payload['encrypted_credit_card'] = [
                    'number' => $instrument->number,
                    'holder' => $instrument->holder,
                    'exp_month' => $instrument->expMonth,
                    'exp_year' => $instrument->expYear,
                    'verification_value' => $instrument->verificationValue,
                ];

                if ($instrument->persist) {
                    $payload['custom_fields']['custom_field_2'] = [
                        'label' => 'Токенизация',
                        'value' => 'yes',
                    ];
                    $payload['notification_url'] = $intent->notifyUrl . '?persist=1';
                }

                break;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ConnectionException
     */
    private function request(string $endpoint, array $payload, string $idempotencyKey): Continuation
    {
        $response = $this
            ->http()
            ->withHeader('RequestID', $idempotencyKey)
            ->post(
                sprintf('%s/%s', self::BASE_URL, $endpoint),
                ['request' => $payload],
            )
            ->throw()
            ->json();

        $continuation = $this->continuation($response);
        $continuation->expiresAt = ($payload['expired_at'] ?? null) !== null
            ? Date::parse($payload['expired_at'])
            : null;

        return $continuation;
    }

    private function continuation(mixed $response): Continuation
    {
        $result = TransactionResponse::parse($response);

        return $result->continuation(array_filter([
            'code' => $response['transaction']['code'] ?? null,
            'receipt' => $response['transaction']['receipt_url'] ?? null,
            'redirect' => $response['transaction']['redirect_url'] ?? null,
            'message' => $response['response']['message'] ?? null,
            'friendly_message' => $response['transaction']['friendly_message'] ?? null,
            'custom_fields' => $response['transaction']['custom_fields'] ?? null,
        ]));
    }

    private function http(): PendingRequest
    {
        return Http::asJson()->timeout(30)->withBasicAuth($this->shopId, $this->privateKey);
    }
}
