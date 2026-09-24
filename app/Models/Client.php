<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Services\ClientNameGenerator;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A machine allowed to call the API: a MAUI cabinet or a service account.
 *
 * @property int $id
 * @property string $public_key
 * @property string $name
 * @property string $email
 * @property string|null $notes
 * @property ClientType $type
 * @property ClientStatus $status
 * @property string|null $machine_fingerprint_hash
 * @property Carbon|null $bound_at
 * @property Carbon|null $last_heartbeat_at
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasApiTokens, HasFactory;

    public const PUBLIC_KEY_PREFIX = 'mk_';

    /** A cabinet is online when its last heartbeat is more recent than this. */
    public const ONLINE_THRESHOLD_MINUTES = 3;

    protected $fillable = ['name', 'email', 'notes', 'type', 'status'];

    protected $hidden = ['machine_fingerprint_hash'];

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->public_key ??= self::PUBLIC_KEY_PREFIX.Str::random(24);
            $client->name ??= app(ClientNameGenerator::class)->generate();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'status' => ClientStatus::class,
            'bound_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<ClientStartup, $this> */
    public function startups(): HasMany
    {
        return $this->hasMany(ClientStartup::class);
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }

    public function isOnline(): bool
    {
        return $this->last_heartbeat_at !== null
            && $this->last_heartbeat_at->greaterThan(now()->subMinutes(self::ONLINE_THRESHOLD_MINUTES));
    }

    /** Blocks the client without deleting its token (docs/DECISIONS.md D12). */
    public function disable(): void
    {
        $this->update(['status' => ClientStatus::Disabled]);
    }

    public function enable(): void
    {
        $this->update(['status' => ClientStatus::Active]);
    }

    /** Lets the next authenticated request bind the client to a new machine. */
    public function resetMachineBinding(): void
    {
        $this->forceFill(['machine_fingerprint_hash' => null, 'bound_at' => null])->save();
    }

    public function recordHeartbeat(): void
    {
        $this->forceFill(['last_heartbeat_at' => now()])->save();
    }
}
