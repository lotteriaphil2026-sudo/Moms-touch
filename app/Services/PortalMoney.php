<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
class PortalMoney
{
    public static function cents(string $amount, ?int $maximumCents = 100000000): int
    {
        if (!preg_match('/\A\d{1,14}(?:\.\d{1,2})?\z/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Enter pesos using up to two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents < 10000 || ($maximumCents !== null && $cents > $maximumCents)) {
            $message = $maximumCents === null
                ? 'Enter an amount of at least ₱100.'
                : 'Enter an amount between ₱100 and ₱1,000,000.';
            throw ValidationException::withMessages(['amount' => $message]);
        }
        return $cents;
    }
}
