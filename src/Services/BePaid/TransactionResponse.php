<?php

namespace PHPinnacle\Minos\Services\BePaid;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Models\CardDetails;
use PHPinnacle\Minos\Models\Continuation;
use UnexpectedValueException;

readonly class TransactionResponse
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public array $payload,
    ) {}

    public static function parse(mixed $payload): self
    {
        if (
            !is_array($payload)
            || !is_array($payload['transaction'] ?? null)
            || !is_string($payload['transaction']['uid'] ?? null)
            || $payload['transaction']['uid'] === ''
            || !is_string($payload['transaction']['status'] ?? null)
            || $payload['transaction']['status'] === ''
        ) {
            throw new UnexpectedValueException('bePaid returned an incomplete transaction response.');
        }

        return new self($payload);
    }

    public function status(): TransactionStatus
    {
        return match ($this->payload['transaction']['status']) {
            'successful' => TransactionStatus::Success,
            'failed' => TransactionStatus::Failure,
            default => TransactionStatus::Pending,
        };
    }

    /** @param array<string, mixed> $metadata */
    public function continuation(array $metadata = []): Continuation
    {
        return new Continuation(
            status: $this->status(),
            externalId: $this->payload['transaction']['uid'],
            response: $this->payload,
            metadata: $metadata,
        );
    }

    public function cardContinuation(): Continuation
    {
        $transaction = $this->payload['transaction'];

        return $this->continuation(array_filter([
            'code' => $transaction['code'] ?? null,
            'receipt' => $transaction['receipt_url'] ?? null,
            'redirect' => $transaction['redirect_url'] ?? null,
            'message' => $this->payload['response']['message'] ?? null,
            'friendly_message' => $transaction['friendly_message'] ?? null,
            'custom_fields' => $transaction['custom_fields'] ?? null,
        ]));
    }

    public function notificationContinuation(): Continuation
    {
        $transaction = $this->payload['transaction'];

        return $this->continuation([
            'redirect' => $transaction['redirect_url'] ?? null,
            'receipt' => $transaction['receipt_url'] ?? null,
            'message' => $transaction['message'] ?? null,
            'payment_card_id' => null,
        ]);
    }

    public function eripContinuation(): Continuation
    {
        $erip = $this->payload['transaction']['erip'] ?? [];

        return $this->continuation([
            'qr_code' => $erip['qr_code'] ?? null,
            'account' => $erip['account_number'] ?? null,
            'instruction' => array_values(array_filter(
                array_map(trim(...), explode('->', $erip['instruction'][0] ?? '')),
                fn (string $instruction) => $instruction !== '',
            )),
            'service' => $erip['service_no_erip'] ?? null,
            'banks' => $erip['banks'] ?? [],
        ]);
    }

    public static function card(mixed $card): ?CardDetails
    {
        if ($card === null || is_array($card) && ($card['token'] ?? null) === null) {
            return null;
        }

        $validator = Validator::make(['card' => $card], [
            'card' => ['array'],
            'card.token' => ['required', 'string'],
            'card.exp_month' => ['required', 'numeric', 'regex:/^[0-9]{1,2}$/D', 'between:1,12'],
            'card.exp_year' => ['required', 'numeric', 'regex:/^[0-9]{4}$/D', 'between:2000,9999'],
            'card.product' => ['nullable', 'string'],
            'card.issuer_country' => ['nullable', 'string', 'size:2'],
            'card.brand' => ['nullable', 'string'],
            'card.sub_brand' => ['nullable', 'string'],
            'card.bin_8' => ['nullable', 'string'],
            'card.last_4' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException('bePaid returned invalid saved-card details.');
        }

        $data = $validator->validated()['card'];

        return new CardDetails(
            token: $data['token'],
            expiresAt: CarbonImmutable::create((int) $data['exp_year'], (int) $data['exp_month'], 1)->endOfMonth(),
            product: $data['product'] ?? null,
            country: $data['issuer_country'] ?? null,
            brand: $data['brand'] ?? null,
            subbrand: $data['sub_brand'] ?? 'regular',
            bin: $data['bin_8'] ?? null,
            mask: $data['last_4'] ?? null,
        );
    }
}
