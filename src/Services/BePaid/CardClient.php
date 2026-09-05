<?php

namespace PHPinnacle\Minos\Services\BePaid;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPinnacle\Minos\Enums\Decision;
use PHPinnacle\Minos\Instruments\CardToken;
use PHPinnacle\Minos\Instruments\EncryptedCard;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\Intent;

readonly class CardClient
{
    private const string BASE_URL = 'https://gateway.bepaid.by';

    private const string TRANSACTION_PAYMENT = 'transactions/payments';

    private const string TRANSACTION_AUTHORIZE = 'transactions/authorizations';

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
        return $this->request(self::TRANSACTION_PAYMENT, $intent);
    }

    public function authorize(Intent $intent): Continuation
    {
        return $this->request(self::TRANSACTION_AUTHORIZE, $intent);
    }

    private function request(string $type, Intent $intent): Continuation
    {
        $expires = $this->timeout > 0 ? Date::now()->addSeconds($this->timeout) : null;
        $response = Http::asJson()
            ->withBasicAuth($this->shopId, $this->privateKey)
            ->post(sprintf('%s/%s', self::BASE_URL, $type), [
                'request' => $this->payload($intent, $expires, $this->test),
            ])
            ->json();

        $decision = match ($response['transaction']['status']) {
            'successful' => Decision::Success,
            'failed' => Decision::Failure,
            default => Decision::Pending,
        };

        return new Continuation(
            decision: $decision,
            externalId: $response['transaction']['uid'] ?? null,
            expiresAt: $expires,
            response: $response,
            metadata: [
                'redirect' => $response['transaction']['redirect_url'] ?? null,
                'receipt' => $response['transaction']['receipt_url'] ?? null,
                'message' => $response['response']['message'] ?? null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Intent $intent, ?DateTimeInterface $expiresAt, bool $test): array
    {
        $total = $intent->total();
        $contract = $intent->recurring ? ['recurring'] : [];
        $instrument = $intent->instrument;
        $payload = [
            'amount' => $total->amount,
            'currency' => $total->currency,
            'description' => $intent->description,
            'tracking_id' => $intent->id,
            'test' => $test,
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

        if ($intent->source->number !== null) {
            $payload['custom_fields']['custom_field_2'] = [
                'label' => 'Номер заказа',
                'value' => $intent->source->number,
            ];
        }

        if ($expiresAt !== null) {
            $payload['expired_at'] = $expiresAt->format(DATE_ATOM);
        }

        switch (true) {
            case $instrument instanceof CardToken:
                $creditCard = CreditCard::find($instrument->id);

                if ($creditCard !== null) {
                    $payload['credit_card']['token'] = $creditCard->token;

                    if ($instrument->verificationValue !== null) {
                        $payload['encrypted_credit_card']['verification_value'] = $instrument->verificationValue;
                    }
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
}
