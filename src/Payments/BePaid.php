<?php

namespace PHPinnacle\Minos\Payments;

use DateTimeImmutable;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Support\Colors\Color;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use PHPinnacle\Minos\Contracts\AuthorizationGateway;
use PHPinnacle\Minos\Contracts\QueuedGateway;
use PHPinnacle\Minos\Contracts\RefundGateway;
use PHPinnacle\Minos\Contracts\WebhookGateway;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Instruments\EncryptedCard;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\GatewayRequest;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Services\BePaid\CardClient;
use PHPinnacle\Minos\Services\BePaid\TransactionResponse;
use PHPinnacle\Minos\Services\BePaid\Webhook;

class BePaid extends Base implements AuthorizationGateway, QueuedGateway, RefundGateway, WebhookGateway
{
    public function acceptsWebhook(Transaction $transaction, Request $request): bool
    {
        return Webhook::accepts($transaction, $request);
    }

    public function prepare(Intent $intent): GatewayRequest
    {
        return $this->client($intent->method)->prepare($intent);
    }

    public function derive(Transaction $transaction): GatewayRequest
    {
        return $this->client($transaction->method)->derive($transaction);
    }

    public function execute(Transaction $transaction, array $payload): Continuation
    {
        return $this->complete(
            $transaction->method,
            new Payer($transaction->payer_id, $transaction->payer_type),
            $this->client($transaction->method)->execute($transaction, $payload),
            persistCard: $transaction->metadata['persist_card'] ?? false,
        );
    }

    public function synchronize(Transaction $transaction): Continuation
    {
        return $this->complete(
            $transaction->method,
            new Payer($transaction->payer_id, $transaction->payer_type),
            $this->client($transaction->method)->synchronize($transaction),
            persistCard: $transaction->metadata['persist_card'] ?? false,
        );
    }

    public function key(): string
    {
        return 'bepaid';
    }

    public function getColor(): array
    {
        return Color::Orange;
    }

    public function getIcon(): string
    {
        return 'phosphor-credit-card';
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.bepaid.description');
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.bepaid.label');
    }

    /** @param array<string, mixed> $settings */
    public function validate(array $settings): bool
    {
        return (
            ($settings['shop_id'] ?? null) !== null
            && ($settings['public_key'] ?? null) !== null
            && ($settings['secret_key'] ?? null) !== null
        );
    }

    public function capture(Transaction $transaction): Continuation
    {
        return $this->client($transaction->method)->capture($transaction);
    }

    public function void(Transaction $transaction): Continuation
    {
        return $this->client($transaction->method)->void($transaction);
    }

    public function refund(Transaction $transaction): Continuation
    {
        return $this->client($transaction->method)->refund($transaction);
    }

    /** @return array<Component> */
    public function form(): array
    {
        return [
            Group::make()
                ->columns()
                ->schema([
                    TextInput::make('shop_id')
                        ->label(__('phpinnacle-minos::providers.bepaid.fields.shop_id'))
                        ->integer()
                        ->required(),
                    Group::make()
                        ->columns(4)
                        ->schema([
                            TextInput::make('timeout')
                                ->label(__('phpinnacle-minos::providers.bepaid.fields.timeout'))
                                ->columnSpan(2)
                                ->integer()
                                ->step(1)
                                ->maxValue(60 * 60 * 24)
                                ->minValue(0)
                                ->default(0),
                            Toggle::make('test_mode')
                                ->label(__('phpinnacle-minos::providers.bepaid.fields.test_mode'))
                                ->inline(false)
                                ->default(false),
                        ]),
                    TextInput::make('public_key')
                        ->label(__('phpinnacle-minos::providers.bepaid.fields.public_key'))
                        ->required()
                        ->password()
                        ->revealable(),
                    TextInput::make('secret_key')
                        ->label(__('phpinnacle-minos::providers.bepaid.fields.secret_key'))
                        ->required()
                        ->password()
                        ->revealable(),
                ]),
            TextInput::make('return_url')
                ->label(__('phpinnacle-minos::providers.webpay.fields.return_url'))
                ->helperText(__('phpinnacle-minos::providers.webpay.help.return_url')),
            TextInput::make('cancel_url')
                ->label(__('phpinnacle-minos::providers.webpay.fields.cancel_url'))
                ->helperText(__('phpinnacle-minos::providers.webpay.help.cancel_url')),
        ];
    }

    public function abilities(): array
    {
        return [
            Ability::Online,
            Ability::Recurring,
            Ability::Refund,
        ];
    }

    public function schema(PaymentMethod $method, Payer $payer): OA\Schema
    {
        $tokens = CreditCard::list($method, $payer);
        $requiredCardFields = [
            'holder',
            'number',
            'verification_value',
            'exp_month',
            'exp_year',
            'persist',
        ];

        return new OA\Schema(
            properties: [
                new OA\Property(
                    property: 'card',
                    required: $requiredCardFields,
                    properties: [
                        new OA\Property(
                            property: 'holder',
                            type: 'string',
                        ),
                        new OA\Property(
                            property: 'number',
                            type: 'string',
                        ),
                        new OA\Property(
                            property: 'verification_value',
                            type: 'string',
                        ),
                        new OA\Property(
                            property: 'exp_month',
                            type: 'string',
                        ),
                        new OA\Property(
                            property: 'exp_year',
                            type: 'string',
                        ),
                        new OA\Property(
                            property: 'persist',
                            type: 'boolean',
                            default: false,
                        ),
                    ],
                    type: 'object',
                    nullable: true,
                    x: [
                        'public-key' => $method->settings['public_key'],
                        'validation' => 'required_array_keys:' . implode(',', $requiredCardFields),
                    ],
                ),
                new OA\Property(
                    property: 'token',
                    type: 'string',
                    format: 'uuid',
                    nullable: true,
                    enum: $tokens->pluck('id')->all(),
                    x: [
                        'cards' => $tokens->map(fn (CreditCard $card) => [
                            'id' => $card->id,
                            'brand' => $card->brand,
                            'subbrand' => $card->subbrand,
                            'bin' => $card->bin,
                            'mask' => $card->mask,
                            'expires_at' => $card->expires_at->format(DateTimeImmutable::ATOM),
                            'is_expired' => $card->expires_at->isPast(),
                        ])->all(),
                    ],
                ),
            ],
            oneOf: [
                new OA\Schema(required: ['card']),
                new OA\Schema(required: ['token']),
            ],
            type: 'object',
        );
    }

    public function intent(Intent $intent): Continuation
    {
        return $this->complete(
            $intent->method,
            $intent->payer,
            $this->client($intent->method)->payment($intent),
            persistCard: $intent->instrument instanceof EncryptedCard && $intent->instrument->persist,
        );
    }

    public function authorize(Intent $intent): Continuation
    {
        return $this->complete(
            $intent->method,
            $intent->payer,
            $this->client($intent->method)->authorize($intent),
            persistCard: $intent->instrument instanceof EncryptedCard && $intent->instrument->persist,
        );
    }

    public function handle(Notification $notification): Continuation
    {
        return $this->complete(
            $notification->method,
            $notification->payer,
            TransactionResponse::parse($notification->payload)->notificationContinuation(),
            persistCard: (bool) filter_var($notification->payload['persist'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    private function complete(
        PaymentMethod $method,
        Payer $payer,
        Continuation $continuation,
        bool $persistCard,
    ): Continuation {
        if (!$persistCard || $continuation->status !== TransactionStatus::Success) {
            return $continuation;
        }

        $details = TransactionResponse::card($continuation->response['transaction']['credit_card'] ?? null);

        if ($details !== null) {
            $continuation->metadata['payment_card_id'] = CreditCard::store($method, $payer, $details)->getKey();
        }

        return $continuation;
    }

    private function client(PaymentMethod $method): CardClient
    {
        return CardClient::create($method->settings);
    }
}
