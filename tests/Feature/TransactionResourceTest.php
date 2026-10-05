<?php

use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Illuminate\Auth\Access\Gate as LaravelGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPinnacle\Minos\Contracts\PaymentPayer;
use PHPinnacle\Minos\Contracts\TransactionSource;
use PHPinnacle\Minos\Infolists\TransactionHistoryEntry;
use PHPinnacle\Minos\MinosPlugin;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Pages\ManageTransactions;
use PHPinnacle\Minos\Payments\Cash;
use PHPinnacle\Minos\Resources\Transactions\Pages\ListTransactions;
use PHPinnacle\Minos\Resources\Transactions\Pages\ViewTransaction;
use PHPinnacle\Minos\Resources\Transactions\RelationManagers\OperationsRelationManager;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

class MinosTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->getAttribute('can_view_transactions');
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->viewAny($user) && $transaction->number !== 'DENIED';
    }
}

class MinosResourceSource implements TransactionSource
{
    public function type(): string
    {
        return new PaymentMethod()->getMorphClass();
    }

    /** @return array<TextEntry|Text> */
    public function render(Transaction $transaction): array
    {
        $method = PaymentMethod::query()->find($transaction->source_id);

        return (
            $method === null
                ? []
                : [
                    TextEntry::make('source_title')
                        ->label('Source')
                        ->state('Source ' . $method->name)
                        ->helperText('Provider: ' . $method->provider)
                        ->url('/sources/' . $method->id),
                    Text::make('Source operation ' . $transaction->number),
                ]
        );
    }
}

class MinosResourcePayer implements PaymentPayer
{
    public function type(): string
    {
        return new PaymentMethod()->getMorphClass();
    }

    /** @return array<TextEntry|Text> */
    public function render(Transaction $transaction): array
    {
        $method = PaymentMethod::query()->find($transaction->payer_id);

        return (
            $method === null
                ? []
                : [
                    TextEntry::make('payer_title')
                        ->label('Payer')
                        ->state('Payer ' . $method->name)
                        ->helperText('Payment method')
                        ->url('/payers/' . $method->id),
                    Text::make('Payer operation ' . $transaction->number),
                ]
        );
    }
}

class MinosHistorySource extends PaymentMethod
{
    /** @return MorphMany<Transaction, $this> */
    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}

class MinosHistoryPayer extends PaymentMethod
{
    /** @return MorphMany<Transaction, $this> */
    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payer');
    }
}

class MinosHistoryPayerResource extends Resource
{
    protected static ?string $model = MinosHistoryPayer::class;

    public static function getPages(): array
    {
        return [
            'index' => MinosHistoryPayerList::route('/'),
            'transactions' => MinosTestManageTransactions::route('/{record}/transactions'),
        ];
    }
}

class MinosHistoryPayerList extends ListRecords
{
    protected static string $resource = MinosHistoryPayerResource::class;
}

class MinosTestManageTransactions extends ManageTransactions
{
    protected static string $resource = MinosHistoryPayerResource::class;
}

class MinosHistoryPayerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MinosHistoryPayer $payer): bool
    {
        return $payer->name !== 'Blocked';
    }
}

function minos_resource_payment(string $number = 'PAY-001', ?Source $source = null, ?Payer $payer = null): Transaction
{
    $method = new Cash()->define();
    $method->save();

    return Transaction::payment(new Intent(
        id: (string) Str::uuid(),
        number: $number,
        description: 'Order payment',
        method: $method,
        source: $source ?? new Source('order-1', 'order'),
        payer: $payer ?? new Payer('customer-1', 'customer'),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    ));
}

beforeEach(function () {
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();
    Http::preventStrayRequests();
    $panel = Panel::make()
        ->id('minos-test')
        ->path('admin')
        ->default()
        ->homeUrl('/admin')
        ->plugin(MinosPlugin::make())
        ->resources([MinosHistoryPayerResource::class]);
    app(PanelRegistry::class)->register($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Route::name('filament.minos-test.')
        ->prefix('admin')
        ->group(function () use ($panel) {
            foreach ($panel->getResources() as $resource) {
                $resource::registerRoutes($panel);
            }
        });
    Route::getRoutes()->refreshNameLookups();
    // Isolate the package from unrelated monorepo authorization callbacks.
    Gate::swap(new LaravelGate(app(), fn () => auth()->user()));
    Gate::policy(Transaction::class, MinosTransactionPolicy::class);
    Gate::policy(MinosHistoryPayer::class, MinosHistoryPayerPolicy::class);
    $this->actingAs(new User()->forceFill([
        'id' => 1,
        'name' => 'Operator',
        'email' => 'operator@example.test',
        'can_view_transactions' => true,
    ]));
});

it('lists and filters payment roots and child operations including inactive methods', function () {
    $payment = minos_resource_payment()->handle(Continuation::success());
    $refund = $payment->refund('REF-001', new Money(200, 'USD'), 'Returned item');
    $other = minos_resource_payment('PAY-OTHER');
    $payment->method->is_active = false;
    $payment->method->save();

    Livewire::test(ListTransactions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$payment, $refund, $other])
        ->filterTable('method_id', $payment->method_id)
        ->assertCanSeeTableRecords([$payment, $refund])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('type', 'refund')
        ->assertCanSeeTableRecords([$refund])
        ->assertCanNotSeeTableRecords([$payment]);

    Livewire::test(ListTransactions::class)
        ->filterTable('status', 'success')
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$refund, $other]);

    $refund->handle(Continuation::success());

    Livewire::test(ListTransactions::class)
        ->filterTable('status', 'success')
        ->assertCanSeeTableRecords([$payment, $refund])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('type', 'refund')
        ->assertCanSeeTableRecords([$refund])
        ->assertCanNotSeeTableRecords([$payment]);

    Livewire::test(ListTransactions::class)
        ->searchTable('REF-001')
        ->assertCanSeeTableRecords([$refund])
        ->assertCanNotSeeTableRecords([$payment, $other]);
});

it('shows confirmed balances and unregistered application references as identifiers', function () {
    $payment = minos_resource_payment()->handle(Continuation::success());
    $refund = $payment->refund('REF-001', new Money(250, 'USD'), 'Returned item')->handle(Continuation::success());

    Livewire::test(ViewTransaction::class, ['record' => $payment->id])
        ->assertSuccessful()
        ->assertSee('10.00 USD')
        ->assertSee('2.50 USD')
        ->assertSee('7.50 USD')
        ->assertSee('order-1')
        ->assertSee('customer-1')
        ->assertSee('order')
        ->assertSee('customer');

    Livewire::test(ViewTransaction::class, ['record' => $refund->id])
        ->assertSuccessful()
        ->assertSee('Returned item')
        ->assertSee(TransactionResource::getUrl('view', ['record' => $payment]), escape: false);

    Http::assertNothingSent();
});

it('renders registered source and payer schemas and hides unavailable references', function () {
    MinosPlugin::get()
        ->sources(new MinosResourceSource)
        ->payers(new MinosResourcePayer);

    $method = new Cash()->define();
    $method->save();
    $payment = Transaction::payment(new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-REGISTERED',
        description: 'Registered references',
        method: $method,
        source: new Source($method->id, $method->getMorphClass()),
        payer: new Payer($method->id, $method->getMorphClass()),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    ));

    Livewire::test(ViewTransaction::class, ['record' => $payment->id])
        ->assertSuccessful()
        ->assertSee('Source ' . $method->name)
        ->assertSee('Provider: cash')
        ->assertSee('/sources/' . $method->id, escape: false)
        ->assertSee('Payer ' . $method->name)
        ->assertSee('Payment method')
        ->assertSee('/payers/' . $method->id, escape: false)
        ->assertSee('Source operation PAY-REGISTERED')
        ->assertSee('Payer operation PAY-REGISTERED')
        ->assertDontSee($method->getMorphClass());

    $missing = Transaction::payment(new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-MISSING-REFERENCES',
        description: 'Missing references',
        method: $method,
        source: new Source('missing-source', $method->getMorphClass()),
        payer: new Payer('missing-payer', $method->getMorphClass()),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    ));

    Livewire::test(ViewTransaction::class, ['record' => $missing->id])
        ->assertSuccessful()
        ->assertDontSee('missing-source')
        ->assertDontSee('missing-payer')
        ->assertDontSee($method->getMorphClass())
        ->assertDontSee('/sources/missing-source', escape: false);
});

it('shows only the selected parents children and links them to their view pages', function () {
    $payment = minos_resource_payment()->handle(Continuation::success());
    $refund = $payment->refund('REF-001', new Money(200, 'USD'), 'Returned item');
    $other = minos_resource_payment('PAY-OTHER')->handle(Continuation::success());
    $otherRefund = $other->refund('REF-OTHER', new Money(100, 'USD'), 'Other item');

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $payment, 'pageClass' => ViewTransaction::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$refund])
        ->assertCanNotSeeTableRecords([$payment, $other, $otherRefund])
        ->assertSee(TransactionResource::getUrl('view', ['record' => $refund]), escape: false);
});

it('honors list and view policies', function () {
    $denied = minos_resource_payment('DENIED');
    Livewire::test(ViewTransaction::class, ['record' => $denied->id])->assertForbidden();

    auth()->user()->setAttribute('can_view_transactions', false);
    Livewire::test(ListTransactions::class)->assertForbidden();
});

it('preserves application query scopes for listing and direct record access', function () {
    $visible = minos_resource_payment();
    $hidden = minos_resource_payment('PAY-HIDDEN');
    Transaction::addGlobalScope('permitted-method', fn (Builder $query) => $query->where(
        'method_id',
        $visible->method_id,
    ));

    Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
    expect(fn () => Livewire::test(ViewTransaction::class, ['record' => $hidden->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not expose direct ledger editing or deletion', function () {
    $payment = minos_resource_payment();

    Livewire::test(ListTransactions::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(TransactionResource::canCreate())
        ->toBeFalse()
        ->and(TransactionResource::canEdit($payment))
        ->toBeFalse()
        ->and(TransactionResource::canDelete($payment))
        ->toBeFalse()
        ->and(TransactionResource::canDeleteAny())
        ->toBeFalse();
});

it('reads payment roots through a named transaction relationship', function () {
    $source = new MinosHistorySource;
    $source->name = 'Source';
    $source->provider = 'cash';
    $source->settings = [];
    $source->save();

    $payment = minos_resource_payment('HISTORY-RELATION', new Source($source->id, $source->getMorphClass()))
        ->handle(Continuation::success());
    $refund = $payment->refund('HISTORY-REFUND', new Money(200, 'USD'), 'Returned item');
    minos_resource_payment('HISTORY-OTHER');

    $entry = TransactionHistoryEntry::make('history')
        ->model($source)
        ->relationship('paymentTransactions');

    expect($entry->getTransactions()->pluck('id')->all())->toBe([$payment->id]);
    expect($entry->getOperations($entry->getTransactions()->sole())->pluck('id')->all())->toBe([$refund->id]);
});

it('reads payment roots from transaction models supplied as raw state', function () {
    $payment = minos_resource_payment('HISTORY-STATE')->handle(Continuation::success());
    $refund = $payment->refund('HISTORY-STATE-REFUND', new Money(200, 'USD'), 'Returned item');
    $other = minos_resource_payment('HISTORY-STATE-OTHER');

    $entry = TransactionHistoryEntry::make('history')
        ->state(collect([$payment, $refund]));

    expect($entry->getTransactions()->pluck('id')->all())->toBe([$payment->id]);
    expect($entry->getOperations($entry->getTransactions()->sole())->pluck('id')->all())->toBe([$refund->id]);

    $entry->state([$other]);

    expect($entry->getTransactions()->pluck('id')->all())->toBe([$other->id]);
});

it('registers the transaction history view', function () {
    expect(view()->exists('phpinnacle-minos::infolists.transaction-history-entry'))->toBeTrue();
});

it('lists only the payer transactions on a related records page', function () {
    $payer = new MinosHistoryPayer;
    $payer->name = 'Allowed';
    $payer->provider = 'cash';
    $payer->settings = [];
    $payer->save();

    $otherPayer = new MinosHistoryPayer;
    $otherPayer->name = 'Other';
    $otherPayer->provider = 'cash';
    $otherPayer->settings = [];
    $otherPayer->save();

    $payment = minos_resource_payment('PAYER-OWN', payer: new Payer($payer->id, $payer->getMorphClass()));
    $otherPayment = minos_resource_payment(
        'PAYER-OTHER',
        payer: new Payer($otherPayer->id, $otherPayer->getMorphClass()),
    );

    Livewire::test(MinosTestManageTransactions::class, ['record' => $payer->id])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$otherPayment]);
});

it('requires access to the owner record before showing its transactions', function () {
    $payer = new MinosHistoryPayer;
    $payer->name = 'Blocked';
    $payer->provider = 'cash';
    $payer->settings = [];
    $payer->save();

    expect(MinosTestManageTransactions::canAccess(['record' => $payer]))->toBeFalse();
});
