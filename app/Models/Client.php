<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Services\ClientNameGenerator;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A machine allowed to call the API: a MAUI cabinet or a service account.
 *
 * @property int $id
 * @property string $public_key
 * @property string $name
 * @property string $owner_name
 * @property string $email
 * @property string|null $notes
 * @property ClientType $type
 * @property ClientStatus $status
 * @property string|null $machine_fingerprint_hash
 * @property Carbon|null $bound_at
 * @property Carbon|null $last_heartbeat_at
 * @property string|null $latest_startup_id
 * @property-read ClientStartup|null $latestStartup
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasApiTokens, HasFactory, LogsActivity;

    public const PUBLIC_KEY_PREFIX = 'mk_';

    /** A cabinet is online when its last heartbeat is more recent than this. */
    public const ONLINE_THRESHOLD_MINUTES = 3;

    protected $fillable = ['name', 'owner_name', 'email', 'notes', 'type', 'status'];

    protected $hidden = ['machine_fingerprint_hash'];

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->public_key ??= self::PUBLIC_KEY_PREFIX.Str::random(24);

            // Arcade names are for cabinets; a service account needs a descriptive one (D39).
            // The name is still unset while creating: read the raw attribute.
            if ($client->getAttribute('name') === null && $client->type === ClientType::Service) {
                throw new InvalidArgumentException('A service account needs an explicit, descriptive name.');
            }

            $client->name ??= app(ClientNameGenerator::class)->generate();
        });
    }

    /**
     * Audit of profile changes (creation, owner, email, notes...). Status changes,
     * binding and tokens are logged as explicit events by ClientAdministration;
     * technical columns (heartbeat) are never logged.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('clients')
            ->logOnly(['name', 'owner_name', 'email', 'notes', 'type'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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

    /** @return BelongsTo<ClientStartup, $this> */
    public function latestStartup(): BelongsTo
    {
        return $this->belongsTo(ClientStartup::class, 'latest_startup_id');
    }

    public function hasCredentials(): bool
    {
        return $this->tokens()->exists();
    }

    public function isBound(): bool
    {
        return $this->machine_fingerprint_hash !== null;
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

    /**
     * Records the versions reported at startup; a startup also counts as a heartbeat.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordStartup(array $attributes): ClientStartup
    {
        $startup = $this->startups()->create([...$attributes, 'received_at' => now()]);

        $this->forceFill(['latest_startup_id' => $startup->id, 'last_heartbeat_at' => now()])->save();
        $this->setRelation('latestStartup', $startup);

        return $startup;
    }

    public function recordHeartbeat(): void
    {
        $this->forceFill(['last_heartbeat_at' => now()])->save();
    }
}
