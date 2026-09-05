<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Livewire\Wireable;
use PHPinnacle\Money\Money;

readonly class PaymentPart implements Arrayable, Wireable
{
    public function __construct(
        public Money $amount,
        public CarbonImmutable $date,
    ) {}

    public static function create(array $data): self
    {
        return ($data['amount'] ?? null) !== null && ($data['date'] ?? null) !== null
            ? new self(Money::parse($data['amount']), CarbonImmutable::parse($data['date']))
            : throw new InvalidArgumentException('Invalid data provided for payment part.');
    }

    public static function fromLivewire($value): ?self
    {
        return $value !== null ? self::create($value) : null;
    }

    public static function resolve(array|self $data): self
    {
        return is_array($data) ? self::create($data) : $data;
    }

    public function add(Money $amount): self
    {
        return new self($this->amount->add($amount), $this->date);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'date' => $this->date,
        ];
    }

    public function toLivewire(): array
    {
        return [
            'amount' => $this->amount->toLivewire(),
            'date' => $this->date,
        ];
    }
}
