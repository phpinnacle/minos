<?php

namespace PHPinnacle\Minos\Services\WebPay;

use Illuminate\Support\Facades\Http;
use PHPinnacle\Minos\Enums\AdjustmentType;
use PHPinnacle\Minos\Enums\Decision;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;

readonly class CardClient
{
    private const int VERSION = 2;

    private const string FORMAT = 'json';

    private const string MAIN_URL = 'https://payment.webpay.by/api/v1/payment';

    private const string TEST_URL = 'https://securesandbox.webpay.by/api/v1/payment';

    private const string METHOD_CARD = 'cardPayment';

    /**
     * @param array{shop_name?: string|null, security_mode?: string|null, redirect?: bool|null} $settings
     */
    public function __construct(
        private string $shopId,
        private RequestSigner $signer,
        private bool $testMode = false,
        private array $settings = [],
    ) {}

    /**
     * @param array{shop_name?: string|null, security_mode?: string|null, redirect?: bool|null} $settings
     */
    public static function make(string $shopId, string $secretKey, array $settings, bool $testMode = false): self
    {
        return new self($shopId, new RequestSigner($secretKey), $testMode, $settings);
    }

    /**
     * @param array{shop_id: string, secret_key: string, test_mode?: bool, shop_name?: string|null, security_mode?: string|null, redirect?: bool|null} $settings
     */
    public static function create(array $settings): self
    {
        $testMode = (bool) ($settings['test_mode'] ?? false);

        return self::make($settings['shop_id'], $settings['secret_key'], $settings, $testMode);
    }

    public function payment(Intent $intent): Continuation
    {
        $url = $this->testMode ? self::TEST_URL : self::MAIN_URL;
        $payload = $this->payload($intent);
        $response = Http::asJson()->post($url, $payload)->json();

        return new Continuation(
            decision: Decision::Pending,
            response: $response,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Intent $intent): array
    {
        $total = $intent->total();
        $payload = [
            // SETTINGS
            'wsb_storeid' => $this->shopId,
            'wsb_store' => $this->settings['shop_name'] ?? null,
            'wsb_3ds_payment_option' => $this->settings['security_mode'] ?? 'auto',
            'wsb_redirect' => (int) ($this->settings['redirect'] ?? true),
            'wsb_test' => $this->testMode,
            // OPTIONS
            'wsb_tab' => self::METHOD_CARD,
            'wsb_version' => self::VERSION,
            'wsb_return_format' => self::FORMAT,
            'wsb_seed' => time(),
            // Routes
            'wsb_return_url' => $intent->returnUrl,
            'wsb_cancel_return_url' => $intent->cancelUrl,
            'wsb_notify_url' => $intent->notifyUrl,
            // Customer
            'wsb_customer_name' => $intent->payer->firstName,
            'wsb_email' => $intent->payer->email,
            'wsb_phone' => $intent->payer->phone,
            // Order
            'wsb_order_tag' => $intent->id,
            'wsb_order_num' => $intent->number,
            // 'wsb_order_contract' => "Договор №152/12-1 от 12.01.19",
            'wsb_currency_id' => $total->currency,
            'wsb_total' => (float) $total->decimal(),
            'wsb_tax' => 0,
            // Lines
            'wsb_invoice_item_name' => [],
            'wsb_invoice_item_quantity' => [],
            'wsb_invoice_item_price' => [],
            // FOR ERIP:
            // 'wsb_due_date' => 1622194277,
        ];

        foreach ($intent->lines as $line) {
            $payload['wsb_invoice_item_name'][] = $line->title;
            $payload['wsb_invoice_item_quantity'][] = $line->qty;
            $payload['wsb_invoice_item_price'][] = (float) $line->price->decimal();
        }

        $discount = $intent->find(AdjustmentType::Discount);

        if ($discount !== null) {
            $payload['wsb_discount_name'] = $discount->label;
            $payload['wsb_discount_price'] = (float) $discount->amount->decimal();
        }

        $shipping = $intent->find(AdjustmentType::Shipping);

        if ($shipping !== null) {
            $payload['wsb_shipping_name'] = $shipping->label;
            $payload['wsb_shipping_price'] = (float) $shipping->amount->decimal();
        }

        $payload['wsb_signature'] = $this->signer->sign($payload);

        return array_filter($payload, fn (mixed $value) => !is_null($value) && $value !== '');
    }
}
