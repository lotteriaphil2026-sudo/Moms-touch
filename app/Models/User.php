<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['is_admin' => false, 'is_dummy' => false, 'referral_preview_type' => 'standard'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'date_of_birth',
        'address',
        'gender',
        'marital_status',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date:Y-m-d',
            'investment_nudge_sent_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_dummy' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (!$user->referral_code) {
                $user->referral_code = static::generateReferralCode($user->name);
            }
        });

        static::saving(function (User $user): void {
            $user->referrer_type = $user->referrer_id ? 'member' : 'platform';
        });
    }

    public static function generateReferralCode(string $name, ?int $excludeUserId = null): string
    {
        $base = preg_replace('/[^A-Z0-9]+/', '-', Str::upper(Str::ascii(trim($name))));
        $base = trim((string) $base, '-');
        $base = $base !== '' ? substr($base, 0, 74) : 'MEMBER';

        $code = $base;
        $suffix = 2;
        while (static::query()
            ->where('referral_code', $code)
            ->when($excludeUserId, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->exists()) {
            $tail = '-'.$suffix++;
            $code = substr($base, 0, 80 - strlen($tail)).$tail;
        }

        return $code;
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    public function signIns(): HasMany
    {
        return $this->hasMany(MemberSignIn::class);
    }
}
