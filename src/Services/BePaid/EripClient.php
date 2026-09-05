<?php

namespace PHPinnacle\Minos\Services\BePaid;

use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPinnacle\Minos\Enums\Decision;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;

readonly class EripClient
{
    private const string BASE_URL = 'https://api.bepaid.by/beyag';

    public function __construct(
        private string $shopId,
        private string $privateKey,
        private int $timeout = 0,
    ) {}

    public static function create(array $settings): self
    {
        return self::make($settings['shop_id'], $settings['secret_key'], (int) ($settings['timeout'] ?? 0));
    }

    public static function make(string $shopId, string $privateKey, int $timeout = 0): self
    {
        return new self($shopId, $privateKey, $timeout);
    }

    public function payment(Intent $intent): Continuation
    {
        $expires = $this->timeout > 0 ? Date::now()->addSeconds($this->timeout) : null;
        $response = (array) Http::asJson()
            ->withBasicAuth($this->shopId, $this->privateKey)
            ->post(sprintf('%s/payments', self::BASE_URL), [
                'request' => $this->payload($intent, $expires),
            ])
            ->json();

        $decision = match ($response['transaction']['status'] ?? null) {
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
                'qr_code' => $response['transaction']['erip']['qr_code'] ?? null,
                'account' => $response['transaction']['erip']['account_number'] ?? null,
                'instruction' => self::explode($response['transaction']['erip']['instruction'][0] ?? '', '->'),
                'service' => $response['transaction']['erip']['service_no_erip'] ?? null,
                'banks' => $response['transaction']['erip']['banks'] ?? [],
            ],
        );
    }

    private static function explode(string $value, string $delimiter = \PHP_EOL): array
    {
        return array_values(array_filter(
            array_map(fn (string $v) => trim($v), explode($delimiter, $value)),
            fn (string $v) => $v !== '',
        ));
    }

    private function payload(Intent $intent, ?DateTimeInterface $expiresAt): array
    {
        $total = $intent->total();
        $settings = $intent->method->settings;
        $payload = [
            'amount' => $total->amount,
            'currency' => $total->currency,
            'description' => $intent->description,
            'email' => $intent->payer->email,
            'ip' => $intent->payer->ipAddress ?? '127.0.0.1',
            'tracking_id' => $intent->id,
            'notification_url' => $intent->notifyUrl,
            'customer' => array_filter([
                'first_name' => $intent->payer->firstName,
                'last_name' => $intent->payer->lastName,
                'country' => $intent->payer->country,
                'phone' => $intent->payer->phone,
            ]),
            'payment_method' => [
                'type' => 'erip',
                'permanent' => false,
                'editable_amount' => false,
                'account_number' => $intent->number,
                'service_no' => $settings['service'] ?? null,
                'service_info' => self::explode($intent->description),
                'receipt' => self::explode($settings['receipt'] ?? ''),
                'instruction' => self::explode($settings['instruction'] ?? ''),
            ],
            'additional_data' => [
                'notifications' => Arr::onlyValues($settings['notifications'] ?? [], ['sms', 'email']),
            ],
        ];

        if ($expiresAt !== null) {
            $payload['expired_at'] = $expiresAt->format(DATE_ATOM);
        }

        return $payload;
    }
}
