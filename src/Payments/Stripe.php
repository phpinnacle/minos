<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Colors\Color;
use Laravel\Cashier\Cashier;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use Stripe\Exception\AuthenticationException;

class Stripe extends Base
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
            Ability::Recurring,
        ];
    }

    public function intent(Intent $intent): Continuation
    {
        return Continuation::pending();
    }

    public function handle(Notification $notification): Continuation
    {
        return Continuation::success();
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
