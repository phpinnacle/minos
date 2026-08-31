<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Livewire\Wireable;
use PHPinnacle\Money\Money;

readonly class PaymentScheme implements Arrayable, Countable, Wireable
{
    public function __construct(
        /** @var Collection<PaymentPart> $parts */
        public Collection $parts,
    ) {}

    public static function attribute(string $field = 'scheme'): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => self::create(json_decode($value, true)),
            set: fn (self $value) => [$field => json_encode($value->toArray())],
        );
    }

    public static function create(array $value): self
    {
        return new self(
            collect($value)
                ->map(fn (PaymentPart|array $item) => PaymentPart::resolve($item))
                ->sortBy(fn (PaymentPart $item) => $item->date)
                ->values(),
        );
    }

    public static function fromLivewire($value): self
    {
        return $value !== null ? self::create($value) : new self(collect());
    }

    public static function once(Money $amount, CarbonImmutable $date): self
    {
        return new self(collect([
            new PaymentPart($amount, $date),
        ]));
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

    public function count(): int
    {
        return $this->parts->count();
    }

    public function expires(): ?CarbonImmutable
    {
        return $this->parts->last()?->date;
    }

    public function render(): array
    {
        return $this->parts->map(fn (PaymentPart $part) => $part->toArray())->all();
    }

    public function starts(): ?CarbonImmutable
    {
        return $this->parts->first()?->date;
    }

    public function toArray(): array
    {
        return $this->parts->map(fn (PaymentPart $part) => $part->toArray())->all();
    }

    public function toLivewire(): array
    {
        return $this->parts->map(fn (PaymentPart $part) => $part->toLivewire())->all();
    }

    public function total(): Money
    {
        return Money::sum(...$this->parts->map(fn (PaymentPart $part) => $part->amount));
    }
}
