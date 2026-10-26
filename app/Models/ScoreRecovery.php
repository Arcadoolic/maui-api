<?php

namespace App\Models;

use App\Enums\ScoreRecoveryStatus;
use Database\Factories\ScoreRecoveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to recover a score the cabinet's file holds but MAUI-API never
 * got (docs/DECISIONS.md D93): created by an administrator after checking it,
 * answered by the cabinet with the score itself (POST /scores) or not_found.
 *
 * @property int $id
 * @property string $uuid Also the id of the score the cabinet sends for it.
 * @property int $client_id
 * @property int $player_id
 * @property string $romname
 * @property string $table
 * @property int $score
 * @property Carbon $achieved_at
 * @property string $reason
 * @property int|null $diagnostic_id
 * @property ScoreRecoveryStatus $status
 * @property array<string, mixed>|null $result
 * @property int|null $score_id
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property-read Client $client
 * @property-read Player $player
 * @property-read Diagnostic|null $diagnostic
 * @property-read Score|null $recoveredScore
 */
class ScoreRecovery extends Model
{
    /** @use HasFactory<ScoreRecoveryFactory> */
    use HasFactory, HasUuids;

    public const KEEP_OPEN_DAYS = 30;

    protected $guarded = ['id'];

    protected $attributes = [
        'table' => Score::DEFAULT_TABLE,
        'status' => 'pending',
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
            'score' => 'integer',
            'achieved_at' => 'datetime',
            'status' => ScoreRecoveryStatus::class,
            'result' => 'array',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Diagnostic, $this> */
    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(Diagnostic::class);
    }

    /** @return BelongsTo<Score, $this> */
    public function recoveredScore(): BelongsTo
    {
        return $this->belongsTo(Score::class, 'score_id');
    }

    /**
     * Waiting for the cabinet, and not past its date.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ScoreRecoveryStatus::Pending)->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === ScoreRecoveryStatus::Pending && $this->expires_at->isFuture();
    }
}
