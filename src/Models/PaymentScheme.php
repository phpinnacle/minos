<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Livewire\Wireable;
use PHPinnacle\Money\Money;

/** @implements Arrayable<int, array{amount: Money, date: CarbonImmutable}> */
readonly class PaymentScheme implements Arrayable, Countable, Wireable
{
    /** @param Collection<int, PaymentPart> $parts */
    public function __construct(
        public Collection $parts,
    ) {}

    public static function once(Money $amount, CarbonImmutable $date): self
    {
        return new self(collect([
            new PaymentPart($amount, $date),
        ]));
    }

    /**
     * @param array<array-key, PaymentPart|array{amount?: Money|array{amount: int|string|null, currency?: string|null}|null, date?: string|\DateTimeInterface|null}> $value
     */
    public static function create(array $value): self
    {
        return new self(
            collect($value)
                ->map(PaymentPart::resolve(...))
                ->sortBy(fn (PaymentPart $item) => $item->date)
                ->values(),
        );
    }

    /**
     * @return Attribute<self, self>
     */
    public static function attribute(string $field = 'scheme'): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => self::create(json_decode($value, true)),
            set: fn (self $value) => [$field => json_encode($value->toArray())],
        );
    }

    public static function fromLivewire(mixed $value): self
    {
        return $value !== null ? self::create($value) : new self(collect());
    }

    public function adjust(Money $amount): self
    {
        if ($this->parts->isEmpty()) {
            return $this;
        }

        /** @var PaymentPart $first */
        $first = $this->parts->first();

        return new self($this->parts->skip(1)->prepend($first->add($amount)));
    }

    public function starts(): ?CarbonImmutable
    {
        return $this->parts->first()?->date;
    }

    public function expires(): ?CarbonImmutable
    {
        return $this->parts->last()?->date;
    }

    public function total(): Money
    {
        return Money::sum(...$this->parts->map(fn (PaymentPart $part) => $part->amount));
    }

    public function count(): int
    {
        return $this->parts->count();
    }

    public function toArray(): array
    {
        return $this->parts->map(fn (PaymentPart $part) => $part->toArray())->all();
    }

    /**
     * @return array<int, array{amount: array{amount: string, currency: string}, date: CarbonImmutable}>
     */
    public function toLivewire(): array
    {
        return $this->parts->map(fn (PaymentPart $part) => $part->toLivewire())->all();
    }

    /**
     * @return array<int, array{amount: Money, date: CarbonImmutable}>
     */
    public function render(): array
    {
        return $this->parts->map(fn (PaymentPart $part) => $part->toArray())->all();
    }
}
