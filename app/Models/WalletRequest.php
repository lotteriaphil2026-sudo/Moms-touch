<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletRequest extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'reviewed_at' => 'datetime'];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function publicData(): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'type' => $this->type,
            'amount' => $this->amount_cents, 'status' => $this->status,
            'member_note' => $this->member_note, 'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(), 'created_at' => $this->created_at->toIso8601String()];
    }
}
