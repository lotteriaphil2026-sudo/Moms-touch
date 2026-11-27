<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance_cents', 'invested_cents', 'earnings_cents', 'held_cents'];
    protected function casts(): array
    {
        return ['balance_cents' => 'integer', 'invested_cents' => 'integer', 'earnings_cents' => 'integer', 'held_cents' => 'integer'];
    }
}
