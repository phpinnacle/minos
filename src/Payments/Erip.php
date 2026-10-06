<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Support\Colors\Color;
use Illuminate\Http\Request;
use PHPinnacle\Minos\Contracts\WebhookGateway;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Enums\EripNotification;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Services\BePaid\EripClient;
use PHPinnacle\Minos\Services\BePaid\TransactionResponse;
use PHPinnacle\Minos\Services\BePaid\Webhook;

class Erip extends Base implements WebhookGateway
{
    public function acceptsWebhook(Transaction $transaction, Request $request): bool
    {
        return Webhook::accepts($transaction, $request);
    }

    public function key(): string
    {
        return 'erip';
    }

    public function getColor(): array
    {
        return Color::Orange;
    }

    public function getIcon(): string
    {
        return 'phosphor-cash-register';
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.erip.description');
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.erip.label');
    }

    public function validate(array $settings): bool
    {
        return ($settings['shop_id'] ?? null) !== null && ($settings['secret_key'] ?? null) !== null;
    }

    public function form(): array
    {
        return [
            Group::make()
                ->columns()
                ->schema([
                    TextInput::make('shop_id')
                        ->label(__('phpinnacle-minos::providers.erip.fields.shop_id'))
                        ->integer()
                        ->required(),
                    TextInput::make('service')
                        ->label(__('phpinnacle-minos::providers.erip.fields.service')),
                    TextInput::make('secret_key')
                        ->label(__('phpinnacle-minos::providers.erip.fields.secret_key'))
                        ->required()
                        ->password()
                        ->revealable(),
                    TextInput::make('timeout')
                        ->label(__('phpinnacle-minos::providers.bepaid.fields.timeout'))
                        ->integer()
                        ->step(1)
                        ->maxValue(60 * 60 * 24)
                        ->minValue(0)
                        ->default(0),
                ]),
            Textarea::make('instructions')
                ->label(__('phpinnacle-minos::providers.erip.fields.instructions')),
            Textarea::make('receipt')
                ->label(__('phpinnacle-minos::providers.erip.fields.receipt')),
            CheckboxList::make('notifications')
                ->label(__('phpinnacle-minos::providers.erip.fields.notifications'))
                ->options(EripNotification::class)
                ->enum(EripNotification::class),
        ];
    }

    public function abilities(): array
    {
        return [
            Ability::Online,
        ];
    }

    public function intent(Intent $intent): Continuation
    {
        return EripClient::create($intent->method->settings)->payment($intent);
    }

    public function handle(Notification $notification): Continuation
    {
        return TransactionResponse::parse($notification->payload)->continuation();
    }
}
