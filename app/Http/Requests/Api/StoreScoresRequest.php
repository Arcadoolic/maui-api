<?php

namespace App\Http\Requests\Api;

use App\Enums\ScoreAttribution;
use App\Services\Scores\ScoreData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScoresRequest extends FormRequest
{
    public const MAX_SCORES = 100;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scores' => ['required', 'list', 'max:'.self::MAX_SCORES],
            'scores.*' => ['array'],
            'scores.*.id' => ['required', 'uuid', 'distinct'],
            'scores.*.player_id' => ['required', 'uuid'],
            // MAME short names, as in the catalog (PutCatalogGamesRequest).
            'scores.*.romname' => ['required', 'string', 'regex:/^[a-z0-9_]{1,32}$/'],
            'scores.*.table' => ['sometimes', 'string', 'regex:/^[a-z0-9_]{1,32}$/'],
            // PostgreSQL bigint: MAUI sends JavaScript numbers, exact up to 2^53.
            'scores.*.score' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'scores.*.rank_on_cabinet' => ['nullable', 'integer', 'min:1', 'max:999'],
            // The cabinet's clock: a few minutes ahead is tolerated, not the future.
            'scores.*.achieved_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'scores.*.startup_id' => ['nullable', 'uuid'],
            // Left out by the cabinets older than D61: the initials, as before.
            'scores.*.attribution' => ['sometimes', Rule::enum(ScoreAttribution::class)],
        ];
    }

    /**
     * @return list<ScoreData>
     */
    public function scores(): array
    {
        /** @var list<array<string, mixed>> $scores */
        $scores = $this->validated('scores');

        return array_map(ScoreData::fromValidated(...), $scores);
    }
}
