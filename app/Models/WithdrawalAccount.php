<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalAccount extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'provider',
        'account_holder',
        'account_number',
    ];

    protected function casts(): array
    {
        return [
            'account_holder' => 'encrypted',
            'account_number' => 'encrypted',
        ];
    }

    public function publicData(): array
    {
        $number = preg_replace('/\s+/', '', $this->account_number);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'provider' => $this->provider,
            'account_holder' => $this->account_holder,
            'last_four' => substr($number, -4),
        ];
    }
}
