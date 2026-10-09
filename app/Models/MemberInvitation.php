<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A link that lets Discord accounts become members of the hiscores front
 * (docs/DECISIONS.md D65). Only the SHA-256 hash of its token is stored.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $label
 * @property int|null $max_uses Null: no limit.
 * @property int $uses
 * @property Carbon|null $expires_at Null: never.
 * @property Carbon|null $revoked_at
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class MemberInvitation extends Model
{
    protected $fillable = ['label', 'max_uses', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected $attributes = [
        'uses' => 0,
    ];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @param  Builder<MemberInvitation>  $query
     * @return Builder<MemberInvitation>
     */
    public function scopeForToken(Builder $query, string $plainToken): Builder
    {
        return $query->where('token_hash', self::hashToken($plainToken));
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsedUp(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->isUsedUp();
    }

    /** `usable`, `revoked`, `expired` or `used_up`, for the back office. */
    public function state(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            $this->isUsedUp() => 'used_up',
            default => 'usable',
        };
    }
}
