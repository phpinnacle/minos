<?php

namespace PHPinnacle\Minos\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

readonly class PaymentParts implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('phpinnacle-minos::validation.payment_parts.format')->translate();

            return;
        }

        if (empty($value)) {
            $fail('phpinnacle-minos::validation.payment_parts.empty')->translate();

            return;
        }

        $values = array_map(floatval(...), array_column($value, 'value'));

        if (array_sum($values) !== 100.0) {
            $fail('phpinnacle-minos::validation.payment_parts.sum')->translate();
        }
    }
}
