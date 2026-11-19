<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
class PortalMoney
{
    public static function cents(string $amount): int
    {
        if (!preg_match('/\A\d{1,7}(?:\.\d{1,2})?\z/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Enter pesos using up to two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents < 10000 || $cents > 100000000) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount between ₱100 and ₱1,000,000.']);
        }
        return $cents;
    }
}
