<?php

namespace App\Rules;

use App\Support\DomainNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Same rules as DomainNormalizer so API 422 messages match what bulk rows store as Failed.
 */
class DomainOrEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Enter a domain or email address.');

            return;
        }

        try {
            DomainNormalizer::fromInput($value);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }
}
