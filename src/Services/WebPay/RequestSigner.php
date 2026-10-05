<?php

namespace PHPinnacle\Minos\Services\WebPay;

readonly class RequestSigner
{
    public function __construct(
        private string $key,
    ) {}

    public static function make(string $key): self
    {
        return new self($key);
    }

    /**
     * @param array{wsb_seed: int, wsb_storeid: string, wsb_order_num: string, wsb_test: bool, wsb_currency_id: string, wsb_total: float} $data
     */
    public function sign(array $data): string
    {
        return sha1(implode('', [
            $data['wsb_seed'],
            $data['wsb_storeid'],
            $data['wsb_order_num'],
            $data['wsb_test'],
            $data['wsb_currency_id'],
            $data['wsb_total'],
            $this->key,
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function verify(array $data): bool
    {
        if (!is_string($data['wsb_signature'] ?? null)) {
            return false;
        }

        $values = [];

        foreach ([
            'batch_timestamp',
            'currency_id',
            'amount',
            'payment_method',
            'order_id',
            'site_order_id',
            'transaction_id',
            'payment_type',
            'rrn',
        ] as $field) {
            if (!array_key_exists($field, $data) || !is_scalar($data[$field]) && $data[$field] !== null) {
                return false;
            }

            $values[] = $data[$field];
        }

        $values[] = $this->key;

        return hash_equals(md5(implode('', $values)), $data['wsb_signature']);
    }
}
