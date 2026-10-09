<?php

namespace App\Models;

use App\Enums\MemberStatus;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A member of the hiscores front: a Discord account let in by an invitation
 * (docs/DECISIONS.md D64). Not an admin (`User`), and no password: Discord
 * authenticates it.
 *
 * @property int $id
 * @property string $uuid Public id, the only one the front sees.
 * @property string $discord_id
 * @property string $username
 * @property string|null $display_name
 * @property string|null $discord_avatar
 * @property MemberStatus $status
 * @property int|null $member_invitation_id
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read MemberInvitation|null $invitation
 */
class Member extends Authenticatable
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['username', 'display_name', 'discord_avatar'];

    protected $hidden = ['remember_token'];

    protected $attributes = [
        'status' => 'active',
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
            'status' => MemberStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Player, $this> */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class)->withPivot('linked_at');
    }

    /** @return BelongsTo<MemberInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(MemberInvitation::class, 'member_invitation_id');
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }

    public function disable(): void
    {
        $this->forceFill(['status' => MemberStatus::Disabled])->save();
    }

    public function enable(): void
    {
        $this->forceFill(['status' => MemberStatus::Active])->save();
    }

    /** The name Discord shows: the display name when the account has one. */
    public function name(): string
    {
        return $this->display_name ?? $this->username;
    }

    public function avatarUrl(): ?string
    {
        return $this->discord_avatar === null
            ? null
            : "https://cdn.discordapp.com/avatars/{$this->discord_id}/{$this->discord_avatar}.png";
    }

    /**
     * The member as the front sees it.
     *
     * @return array{id: string, username: string, display_name: string|null, avatar: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->uuid,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'avatar' => $this->avatarUrl(),
        ];
    }
}
