<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FranchiseApplication extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_id', 'request_fingerprint'];
    protected function casts(): array { return ['budget_cents' => 'integer', 'consent_at' => 'datetime']; }
}
