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

    public function verify(array $data): bool
    {
        $signature = md5(implode('', [
            $data['batch_timestamp'],
            $data['currency_id'],
            $data['amount'],
            $data['payment_method'],
            $data['order_id'],
            $data['site_order_id'],
            $data['transaction_id'],
            $data['payment_type'],
            $data['rrn'],
            $this->key,
        ]));

        return $signature === ($data['wsb_signature'] ?? '');
    }
}
