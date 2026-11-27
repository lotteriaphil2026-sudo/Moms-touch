<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WalletTransaction extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'balance_after_cents' => 'integer', 'read_at' => 'datetime'];
    }
}
