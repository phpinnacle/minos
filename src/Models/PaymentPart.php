<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Livewire\Wireable;
use PHPinnacle\Money\Money;

/** @implements Arrayable<string, Money|CarbonImmutable> */
readonly class PaymentPart implements Arrayable, Wireable
{
    public function __construct(
        public Money $amount,
        public CarbonImmutable $date,
    ) {}

    /**
     * @param array{amount?: Money|array{amount: int|string|null, currency?: string|null}|null, date?: string|\DateTimeInterface|null} $data
     */
    public static function create(array $data): self
    {
        return ($data['amount'] ?? null) !== null && ($data['date'] ?? null) !== null
            ? new self(Money::parse($data['amount']), CarbonImmutable::parse($data['date']))
            : throw new InvalidArgumentException('Invalid data provided for payment part.');
    }

    /**
     * @param array{amount?: Money|array{amount: int|string|null, currency?: string|null}|null, date?: string|\DateTimeInterface|null}|self $data
     */
    public static function resolve(array|self $data): self
    {
        return is_array($data) ? self::create($data) : $data;
    }

    public static function fromLivewire(mixed $value): ?self
    {
        return $value !== null ? self::create($value) : null;
    }

    public function add(Money $amount): self
    {
        return new self($this->amount->add($amount), $this->date);
    }

    /**
     * @return array{amount: Money, date: CarbonImmutable}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'date' => $this->date,
        ];
    }

    /**
     * @return array{amount: array{amount: string, currency: string}, date: CarbonImmutable}
     */
    public function toLivewire(): array
    {
        return [
            'amount' => $this->amount->toLivewire(),
            'date' => $this->date,
        ];
    }
}
