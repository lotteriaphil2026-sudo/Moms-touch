<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KoreaRaffleTicket extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date:Y-m-d',
        ];
    }
}
