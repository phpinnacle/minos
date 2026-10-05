<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Colors\Color;
use Laravel\Cashier\Cashier;
use PHPinnacle\Minos\Contracts\AuthorizationGateway;
use PHPinnacle\Minos\Contracts\QueuedGateway;
use PHPinnacle\Minos\Contracts\RefundGateway;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\GatewayRequest;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Services\Stripe\PaymentClient;
use Stripe\Exception\AuthenticationException;

class Stripe extends Base implements AuthorizationGateway, QueuedGateway, RefundGateway
{
    public function key(): string
    {
        return 'stripe';
    }

    public function getColor(): array
    {
        return Color::Indigo;
    }

    public function getIcon(): string
    {
        return 'phosphor-stripe-logo';
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.stripe.description');
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.stripe.label');
    }

    public function validate(array $settings): bool
    {
        return ($settings['api_key'] ?? null) !== null && ($settings['public_key'] ?? null) !== null;
    }

    public function form(): array
    {
        return [
            TextInput::make('public_key')
                ->label(__('phpinnacle-minos::providers.stripe.fields.public_key'))
                ->required()
                ->password()
                ->revealable(),
            TextInput::make('api_key')
                ->label(__('phpinnacle-minos::providers.stripe.fields.api_key'))
                ->required()
                ->password()
                ->revealable()
                ->hintActions([
                    Action::make('test')
                        ->label(__('phpinnacle-minos::providers.stripe.actions.test'))
                        ->icon('phosphor-check-circle')
                        ->action(function (Get $get, Set $set) {
                            $set('info', $this->test($get->string('api_key')));
                        }),
                ]),
            KeyValue::make('info')
                ->label(__('phpinnacle-minos::providers.stripe.fields.info'))
                ->visible(fn (?array $state) => $state !== null && $state !== [])
                ->disabled(),
        ];
    }

    public function abilities(): array
    {
        return [
            Ability::Online,
            Ability::Refund,
        ];
    }

    public function intent(Intent $intent): Continuation
    {
        $client = $this->client($intent->method);
        $request = $client->prepare($intent);

        return $client->payment($request->payload, $intent->id);
    }

    public function refund(Transaction $transaction): Continuation
    {
        $client = $this->client($transaction->method);
        $request = $client->derive($transaction);

        return $client->refund($request->payload, $transaction->id);
    }

    public function authorize(Intent $intent): Continuation
    {
        $client = $this->client($intent->method);
        $request = $client->prepare($intent);

        return $client->authorize($request->payload, $intent->id);
    }

    public function capture(Transaction $transaction): Continuation
    {
        $client = $this->client($transaction->method);
        $request = $client->derive($transaction);

        return $client->capture($request->payload, $transaction->id);
    }

    public function void(Transaction $transaction): Continuation
    {
        $client = $this->client($transaction->method);
        $request = $client->derive($transaction);

        return $client->void($request->payload, $transaction->id);
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
        $client = $this->client($transaction->method);

        return match ($transaction->type) {
            TransactionType::PAYMENT => $client->payment($payload, $transaction->id),
            TransactionType::AUTHORIZE => $client->authorize($payload, $transaction->id),
            TransactionType::CAPTURE => $client->capture($payload, $transaction->id),
            TransactionType::VOID => $client->void($payload, $transaction->id),
            TransactionType::REFUND => $client->refund($payload, $transaction->id),
        };
    }

    public function synchronize(Transaction $transaction): Continuation
    {
        return $this->client($transaction->method)->synchronize($transaction);
    }

    private function client(PaymentMethod $method): PaymentClient
    {
        return PaymentClient::create($method->settings);
    }

    /** @return array<string, mixed> */
    private function test(string $key): array
    {
        try {
            if ($key === '') {
                return [];
            }

            $info = Cashier::stripe([
                'api_key' => $key,
            ])
                ->accounts
                ->retrieve()
                ->toArray();

            return [
                'id' => $info['id'],
                'email' => $info['email'],
            ];
        } catch (AuthenticationException) {
            return [];
        }
    }
}
