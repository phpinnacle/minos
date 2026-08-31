<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Support\Colors\Color;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Enums\Decision;
use PHPinnacle\Minos\Enums\EripNotification;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Services\BePaid\EripClient;

class Erip extends Base
{
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

    public function getColor(): array
    {
        return Color::Orange;
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.erip.description');
    }

    public function getIcon(): string
    {
        return 'phosphor-cash-register';
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.erip.label');
    }

    public function handle(Notification $notification): Continuation
    {
        $transaction = $notification->payload['transaction'] ?? [];
        $decision = match ($transaction['status'] ?? null) {
            'successful' => Decision::Success,
            'failed' => Decision::Failure,
            default => Decision::Pending,
        };

        return new Continuation(
            decision: $decision,
            externalId: $transaction['uid'] ?? null,
            response: $notification->payload,
        );
    }

    public function intent(Intent $intent): Continuation
    {
        return EripClient::create($intent->method->settings)->payment($intent);
    }

    public function key(): string
    {
        return 'erip';
    }

    public function validate(array $settings): bool
    {
        return isset($settings['shop_id'], $settings['secret_key']);
    }
}
