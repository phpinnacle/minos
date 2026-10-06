# Minos for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/minos.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/minos)

Minos is a payment orchestration package for Laravel and Filament. It models payment methods, installment plans, stored cards, payment intents and adjustments behind provider contracts, while supplying Filament resources for operational configuration.

## Features

- Transaction, payment method and payment plan Filament resources.
- Provider registry with built-in cash, bank, card, ERIP, Stripe, BePaid and WebPay implementations.
- `PaymentGateway` and `PaymentProvider` contracts for custom integrations.
- Payment intents, line items, payers, sources and continuation statuses.
- Persisted payment transactions with authorization, capture, void and refund operations.
- Atomic payment and Laravel database job creation, with encrypted requests and provider idempotency.
- Discounts, shipping, tax, fees and rounding adjustments.
- Single and multipart payment schemes with validation.
- Tokenized and encrypted card instruments.
- Configurable connection, navigation and tenancy.

## Requirements and installation

- PHP 8.4 or later
- Laravel 13 and Filament 5
- Laravel Cashier 16
- `phpinnacle/common`

```bash
composer require phpinnacle/minos
php artisan vendor:publish --tag="phpinnacle-minos-migrations"
php artisan migrate
```

## Registering providers

```php
use PHPinnacle\Minos\MinosPlugin;

$panel->plugin(MinosPlugin::make());
```

Register provider class names in `phpinnacle-minos.providers`. The service provider resolves them through the container in both HTTP requests and queue workers, without requiring a current Filament panel. Provider instances must be stateless; merchant credentials come from each transaction's payment method. The built-in providers are registered by default. Replace the list to restrict availability or add custom providers.

Providers are loaded into `ProviderRegistry` and become available when defining payment methods. Each provider supplies its label, availability, configuration form, validation and gateway behavior. Stripe supports hosted Checkout payments and refunds, plus direct PaymentIntent authorizations, captures and voids through the queued transaction flow.
Providers that do not require checkout input may return `null` from `PaymentGateway::schema()`.

## Payment plans and calculations

`PaymentPlan::scheme()` creates a `PaymentScheme` for a price and optional sale date. `PaymentScheme` exposes its start, expiry, total and installment count. Each `IntentLine::total()` multiplies its unit price by quantity. `Intent::total()` sums those line totals and applies typed `Adjustment` objects in order:

```php
use PHPinnacle\Minos\Models\Adjustment;

$adjustment = Adjustment::discount('Campaign', $discount);
$newTotal = $adjustment->apply($subtotal);
```

Gateway results use `Continuation` and `TransactionStatus` to represent success, failure, pending and cancellation without coupling application flows to one provider.

The `PaymentParts` validation rule accepts installment percentages from 1 to 100 that total exactly 100, and integer delays from 0 to 365 days. The `PaymentScheme` rule validates dates and amounts, requires a common currency, and optionally checks the total against an expected amount. These rules validate form input before constructing domain objects.

`PaymentMethod::get($provider)` looks up an active method using the provider's registered key. `PaymentMethod::default($online)` and `list($online)` share the same optional online/offline filter, including the default selected by `MethodSelect`.

## Provider responses and stored cards

BePaid and ERIP share a transaction response adapter for both HTTP responses and notifications. It requires a transaction reference and status, preserves raw responses in `Continuation`, and maps provider statuses consistently. HTTP failures and malformed responses raise exceptions rather than becoming pending payments. WebPay also propagates HTTP failures, rejects empty or non-array JSON responses, and rejects incomplete or incorrectly signed notifications before handling them. Signed values retain their original representation.

`TransactionResponse::cardContinuation()` maps card API checkout metadata, `notificationContinuation()` maps BePaid notification metadata, and `eripContinuation()` maps ERIP checkout instructions. These methods preserve the metadata conventions of each response path.

ERIP reads checkout instructions from `settings['instructions']`, matching its configuration form. Non-empty, trimmed lines are sent in the provider's `payment_method.instruction` array.

BePaid maps saved-card responses into `CardDetails`; `CreditCard::store()` owns persistence by payment method, payer and token. Immediate results and callbacks reuse that identity and preserve model events and default/sort behavior. Missing tokens mean that no card is saved. Supplied expiry values are validated, and the card remains valid through the last day of its expiry month. `CreditCard::usable()` scopes token use to an active, unexpired card belonging to the requested method and payer. Concurrent card insertion is not protected by a unique database constraint.

## Transactions

### Filament transaction resource

`MinosPlugin` registers `TransactionResource` at `transactions`. Its journal lists payments and child operations with amounts, payment methods, operation types and statuses. Search by number or provider reference, and filter by method (including inactive methods), type, status and dates. Configure the navigation icon and order with `phpinnacle-minos.navigation.transaction`.

`TransactionStatus` and `TransactionType` implement Filament's `HasLabel`, `HasColor` and `HasIcon` contracts, so their badges display labels, colors and icons automatically.

The view page shows operation details, source/payer references, confirmed captured/refunded/net totals and dates. Its child-operations table links to each operation's own page, including refunds below captures, and children link back to their parent. Unregistered source and payer types show their stored identifier and type. Applications can register supported model types with a renderer that receives the full transaction and returns a Filament schema:

```php
use App\Models\Order;
use App\Filament\Resources\OrderResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Text;
use PHPinnacle\Minos\Contracts\TransactionSource;
use PHPinnacle\Minos\MinosPlugin;
use PHPinnacle\Minos\Models\Transaction;

final class OrderTransactionSource implements TransactionSource
{
    public function type(): string
    {
        return new Order()->getMorphClass();
    }

    /** @return array<TextEntry|Text> */
    public function render(Transaction $transaction): array
    {
        $order = Order::query()->find($transaction->source_id);

        if ($order === null || ! OrderResource::canEdit($order)) {
            return [];
        }

        return [
            TextEntry::make('source_order')
                ->label('Order')
                ->state($order->number)
                ->helperText($order->customer?->name)
                ->url(OrderResource::getUrl('edit', ['record' => $order])),
            Text::make('Transaction ' . $transaction->number),
        ];
    }
}

$panel->plugin(MinosPlugin::make()->sources(new OrderTransactionSource));
```

Sources implement `PHPinnacle\Minos\Contracts\TransactionSource`; payers implement `PHPinnacle\Minos\Contracts\PaymentPayer`. These interfaces replace the concrete `SourceType` and `PayerType` definitions. Register payer implementations with `->payers(new CustomerPaymentPayer)`, using `$transaction->payer_id` to look up the payer. `type()` returns the stored `source_type` or `payer_type`; return the model's `getMorphClass()` to support Laravel morph-map aliases. Their `render(Transaction $transaction): array` methods return components directly, including schema primitives such as `Text`, `Icon` and `Image`. Actions, strings and `Htmlable` values follow Filament's native schema contract. The returned components are inserted into the operation section with their names and layout unchanged. `ReferenceDisplay` and the identifier-based resolver are removed.

The plugin delegates registration to the separate `SourceRegistry` and `PayerRegistry` services; definitions are shared across panels, and consumers resolve them directly from the registries. Registries retain developer-authored definitions only: renderers create components on each call and resolve the current user and tenant rather than capture request-specific state. Render only records the current user may access, including the application's tenant boundary; returning `[]` renders no reference components. The resource offers no direct creation, editing or deletion of ledger rows; payments continue through the domain API and `PaymentManager`.

Standard Filament `viewAny` and `view` policies apply. The resource retains Eloquent global scopes and Filament's default tenant scoping. Applications must supply their access policy and row scope; a `viewAny` policy alone does not filter rows. In a tenant panel, configure the transaction's tenant relationship or an application-owned scope through the usual Filament integration. Minos does not infer tenant ownership from arbitrary source or payer references.

### Transaction history entry

`PHPinnacle\Minos\Infolists\TransactionHistoryEntry` displays payment roots and their capture, void, and refund operations in a Filament schema. Supply either a named Eloquent relationship on the current record or a collection/array of `Transaction` models through `state()`:

```php
use PHPinnacle\Minos\Infolists\TransactionHistoryEntry;

TransactionHistoryEntry::make('payment_history')
    ->relationship('paymentTransactions')
    ->limit(20);

TransactionHistoryEntry::make('payment_history')
    ->state($transactions);
```

For a custom Filament theme using Tailwind CSS v4, add `@source '../../../../vendor/phpinnacle/minos/resources/views/**/*.blade.php';` to the theme CSS, adjusting the relative path to your project.

The relationship should return the source's Minos transactions, for example a `morphMany(Transaction::class, 'source')` relation. Raw state should contain payment roots; child operations are loaded from their relationships. The entry shows ten roots by default and only records authorized by the transaction resource's `viewAny` and `view` policies. `manageWhen(true)` enables eligible capture, void, refund, and cancellation actions for users allowed to `update` the transaction; it defaults to false. Applications should pass an authorization callback when access also depends on the current record. Offline pending transactions can be canceled locally. The entry commits manual capture, void, and refund records together with their successful confirmation, so listeners observe the completed operation. Online operations require a provider with the corresponding capability.

### Related transaction page

Extend `PHPinnacle\Minos\Pages\ManageTransactions` to add a transaction table to a payer or source resource. The owner model supplies a `paymentTransactions()` relationship; use the `payer` morph for a payer and the `source` morph for a source. The page reuses the Minos transaction table, including filters and links to transaction details. Access requires both permission to view the owner record and transaction `viewAny` permission.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Pages\ManageTransactions;

class Payer extends Model
{
    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payer');
    }
}

final class ManagePayerTransactions extends ManageTransactions
{
    protected static string $resource = PayerResource::class;
}
```

Register `'transactions' => ManagePayerTransactions::route('/{record}/transactions')` in `PayerResource::getPages()` and add the page to the resource's record sub-navigation to expose it beside the payer's edit page. The table is read-only; Minos processes payments and operations through its domain API.

### Transaction model

`PHPinnacle\Minos\Models\Transaction` stores a payment and its child operations in one `payment_transactions` table. The root represents the payment throughout its lifecycle; captures and voids are its children, and a refund belongs to the payment or capture being refunded. `root()` follows these links back to the payment. `Intent` remains the input DTO for creating the root.

Each record owns its amount, currency, type, operation status, external identifier and metadata. Its polymorphic `source` and `payer` references can point to application models or morph-map aliases; Minos does not depend on an order or sales package. Source and payer identifiers support both integer and string model keys.

`status` uses `TransactionStatus` and records the state of that record's operation: `pending`, `success`, `failure` or `cancel`. `type` identifies the operation: payment, authorization, capture, void or refund. Child operations do not change their parent's status: a successful authorization stays successful after capture or void, and a successful payment or capture stays successful after a refund. A successful refund also has `status = success`. Read `captured()`, `refunded()`, `received()` and `capturable()` for amounts; pending reservations do not mean that funds have been captured.

The transaction table uses `phpinnacle-minos.connection` and retains foreign keys to payment methods and parent operations. A referenced method or parent cannot be deleted while transactions depend on it.

Use `PaymentManager` to persist an operation and schedule its execution atomically:

```php
use PHPinnacle\Minos\Services\PaymentManager;

$payments = app(PaymentManager::class);
$transaction = $payments->payment($intent);

// The response is pending. Read the refreshed transaction after the worker runs.
```

For checkout flows that need an immediate provider response, call `$payments->paymentNow($intent)`. It creates the transaction, invokes the synchronous gateway API for an online method and applies the result before returning. This also supports ERIP and WebPay, which do not implement `QueuedGateway`. Offline methods remain pending without invoking a provider. Unlike `payment()`, this method does not enqueue a job or provide durable replay; a transport exception propagates and leaves the saved operation pending. Use `payment()` for queued execution.

For cash, a bank transfer or an external terminal, the manager creates a pending transaction without a provider job. Call `$transaction->confirmManual()` only after an authorized operator or trusted integration confirms receipt of funds. Pass an optional `DateTimeInterface` completion date and metadata with `$transaction->confirmManual($processedAt, ['receipt' => 'RECEIPT-1'])`; omission uses the current time. This method confirms offline payments and child operations, merging metadata and saving the status, date and metadata in one transition before dispatching events. It rejects online methods and leaves terminal operations unchanged on repeated calls. Bank transfer initialization does not confirm receipt. The application authorizes operations and scopes access to transactions; Minos performs supported queued requests in the worker.

`AuthorizationGateway` exposes `authorize()`, `capture()` and `void()`. `RefundGateway` exposes `refund()`. BePaid and Stripe implement both. Other providers retain their existing `PaymentGateway` contract and must not be assumed to support these operations. Check the corresponding interface before offering an online operation.

```php
use PHPinnacle\Money\Money;

// Use a fresh Intent ID. This example authorizes USD 10.00.
$authorization = $payments->authorize($intent);

// In a later request, once the authorization reaches TransactionStatus::Success:
$capture = $payments->capture($authorization, 'CAP-001', new Money(600, 'USD'));

// Once capture succeeds, refund its funds:
$refund = $payments->refund($capture, 'REF-001', new Money(200, 'USD'), 'Returned item');

// First confirm that the provider still holds the remainder and supports void.
$remaining = $authorization->refresh()->capturable();

if (!$remaining->isZero()) {
    $void = $payments->void($authorization, 'VOID-001', $remaining);
}
```

The manager's `capture()`, `void()` and `refund()` create pending children and enqueue online operations. Reservations and results lock the root first, serializing balance changes throughout that payment's tree. Pending and successful children consume the available balance; failed or canceled children release their reservation. Amounts must be positive and use the parent's currency. `capturable()` and `refundable()` report the locally available amount for an eligible parent; provider-specific capabilities and limits still apply. A provider may have released an unused hold after capture without a separate void, so check its current state before voiding the remainder. Refresh a previously loaded parent after handling a child result. The model's corresponding factory methods remain persistence-only primitives for manual operations and custom integrations; they do not enqueue jobs.

A connection failure leaves the saved operation pending because its remote outcome is unknown. Reconcile with the provider or retry the same operation, using its existing ID; do not create a replacement operation merely because an HTTP response was lost. BePaid uses the operation ID as `RequestID`. Gateway requests should run after the transaction record has committed, outside a surrounding database transaction that could roll back after money has moved.

Minos registers `POST /api/minos/transactions/{id}/notify` as `minos.transaction.notify`. Use `route('minos.transaction.notify', ['id' => $intent->id])` for BePaid, ERIP and WebPay notification URLs. BePaid and ERIP notifications must pass merchant HTTP Basic credentials and match the operation's tracking ID, provider ID, amount and currency. WebPay notifications must have a valid provider signature and match the operation number and provider ID. Minos queues `PaymentManager::synchronize()` for BePaid after verification; the job retrieves the current provider state, so delayed webhook snapshots cannot overwrite a newer result. ERIP and WebPay apply their verified notifications directly. An expiry timestamp alone is not evidence that a delayed success should be discarded. Other providers can implement `WebhookGateway` to use this route; Stripe webhook authentication and correlation remain application-owned until its adapter implements that contract.

`handle()` locks and reloads the operation before applying a result. It merges metadata, preserves existing external IDs and expiry values when omitted, and records the operation's completion time in `processed_at`. Once a terminal operation status has been recorded, duplicate or late results do not change it. Child results advance the root version atomically without changing parent statuses; balance totals reflect the child results. The root's `processed_at` describes its initial payment or authorization operation, not the completion of subsequent refunds. Sensitive card input and raw gateway responses are not persisted by the transaction model.

The low-level `Transaction::synchronize($continuation, $expectedVersion)` can correct a terminal status, for example when a completed refund returns to pending or fails. Read `$transaction->root()->refresh()->version` **before** retrieving provider state and pass it when applying that result. A concurrent change raises `StaleTransaction`; fetch provider state again instead of applying the old response with a newer version. The queue job handles this through Laravel retries. A return to pending clears `processed_at` and reserves the amount again. An unchanged result does not emit repeated model updates. Synchronize capture and refund outcomes on their corresponding child records, rather than applying a provider's aggregate payment status as an authorization result.

`captured()`, `refunded()` and `received()` return confirmed totals, including refunds below capture children. These totals are calculated from the records, so reservations and duplicate responses cannot increase them. `received()` is captured funds less successful refunds. Query `Transaction::forSource($source)->whereNull('parent_id')` for payment roots and their totals.

`balanceImpact()` instead returns one operation's effect on funds received: a successful payment or capture is positive, a successful refund is negative, and authorizations, voids and unfinished or failed operations contribute zero. It uses the operation's `type` and `status`: refunding a capture does not erase the original capture's positive effect. Summing operation effects through `Transaction::forSource($source)` counts each effect once; do not also add root aggregate totals. The application owns payment allocation, installments, order status changes and business events.

Minos dispatches `PHPinnacle\Minos\Events\TransactionCreated` when a payment or child operation is saved, `PHPinnacle\Minos\Events\TransactionUpdated` when an operation's persisted data changes, and `PHPinnacle\Minos\Events\TransactionStatusChanged` when its status changes. All three events implement Laravel's `ShouldDispatchAfterCommit`: listeners run after the owning database transaction commits, and a rollback discards the events. `TransactionCreated` and `TransactionUpdated` carry `$transaction`; `TransactionStatusChanged` carries `$transaction`, `$previousStatus`, and `$status` as `TransactionStatus` values. A pending provider response that changes metadata or the external ID emits `TransactionUpdated` without a status event. Root version and timestamp-only updates, and repeated results with no changes, emit neither update nor status events. Register ordinary Laravel listeners, for example:

```php
use Illuminate\Support\Facades\Event;
use PHPinnacle\Minos\Events\TransactionStatusChanged;

Event::listen(TransactionStatusChanged::class, ReconcilePayment::class);
```

In the listener, reconcile the allocation with the payment root's current `received()` total. Replace the recorded contribution idempotently instead of adding the whole amount again; reconciliation can reverse an earlier result. A refund changes the balance without changing the root's operation status, so handle status events from child operations as well as roots. Scope transaction lookups through an authorized source or payment method, including the application's tenant boundary; this table does not install a global tenant scope or infer a current tenant.

### Optional WebSocket updates

Register Minos's broadcast listener in the application to publish committed creation and update events. The application supplies its Laravel broadcaster and queue; Minos does not require a specific WebSocket server.

```php
use Illuminate\Support\Facades\Event;
use PHPinnacle\Minos\Events\TransactionCreated;
use PHPinnacle\Minos\Events\TransactionUpdated;
use PHPinnacle\Minos\Listeners\BroadcastTransaction;

Event::listen([TransactionCreated::class, TransactionUpdated::class], BroadcastTransaction::class);
```

Each message goes to the private `minos.transactions.{rootId}` channel as `minos.transaction.changed`. It contains `root` (ID, number, amount, currency, type, status, version, confirmed and available amounts) and `operation` (identity, type, status, amount, currency, payment method name, dates, available amounts, checkout redirect, receipt and message, and ERIP QR code, account, instruction and service). Amounts are integer minor units. The payload is assembled from a consistent payment state after commit, so the client can update its local payment state directly. `operation` contains only explicitly selected metadata fields; raw provider responses, card details, payer and source references, and arbitrary metadata are excluded. `TransactionStatusChanged` remains a separate event for business listeners and does not need a second broadcast listener.

Authorize subscriptions in the application's `routes/channels.php` using the same tenant and transaction access rules as its HTTP interface. For example, an application policy that grants `view` to both permitted operators and authenticated payers can be used for the channel:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use PHPinnacle\Minos\Models\Transaction;

Broadcast::channel('minos.transactions.{rootId}', function (Authenticatable $user, string $rootId): bool {
    $root = Transaction::query()->whereNull('parent_id')->find($rootId);

    return $root !== null && Gate::forUser($user)->allows('view', $root);
});
```

Subscribe with `Echo.private('minos.transactions.' + rootId).listen('.minos.transaction.changed', handler)`. The initial page state still comes from the application's authorized read path; subsequent messages supply the updated operation and root balance without a request per message. Compare `root.version` when applying messages that may arrive out of order. Refresh once after reconnecting to recover any missed messages. Broadcasting runs through Laravel's queue and failures are reported without changing the payment outcome.

### Laravel database queue as the transactional outbox

Minos uses Laravel's standard `jobs` table as its outbox. `PaymentManager` writes the payment and an encrypted `ProcessPayment` job in one database transaction, using `beforeCommit()` to insert the job immediately. Other workers can see it only after commit; rollback removes both records. There is no separate outbox table, relay or scheduler. This guarantee requires the queue to use the **same Laravel database connection instance** as Minos. The manager rejects other queue drivers or connections.

All Minos models, their relationships and queued payment jobs use `phpinnacle-minos.connection`, or the application's default database connection when it is `null`. Jobs resolve the package connection when they execute. Keep this configuration consistent between HTTP requests and queue workers, including while jobs are pending. Package migrations create and remove their tables on the configured Minos connection.

For example, configure a queue connection in `config/queue.php`:

```php
'connections' => [
    'minos' => [
        'driver' => 'database',
        'connection' => 'mysql',
        'table' => 'jobs',
        'queue' => 'payments',
        'retry_after' => 150,
        'after_commit' => false,
    ],
],
```

And select it in `config/phpinnacle-minos.php`:

```php
'connection' => 'mysql',
'queue' => ['connection' => 'minos', 'queue' => 'payments'],
```

Create Laravel's standard `jobs` and `failed_jobs` tables on that database if your application does not already have them, then run its migrations. Start the worker with:

```bash
php artisan queue:work minos --queue=payments
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
```

The package defaults to the `database` queue connection and `payments` queue. Keep `retry_after` greater than the job's 60-second timeout and 120-second overlap lock lifetime; 150 seconds is suitable. Use a shared Laravel cache with atomic locks across workers. Requests for the same payment root share `WithoutOverlapping` middleware, including captures, refunds and synchronization. The root version also rejects responses from requests that outlived their lock or raced with a direct model update.

Laravel owns job reservation, retries, failure storage and redelivery after a worker crash. A failed job does **not** mean a declined payment: its remote outcome may be unknown, so the transaction stays pending and its amount remains reserved. Jobs make up to five attempts with backoff. Inspect failed jobs and reconcile or retry the existing operation; do not create a new payment ID because of a timeout. A pending provider response completes the execution job, but leaves the payment pending. Authenticated webhooks or an application reconciliation task must request subsequent synchronization.

`QueuedGateway` is the provider contract for this flow. `prepare(Intent $intent)` prepares payment and authorization requests; `derive(Transaction $transaction)` prepares capture, void and refund requests. Both return a `GatewayRequest` containing the request, its replay deadline and optional non-sensitive transaction metadata, without external effects. The request stays in the encrypted queue job; only its metadata is copied to the transaction. These methods replace `prepare(Transaction $transaction, ?Intent $intent = null)`. `execute()` must reuse the operation ID as its idempotency key; `synchronize()` retrieves the operation's current provider state. BePaid and Stripe implement this contract. ERIP and WebPay retain their synchronous gateway APIs. Unsupported online operations are rejected before the transaction commits.

Stripe requires an `Intent` with a `returnUrl`, no instrument and `recurring: false`. For a payment, the worker creates a [Checkout Session](https://docs.stripe.com/api/checkout/sessions/create). Its URL appears in transaction metadata as `redirect` and in the optional WebSocket update as `redirect_url`; the application redirects the payer there. A completed Checkout Session can still be unpaid, so only Stripe's `paid` payment status confirms a payment. A failed delayed PaymentIntent fails the operation.

For an authorization, the worker creates a direct card [PaymentIntent](https://docs.stripe.com/api/payment_intents/create) with `capture_method=manual`. Its identifier is the transaction's `external_id`, and its `client_secret` appears in transaction metadata. The application must show Stripe's [Payment Element](https://docs.stripe.com/payments/payment-element/migration) to the authorized payer using the payment method's `public_key`, then confirm that PaymentIntent with Stripe.js and the intent's return URL. Treat the client secret as payer-specific data. Synchronization confirms the hold when the PaymentIntent reaches `requires_capture`. Captures and voids must use the full authorized amount; [ordinary partial capture releases the remainder](https://docs.stripe.com/api/payment_intents/capture), which cannot be represented as a remaining Minos hold. A capture can then be refunded. Stripe controls the authorization window; Minos does not record a hold expiry from the PaymentIntent.

Authenticate Stripe webhook notifications in the application and call `$payments->synchronize($transaction)` for the matching payment, authorization, capture, void or refund. Minos retrieves the current Stripe object instead of applying an untrusted webhook snapshot. Checkout Sessions that expire without payment fail the operation. Stripe does not offer subscriptions through this adapter. Keep a payment method bound to the same Stripe account while operations are pending. Stripe requests use the transaction UUID as an [idempotency key](https://docs.stripe.com/api/idempotent_requests), with the same 23-hour replay deadline as BePaid. If a response is lost and no remote ID was saved, inspect Stripe's request logs using that UUID before replaying after the deadline.

BePaid freezes the request, including expiry and the saved-card token or encrypted card details, before enqueueing. It sends the transaction UUID as `RequestID` and stops replaying after 23 hours, within the provider's [24-hour idempotency retention](https://docs.bepaid.by/en/using_api/idempotent_requests/). Retrying an expired job does not extend this deadline. If the external identifier was lost, reconcile the provider operation by its tracking ID before applying an authoritative result; never blindly resend after retention expires. The [status query API](https://docs.bepaid.by/en/integration/card_api/transactions/status_query/) is used for synchronization by external ID. Keep a payment method tied to the same merchant account while its operations are pending.

Queued BePaid payment and authorization accept a new `EncryptedCard` or a saved `CardToken` without CVC. The adapter checks that a saved token belongs to the payer and payment method and is active and unexpired; token-plus-CVC inputs remain unsupported. When `EncryptedCard::persist` is true, Minos saves the returned card token after an immediate success or a later successful synchronization. Job payloads are encrypted with Laravel's `ShouldBeEncrypted`, contain no merchant credentials or Eloquent models, and are removed by the normal worker after completion. Failed jobs retain their encrypted payload until retried or pruned. The transaction persists only non-sensitive intent metadata and normalized results, never the encrypted card fields or raw provider response. HTTP failures and malformed responses throw without recording a false payment decline.

### Changes from the follow-up API

`Decision` is replaced by `TransactionStatus`; rename `Transaction::decision` and `Continuation::decision` to `status`, and `TransactionResponse::decision()` to `status()`. The former aggregate lifecycle statuses are removed. The base migration now defines a single operation `status` column and its balance-query index. This change requires recreating existing transaction tables from that migration or migrating existing data separately; changing an already applied base migration does not update its tables.

`FollowUp` and `PaymentGateway::followUp()` are replaced by persisted transactions and optional gateway interfaces. BePaid authorization is now explicit: replace the `settings['authorize']` switch with `Transaction::authorize($intent)` and `$gateway->authorize($intent)`. `BePaid::intent()` always creates a payment. Read the operation type from `Transaction::type`, rather than `Continuation::metadata['type']`.

For queued execution, use `PaymentManager` instead of calling the model factory and gateway separately. Move `MinosPlugin::providers()` registrations to the `phpinnacle-minos.providers` class list so workers and panels use the same registry. Direct transaction synchronization now requires the root version captured before the provider request.

Payment configuration and credentials are sensitive. Keep production secrets outside source control and validate webhook authenticity with the selected provider implementation.

## Testing

```bash
composer test
```

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
