<?php

namespace App\Models;

use Database\Factories\DiagnosticFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The files of a game sent from a cabinet's back office, for an
 * administrator to look into a missing score (docs/DECISIONS.md D92).
 *
 * @property int $id
 * @property string $uuid
 * @property int $client_id
 * @property string $romname
 * @property string|null $comment
 * @property array<string, mixed> $report What the cabinet says about the game.
 * @property list<array{area: string, name: string, size: int, modified: string|null, sha256: string}> $files
 * @property int $size Bytes, all files together.
 * @property bool $has_logs
 * @property Carbon $received_at
 * @property-read Client $client
 */
class Diagnostic extends Model
{
    /** @use HasFactory<DiagnosticFactory> */
    use HasFactory, HasUuids;

    public const DISK = 'local';

    public const AREAS = ['hiscore', 'nvram', 'logs', 'sessions'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // The files go with the row, whoever deletes it (purge, back office, client deletion).
        static::deleted(fn (Diagnostic $diagnostic) => Storage::disk(self::DISK)->deleteDirectory($diagnostic->directory()));
    }

    /**
     * HasUuids fills the public `uuid` column, not the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'files' => 'array',
            'size' => 'integer',
            'has_logs' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Where its files are on the disk. */
    public function directory(): string
    {
        return 'diagnostics/'.$this->uuid;
    }

    public function path(string $area, string $name): string
    {
        return $this->directory().'/'.$area.'/'.$name;
    }
}
