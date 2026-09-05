<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Support\Colors\Color;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Enums\Decision;
use PHPinnacle\Minos\Exceptions\PaymentDenied;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Services\WebPay\CardClient;
use PHPinnacle\Minos\Services\WebPay\RequestSigner;

class WebPay extends Base
{
    private const array SECURITY = [
        'auto',
        'force_3ds',
        'force_3ds_only_auth_yes',
        'without_3ds',
    ];

    public function abilities(): array
    {
        return [
            Ability::Online,
        ];
    }

    public function form(): array
    {
        return [
            Group::make()
                ->columns()
                ->schema([
                    TextInput::make('shop_id')
                        ->label(__('phpinnacle-minos::providers.webpay.fields.shop_id'))
                        ->required()
                        ->password()
                        ->revealable(),
                    TextInput::make('secret_key')
                        ->label(__('phpinnacle-minos::providers.webpay.fields.secret_key'))
                        ->required()
                        ->password()
                        ->revealable(),
                    TextInput::make('shop_name')
                        ->label(__('phpinnacle-minos::providers.webpay.fields.shop_name')),
                    Select::make('security_mode')
                        ->label(__('phpinnacle-minos::providers.webpay.fields.security_mode'))
                        ->options(array_map(
                            fn (string $v) => __(sprintf('phpinnacle-minos::providers.webpay.security_mode.%s', $v)),
                            array_combine(self::SECURITY, self::SECURITY),
                        ))
                        ->default('auto')
                        ->selectablePlaceholder(false),
                ]),
            TextInput::make('return_url')
                ->label(__('phpinnacle-minos::providers.webpay.fields.return_url'))
                ->helperText(__('phpinnacle-minos::providers.webpay.help.return_url')),
            TextInput::make('cancel_url')
                ->label(__('phpinnacle-minos::providers.webpay.fields.cancel_url'))
                ->helperText(__('phpinnacle-minos::providers.webpay.help.cancel_url')),
            Toggle::make('redirect')
                ->label(__('phpinnacle-minos::providers.webpay.fields.redirect'))
                ->default(true),
            Toggle::make('test_mode')
                ->label(__('phpinnacle-minos::providers.webpay.fields.test_mode')),
        ];
    }

    public function getColor(): array
    {
        return Color::Blue;
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.webpay.description');
    }

    public function getIcon(): string
    {
        return 'phosphor-credit-card';
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.webpay.label');
    }

    public function handle(Notification $notification): Continuation
    {
        $payload = $notification->payload;
        $signer = RequestSigner::make($notification->method->settings['secret_key']);

        if (!$signer->verify($payload)) {
            throw new PaymentDenied;
        }

        $code = (int) $payload['payment_type'];
        $success = in_array($code, [1, 4]);
        $failure = in_array($code, [5, 7, 9, 11]);
        $decision = Decision::Pending;

        if ($success) {
            $decision = Decision::Success;
        } elseif ($failure) {
            $decision = Decision::Failure;
        }

        return new Continuation(
            decision: $decision,
            externalId: $payload['transaction_id'] ?? null,
            response: $payload,
            metadata: [
                'code' => $code,
                'rrn' => $payload['rrn'] ?? null,
                'status' => $payload['rc'] ?? null,
                'reason' => $payload['rc_text'] ?? null,
            ],
        );
    }

    public function intent(Intent $intent): Continuation
    {
        return CardClient::create($intent->method->settings)->payment($intent);
    }

    public function key(): string
    {
        return 'webpay';
    }

    public function validate(array $settings): bool
    {
        return ($settings['shop_id'] ?? null) !== null && ($settings['secret_key'] ?? null) !== null;
    }
}
