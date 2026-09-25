<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versions reported by a MAUI cabinet when it starts in ONLINE mode.
 *
 * @property string $id
 * @property int $client_id
 * @property string $mame_version
 * @property string $maui_version
 * @property string $os
 * @property string $os_version Kernel version (Node os.release()).
 * @property string|null $os_name Readable OS name, e.g. "Ubuntu 24.04.5 LTS".
 * @property Carbon $client_datetime
 * @property Carbon $received_at
 */
class ClientStartup extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['mame_version', 'maui_version', 'os', 'os_version', 'os_name', 'client_datetime', 'received_at'];

    protected function casts(): array
    {
        return [
            'client_datetime' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
