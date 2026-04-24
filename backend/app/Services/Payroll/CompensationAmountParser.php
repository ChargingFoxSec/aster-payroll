<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;

class CompensationAmountParser
{
    public function parseMinor(string $amount): int
    {
        $minorUnit = max(0, (int) config('payroll.currency.minor_unit', 2));
        $pattern = $minorUnit > 0
            ? '/^\d{1,9}(\.\d{1,'.$minorUnit.'})?$/'
            : '/^\d{1,9}$/';

        if (! preg_match($pattern, $amount)) {
            throw new UserFacingException(__('ui.messages.compensation_amount_invalid'));
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * (10 ** $minorUnit)) + (int) str_pad($fraction, $minorUnit, '0');
    }
}
