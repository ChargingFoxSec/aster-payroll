<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;

class CompensationAmountParser
{
    public function parseMinor(string $amount): int
    {
        if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount)) {
            throw new UserFacingException('The compensation amount must be a valid currency value.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
