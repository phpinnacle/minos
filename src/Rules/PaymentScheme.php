<?php

namespace PHPinnacle\Minos\Rules;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use PHPinnacle\Minos\Models\PaymentScheme as SchemeModel;
use PHPinnacle\Money\Comparison;
use PHPinnacle\Money\Money;

readonly class PaymentScheme implements ValidationRule
{
    public function __construct(
        private ?Money $amount = null,
        private Comparison $comparison = Comparison::Equal,
        private string $amountField = 'amount',
        private string $dateField = 'date',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('phpinnacle-minos::validation.payment_scheme.format')->translate();

            return;
        }

        if ($value === []) {
            $fail('phpinnacle-minos::validation.payment_scheme.empty')->translate();

            return;
        }

        try {
            $parts = [];

            foreach ($value as $item) {
                if (!is_array($item)) {
                    $fail('phpinnacle-minos::validation.payment_scheme.invalid')->translate();

                    return;
                }

                $amount = $item[$this->amountField] ?? null;
                $date = $item[$this->dateField] ?? null;

                if (
                    !$this->isAmount($amount)
                    || !is_string($date)
                    && !$date instanceof DateTimeInterface
                    || $date === ''
                ) {
                    $fail('phpinnacle-minos::validation.payment_scheme.invalid')->translate();

                    return;
                }

                $parts[] = ['amount' => $amount, 'date' => $date];
            }

            $scheme = SchemeModel::create($parts);
            $total = $scheme->total();

            if ($this->amount !== null && !$this->comparison->satisfy($total, $this->amount)) {
                $fail(sprintf(
                    'phpinnacle-minos::validation.payment_scheme.amount.%s',
                    $this->comparison->value,
                ))->translate([
                    'value' => $this->amount->decimal(),
                ]);
            }
        } catch (InvalidArgumentException) {
            $fail('phpinnacle-minos::validation.payment_scheme.invalid')->translate();
        }
    }

    /** @phpstan-assert-if-true Money|array{amount: int|string, currency: string} $amount */
    private function isAmount(mixed $amount): bool
    {
        return (
            $amount instanceof Money
            || is_array($amount)
            && (is_int($amount['amount'] ?? null) || is_string($amount['amount'] ?? null))
            && $amount['amount'] !== ''
            && is_string($amount['currency'] ?? null)
        );
    }
}
