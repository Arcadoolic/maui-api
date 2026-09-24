<?php

namespace App\Models;

use App\Enums\InvitationPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Single-use link letting a cabinet owner retrieve credentials.
 * Only the SHA-256 hash of the link token is stored.
 *
 * @property int $id
 * @property int $client_id
 * @property string $token_hash
 * @property InvitationPurpose $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property string|null $claimed_ip
 * @property int|null $created_by
 * @property-read Client $client
 */
class Invitation extends Model
{
    protected $fillable = ['purpose', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => InvitationPurpose::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeForToken(Builder $query, string $plainToken): Builder
    {
        return $query->where('token_hash', self::hashToken($plainToken));
    }

    /**
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('claimed_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isClaimable(): bool
    {
        return ! $this->isClaimed() && ! $this->isExpired();
    }
}
