<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use LogicException;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Events\TransactionCreated;
use PHPinnacle\Minos\Events\TransactionStatusChanged;
use PHPinnacle\Minos\Events\TransactionUpdated;
use PHPinnacle\Minos\Exceptions\StaleTransaction;
use PHPinnacle\Money\Money;

/**
 * @property string $id
 * @property string $method_id
 * @property string|null $parent_id
 * @property string $source_type
 * @property string $source_id
 * @property string $payer_type
 * @property string $payer_id
 * @property string $number
 * @property string $description
 * @property string|null $reason
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property int $version
 * @property Money $amount
 * @property string $currency
 * @property string|null $external_id
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PaymentMethod $method
 * @property-read self|null $parent
 */
class Transaction extends Model
{
    use HasUuids;

    protected $table = 'payment_transactions';

    protected $attributes = [
        'status' => 'pending',
        'version' => 0,
        'metadata' => '{}',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'status' => TransactionStatus::class,
        'version' => 'integer',
        'metadata' => 'array',
        'expires_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (self $transaction) {
            TransactionCreated::dispatch($transaction);
        });

        static::updated(function (self $transaction) {
            if (array_diff(array_keys($transaction->getChanges()), ['version', 'updated_at']) !== []) {
                TransactionUpdated::dispatch($transaction);
            }

            if (!$transaction->wasChanged('status')) {
                return;
            }

            TransactionStatusChanged::dispatch(
                $transaction,
                TransactionStatus::from($transaction->getRawOriginal('status')),
                $transaction->status,
            );
        });
    }

    public static function payment(Intent $intent): self
    {
        return self::record($intent, TransactionType::PAYMENT);
    }

    public static function authorize(Intent $intent): self
    {
        return self::record($intent, TransactionType::AUTHORIZE);
    }

    private static function record(Intent $intent, TransactionType $type): self
    {
        $amount = $intent->total();

        if ($amount->amount <= 0) {
            throw new InvalidArgumentException('A transaction amount must be positive.');
        }

        $transaction = new self;
        $transaction->id = $intent->id;
        $transaction->method()->associate($intent->method);
        $transaction->number = $intent->number;
        $transaction->description = $intent->description;
        $transaction->source_type = $intent->source->type;
        $transaction->source_id = $intent->source->id;
        $transaction->payer_type = $intent->payer->type;
        $transaction->payer_id = $intent->payer->id;
        $transaction->amount = $amount;
        $transaction->type = $type;
        $transaction->save();

        return $transaction;
    }

    public function capture(string $number, Money $amount): self
    {
        return $this->reserve(TransactionType::CAPTURE, $number, $amount);
    }

    public function void(string $number, Money $amount): self
    {
        return $this->reserve(TransactionType::VOID, $number, $amount);
    }

    public function refund(string $number, Money $amount, string $reason): self
    {
        return $this->reserve(TransactionType::REFUND, $number, $amount, $reason);
    }

    private function reserve(TransactionType $type, string $number, Money $amount, ?string $reason = null): self
    {
        $rootId = $this->root()->id;

        return $this->getConnection()->transaction(function () use ($rootId, $type, $number, $amount, $reason) {
            $root = $this->newQuery()->lockForUpdate()->findOrFail($rootId);
            $parent = $root->id === $this->id
                ? $root
                : $this->newQuery()->lockForUpdate()->findOrFail($this->id);
            $available = $type === TransactionType::REFUND ? $parent->refundable() : $parent->capturable();

            if ($amount->amount <= 0 || !$available->gt($amount, equal: true)) {
                throw new InvalidArgumentException(
                    'The amount must be positive and must not exceed the available balance.',
                );
            }

            $transaction = new self;
            $transaction->parent()->associate($parent);
            $transaction->method_id = $parent->method_id;
            $transaction->source_type = $parent->source_type;
            $transaction->source_id = $parent->source_id;
            $transaction->payer_type = $parent->payer_type;
            $transaction->payer_id = $parent->payer_id;
            $transaction->number = $number;
            $transaction->description = $parent->description;
            $transaction->reason = $reason;
            $transaction->amount = $amount;
            $transaction->type = $type;
            $transaction->save();
            $root->increment('version');

            return $transaction;
        });
    }

    public function capturable(): Money
    {
        if ($this->status !== TransactionStatus::Success || $this->type !== TransactionType::AUTHORIZE) {
            throw new LogicException('Only a successful authorization can be captured or voided.');
        }

        return $this->remaining([TransactionType::CAPTURE, TransactionType::VOID]);
    }

    public function refundable(): Money
    {
        if (
            $this->status !== TransactionStatus::Success
            || !in_array($this->type, [TransactionType::PAYMENT, TransactionType::CAPTURE], true)
        ) {
            throw new LogicException('Only a successful payment or capture can be refunded.');
        }

        return $this->remaining([TransactionType::REFUND]);
    }

    /** @param list<TransactionType> $types */
    private function remaining(array $types): Money
    {
        $reserved = $this->sumAmounts(
            $this
                ->children()
                ->getQuery()
                ->whereIn('type', $types)
                ->whereIn('status', [TransactionStatus::Pending, TransactionStatus::Success]),
        );

        return $this->amount->sub($reserved);
    }

    public function handle(Continuation $continuation, ?int $expectedVersion = null): self
    {
        return $this->apply($continuation, synchronize: false, expectedVersion: $expectedVersion);
    }

    public function synchronize(Continuation $continuation, int $expectedVersion): self
    {
        return $this->apply($continuation, synchronize: true, expectedVersion: $expectedVersion);
    }

    private function apply(Continuation $continuation, bool $synchronize, ?int $expectedVersion): self
    {
        $rootId = $this->root()->id;

        $this->getConnection()->transaction(function () use ($rootId, $continuation, $synchronize, $expectedVersion) {
            $root = $this->newQuery()->lockForUpdate()->findOrFail($rootId);
            $transaction = $root->id === $this->id
                ? $root
                : $this->newQuery()->lockForUpdate()->findOrFail($this->id);

            if (!$synchronize && $transaction->status !== TransactionStatus::Pending) {
                return;
            }

            if ($expectedVersion !== null && $root->version !== $expectedVersion) {
                throw new StaleTransaction('The payment changed while the provider request was in flight.');
            }

            $transaction->applyContinuation($continuation);

            if ($transaction->isDirty()) {
                $root->version++;
                $transaction->save();

                if ($root->id !== $transaction->id) {
                    $root->save();
                }
            }
        });

        return $this->refresh();
    }

    private function applyContinuation(Continuation $continuation): void
    {
        $this->status = $continuation->status;
        $this->external_id = $continuation->externalId ?? $this->external_id;

        if ($continuation->expiresAt !== null) {
            $this->expires_at = CarbonImmutable::instance($continuation->expiresAt);
        }

        $this->metadata = array_replace_recursive($this->metadata, $continuation->metadata);
        $this->processed_at = match (true) {
            $continuation->status === TransactionStatus::Pending => null,
            $this->isDirty('status') => $this->freshTimestamp(),
            default => $this->processed_at,
        };
    }

    public function balanceImpact(): Money
    {
        if ($this->status !== TransactionStatus::Success) {
            return Money::zero($this->currency);
        }

        return match ($this->type) {
            TransactionType::PAYMENT, TransactionType::CAPTURE => $this->amount,
            TransactionType::REFUND => $this->amount->mul(-1),
            TransactionType::AUTHORIZE, TransactionType::VOID => Money::zero($this->currency),
        };
    }

    public function root(): self
    {
        return $this->parent?->root() ?? $this;
    }

    public function captured(): Money
    {
        if ($this->type === TransactionType::AUTHORIZE) {
            return $this->sumAmounts(
                $this
                    ->children()
                    ->getQuery()
                    ->where('type', TransactionType::CAPTURE)
                    ->where('status', TransactionStatus::Success),
            );
        }

        return in_array($this->type, [TransactionType::PAYMENT, TransactionType::CAPTURE], true)
            ? $this->balanceImpact()
            : Money::zero($this->currency);
    }

    public function refunded(): Money
    {
        $captures = $this
            ->newQuery()
            ->select('id')
            ->where('parent_id', $this->id)
            ->where('type', TransactionType::CAPTURE);

        if ($this->getConnection()->transactionLevel() > 0) {
            $captures = $captures->lockForUpdate()->pluck('id');
        }

        return $this->sumAmounts($this
            ->newQuery()
            ->where('type', TransactionType::REFUND)
            ->where('status', TransactionStatus::Success)
            ->where(fn (Builder $query) => $query->where('parent_id', $this->id)->orWhereIn('parent_id', $captures)));
    }

    public function received(): Money
    {
        return $this->captured()->sub($this->refunded());
    }

    /** @param Builder<covariant self> $query */
    private function sumAmounts(Builder $query): Money
    {
        // Locking reads must see current rows even inside an existing REPEATABLE READ transaction.
        $amount = $this->getConnection()->transactionLevel() > 0
            ? $query->lockForUpdate()->toBase()->pluck('amount')->sum()
            : $query->sum('amount');

        return new Money((int) $amount, $this->currency);
    }

    /** @return Builder<self> */
    public static function forSource(Source $source): Builder
    {
        return self::query()->where('source_type', $source->type)->where('source_id', $source->id);
    }

    /** @return Attribute<Money|null, Money|array{amount: int|string|null, currency?: string|null}|int|null> */
    public function amount(): Attribute
    {
        return Money::attribute('amount');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'method_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function payer(): MorphTo
    {
        return $this->morphTo();
    }
}
