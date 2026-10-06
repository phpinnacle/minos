<?php

use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Relations\Relation;
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
