<?php

namespace PHPinnacle\Minos\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPinnacle\Minos\Models\PaymentScheme as SchemeModel;
use PHPinnacle\Money\Comparison;
use PHPinnacle\Money\Money;
use Throwable;

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
            $value = array_map(fn (array $item) => [
                'amount' => $item[$this->amountField] ?? null,
                'date' => $item[$this->dateField] ?? null,
            ], array_values($value));
            $scheme = SchemeModel::create($value);

            if ($this->amount !== null && !$this->comparison->satisfy($scheme->total(), $this->amount)) {
                $fail(sprintf(
                    'phpinnacle-minos::validation.payment_scheme.amount.%s',
                    $this->comparison->value,
                ))->translate([
                    'value' => $this->amount->decimal(),
                ]);
            }
        } catch (Throwable $e) {
            $fail('phpinnacle-minos::validation.payment_scheme.invalid')->translate([
                'error' => $e->getMessage(),
            ]);
        }
    }
}
