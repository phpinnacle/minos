<?php

use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use PHPinnacle\Minos\Contracts\PaymentPayer;
use PHPinnacle\Minos\Contracts\TransactionSource;
use PHPinnacle\Minos\MinosPlugin;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Services\PayerRegistry;
use PHPinnacle\Minos\Services\SourceRegistry;
use Tests\TestCase;

uses(TestCase::class);

class MinosRegistrySource implements TransactionSource
{
    public function type(): string
    {
        return new PaymentMethod()->getMorphClass();
    }

    /** @return array<Text> */
    public function render(Transaction $transaction): array
    {
        return [Text::make('Source ' . $transaction->source_id)];
    }
}

class MinosRegistryPayer implements PaymentPayer
{
    public function type(): string
    {
        return new PaymentMethod()->getMorphClass();
    }

    /** @return array<Text> */
    public function render(Transaction $transaction): array
    {
        return [Text::make('Payer ' . $transaction->payer_id)];
    }
}

it('registers distinct source and payer definitions shared by plugin instances without a panel', function () {
    MinosPlugin::make()
        ->sources(new MinosRegistrySource)
        ->payers(new MinosRegistryPayer);
    MinosPlugin::make()->sources(new class implements TransactionSource {
        public function type(): string
        {
            return new Transaction()->getMorphClass();
        }

        /** @return array<Text> */
        public function render(Transaction $transaction): array
        {
            return [Text::make('Transaction ' . $transaction->number)];
        }
    });
    $transaction = new Transaction()->forceFill(['source_id' => '1', 'payer_id' => '2', 'number' => 'PAY-001']);

    expect(app(SourceRegistry::class)->get(PaymentMethod::class)->render($transaction)[0]->getContent())
        ->toBe('Source 1')
        ->and(app(PayerRegistry::class)->get(PaymentMethod::class)->render($transaction)[0]->getContent())
        ->toBe('Payer 2')
        ->and(app(SourceRegistry::class)->get(Transaction::class)->render($transaction)[0]->getContent())
        ->toBe('Transaction PAY-001')
        ->and(app(PayerRegistry::class)->get(Transaction::class))
        ->toBeNull()
        ->and(app(SourceRegistry::class)->get('unregistered'))
        ->toBeNull();
});

it('looks up source and payer definitions by Laravel morph-map aliases', function () {
    $originalMap = Relation::morphMap();
    Relation::morphMap(['minos-method' => PaymentMethod::class], false);

    try {
        MinosPlugin::make()
            ->sources(new MinosRegistrySource)
            ->payers(new MinosRegistryPayer);
        $transaction = new Transaction()->forceFill(['source_id' => '1', 'payer_id' => '2']);

        expect(app(SourceRegistry::class)->get('minos-method')->render($transaction)[0]->getContent())
            ->toBe('Source 1')
            ->and(app(PayerRegistry::class)->get('minos-method')->render($transaction)[0]->getContent())
            ->toBe('Payer 2');
    } finally {
        Relation::morphMap($originalMap, false);
    }
});

it('renders reference schemas using the current user on every call', function () {
    MinosPlugin::make()
        ->sources(new class implements TransactionSource {
            public function type(): string
            {
                return new PaymentMethod()->getMorphClass();
            }

            /** @return array<Text> */
            public function render(Transaction $transaction): array
            {
                return (
                    auth()->user()?->getAttribute('can_view_references')
                        ? [Text::make('Visible ' . $transaction->number)]
                        : []
                );
            }
        })
        ->payers(new class implements PaymentPayer {
            public function type(): string
            {
                return new PaymentMethod()->getMorphClass();
            }

            /** @return array<Text> */
            public function render(Transaction $transaction): array
            {
                return (
                    auth()->user()?->getAttribute('can_view_references')
                        ? [Text::make('Visible ' . $transaction->number)]
                        : []
                );
            }
        });
    $source = app(SourceRegistry::class)->get(PaymentMethod::class);
    $payer = app(PayerRegistry::class)->get(PaymentMethod::class);
    $transaction = new Transaction()->forceFill(['number' => 'PAY-001']);

    expect($source->render($transaction))->toBe([])->and($payer->render($transaction))->toBe([]);

    $this->actingAs(new User()->forceFill(['id' => 1, 'can_view_references' => true]));
    expect($source->render($transaction)[0]->getContent())
        ->toBe('Visible PAY-001')
        ->and($payer->render($transaction)[0]->getContent())
        ->toBe('Visible PAY-001');

    $this->actingAs(new User()->forceFill(['id' => 2, 'can_view_references' => false]));
    expect($source->render($transaction))->toBe([])->and($payer->render($transaction))->toBe([]);
});
