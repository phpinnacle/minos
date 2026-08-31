<?php

namespace PHPinnacle\Minos\Payments;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Support\Colors\Color;
use OpenApi\Attributes as OA;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Enums\Decision;
use PHPinnacle\Minos\Instruments\EncryptedCard;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Services\BePaid\CardClient;

class BePaid extends Base
{
    public function abilities(): array
    {
        return [
            Ability::Online,
            Ability::Recurring,
        ];
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
                            Toggle::make('authorize')
                                ->label(__('phpinnacle-minos::providers.bepaid.fields.authorize'))
                                ->inline(false)
                                ->default(false),
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

    public function getColor(): array
    {
        return Color::Orange;
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.bepaid.description');
    }

    public function getIcon(): string
    {
        return 'phosphor-credit-card';
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.bepaid.label');
    }

    public function handle(Notification $notification): Continuation
    {
        $transaction = $notification->payload['transaction'] ?? [];
        $creditCard = null;
        $decision = match ($transaction['status'] ?? null) {
            'successful' => Decision::Success,
            'failed' => Decision::Failure,
            default => Decision::Pending,
        };

        if ($decision === Decision::Success) {
            $persist = (bool) filter_var($notification->payload['persist'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($persist) {
                $cardData = $transaction['credit_card'] ?? [];
                $creditCard = $this->persistCard($notification->method, $notification->payer, $cardData);
            }
        }

        return new Continuation(
            decision: $decision,
            externalId: $transaction['uid'] ?? null,
            response: $notification->payload,
            metadata: [
                'redirect' => $transaction['redirect_url'] ?? null,
                'receipt' => $transaction['receipt_url'] ?? null,
                'message' => $transaction['message'] ?? null,
                'payment_card_id' => $creditCard?->getKey() ?? null,
            ],
        );
    }

    public function intent(Intent $intent): Continuation
    {
        $client = CardClient::create($intent->method->settings);
        $authorize = (bool) filter_var($intent->method->settings['authorize'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $continuation = $authorize ? $client->authorize($intent) : $client->payment($intent);

        // No 3D Secure pass
        if ($continuation->decision === Decision::Success) {
            $persist = $intent->instrument instanceof EncryptedCard && $intent->instrument->persist;

            if ($persist) {
                $cardData = $continuation->response['transaction']['credit_card'] ?? [];
                $creditCard = $this->persistCard($intent->method, $intent->payer, $cardData);

                if ($creditCard !== null) {
                    $continuation->metadata['payment_card_id'] = $creditCard->getKey();
                }
            }
        }

        return $continuation;
    }

    public function key(): string
    {
        return 'bepaid';
    }

    public function schema(PaymentMethod $method, Payer $payer): OA\Schema
    {
        $tokens = CreditCard::list($method->id, $payer);
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

    /** @param array<string, mixed> $settings */
    public function validate(array $settings): bool
    {
        return isset($settings['shop_id'], $settings['public_key'], $settings['secret_key']);
    }

    /** @param array<string, mixed> $cardData */
    private function persistCard(PaymentMethod|string $method, Payer $payer, array $cardData): ?CreditCard
    {
        if (!isset($cardData['token'])) {
            return null;
        }

        $methodId = $method instanceof PaymentMethod ? $method->getKey() : $method;
        $creditCard = CreditCard::query()->firstOrNew([
            'method_id' => $methodId,
            'customer_type' => $payer->type,
            'customer_id' => $payer->id,
            'token' => $cardData['token'],
        ]);
        $creditCard->product = $cardData['product'];
        $creditCard->country = $cardData['issuer_country'];
        $creditCard->brand = $cardData['brand'];
        $creditCard->subbrand = $cardData['sub_brand'] ?? 'regular';
        $creditCard->bin = $cardData['bin_8'];
        $creditCard->mask = $cardData['last_4'];
        $creditCard->expires_at = CarbonImmutable::create(
            year: (int) $cardData['exp_year'],
            month: (int) $cardData['exp_month'],
        )->lastOfMonth();
        $creditCard->save();

        return $creditCard;
    }
}
