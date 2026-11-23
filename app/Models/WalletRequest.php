<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletRequest extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'reviewed_at' => 'datetime',
            'test_only' => 'boolean',
            'withdrawal_account_holder' => 'encrypted',
            'withdrawal_account_number' => 'encrypted',
        ];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function publicData(): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'type' => $this->type,
            'amount' => $this->amount_cents, 'status' => $this->status,
            'payment_method' => $this->payment_method, 'test_only' => (bool) $this->test_only,
            'member_note' => $this->member_note, 'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(), 'created_at' => $this->created_at->toIso8601String()];
    }

    public function adminData(): array
    {
        return [
            ...$this->publicData(),
            'withdrawal_destination' => $this->withdrawal_account_type ? [
                'type' => $this->withdrawal_account_type,
                'provider' => $this->withdrawal_account_provider,
                'account_holder' => $this->withdrawal_account_holder,
                'account_number' => $this->withdrawal_account_number,
            ] : null,
        ];
    }
}
