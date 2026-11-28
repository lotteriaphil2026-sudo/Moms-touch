<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InvestmentAllocation extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'daily_interest_rate' => 'decimal:6',
            'rate_basis_points' => 'integer', 'term_days' => 'integer', 'credited_days' => 'integer',
            'started_at' => 'immutable_datetime', 'matures_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
        ];
    }
    public function dailyInterestCents(): int
    {
        return $this->rate_basis_points === null ? 0 : intdiv($this->amount_cents * $this->rate_basis_points + 5000, 10000);
    }
}
