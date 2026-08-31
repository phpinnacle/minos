<?php

namespace PHPinnacle\Minos\Instruments;

use PHPinnacle\Minos\Contracts\Instrument;

class EncryptedCard implements Instrument
{
    public function __construct(
        public string $number,
        public string $holder,
        public string $expMonth,
        public string $expYear,
        public string $verificationValue,
        public bool $persist = false,
    ) {}

    /**
     * @param array{
     *     number: string,
     *     holder: string,
     *     exp_month: string,
     *     exp_year: string,
     *     verification_value: string,
     *     persist?: bool|int|string,
     * } $data
     */
    public static function create(array $data): self
    {
        return new self(
            number: $data['number'],
            holder: $data['holder'],
            expMonth: $data['exp_month'],
            expYear: $data['exp_year'],
            verificationValue: $data['verification_value'],
            persist: (bool) filter_var($data['persist'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function persisted(): self
    {
        return new self(
            number: $this->number,
            holder: $this->holder,
            expMonth: $this->expMonth,
            expYear: $this->expYear,
            verificationValue: $this->verificationValue,
            persist: true,
        );
    }
}
