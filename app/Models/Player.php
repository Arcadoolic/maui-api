<?php

namespace App\Models;

use App\Enums\PlayerStatus;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * A player, global to the fleet: unique initials, linked to the cabinets it
 * plays on with a PIN (docs/DECISIONS.md D4, D48).
 *
 * @property int $id
 * @property string $uuid Public id, the only one cabinets see.
 * @property string $pseudo_3
 * @property bool $is_public
 * @property PlayerStatus $status
 * @property string $pin 4 digits, encrypted at rest (D49).
 * @property int $pin_failed_attempts
 * @property Carbon|null $pin_locked_at
 * @property string|null $avatar_hash SHA-256 of the avatar PNG, null without one (D53).
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory, HasUuids;

    /** Wrong PINs in a row before the player is locked. */
    public const MAX_PIN_ATTEMPTS = 5;

    protected $fillable = ['pseudo_3', 'is_public'];

    protected $hidden = ['pin'];

    protected $attributes = [
        'is_public' => false,
        'status' => 'active',
        'pin_failed_attempts' => 0,
    ];

    /**
     * HasUuids fills the public `uuid` column, not the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'pin' => 'encrypted',
            'status' => PlayerStatus::class,
            'pin_failed_attempts' => 'integer',
            'pin_locked_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Client, $this> */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withPivot('linked_at');
    }

    /** @return HasMany<Score, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /**
     * Audit trail, written explicitly by PlayerRegistry and PlayerAdministration
     * (no automatic model event logging).
     *
     * @return MorphMany<Activity, $this>
     */
    public function activitiesAsSubject(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /** A new random 4-digit PIN. */
    public static function generatePin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function pinMatches(string $pin): bool
    {
        return hash_equals($this->pin, $pin);
    }

    /** Replaces the PIN and clears the lock. Returns the new PIN. */
    public function regeneratePin(): string
    {
        $pin = self::generatePin();
        $this->forceFill(['pin' => $pin, 'pin_failed_attempts' => 0, 'pin_locked_at' => null])->save();

        return $pin;
    }

    public function isActive(): bool
    {
        return $this->status === PlayerStatus::Active;
    }

    public function isLocked(): bool
    {
        return $this->pin_locked_at !== null;
    }

    public function disable(): void
    {
        $this->forceFill(['status' => PlayerStatus::Disabled])->save();
    }

    public function enable(): void
    {
        $this->forceFill(['status' => PlayerStatus::Active])->save();
    }

    public function unlock(): void
    {
        $this->forceFill(['pin_failed_attempts' => 0, 'pin_locked_at' => null])->save();
    }

    /** `active`, `disabled` or `locked`, as the API reports it. */
    public function apiStatus(): string
    {
        return match (true) {
            ! $this->isActive() => PlayerStatus::Disabled->value,
            $this->isLocked() => 'locked',
            default => PlayerStatus::Active->value,
        };
    }

    /** @return array{id: string, pseudo_3: string, is_public: bool, status: string, avatar: string|null} */
    public function toApiArray(): array
    {
        return [
            'id' => $this->uuid,
            'pseudo_3' => $this->pseudo_3,
            'is_public' => $this->is_public,
            'status' => $this->apiStatus(),
            // SHA-256 of the avatar (D53): the cabinet sends its PNG again when it differs.
            'avatar' => $this->avatar_hash,
        ];
    }
}
