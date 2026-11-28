<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberCertificate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'indicated_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
