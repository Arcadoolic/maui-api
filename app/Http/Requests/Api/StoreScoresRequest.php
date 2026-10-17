<?php

namespace App\Http\Requests\Api;

use App\Enums\ScoreAttribution;
use App\Services\Scores\ScoreData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScoresRequest extends FormRequest
{
    public const MAX_SCORES = 100;

    /** A report is a few hundred bytes; this leaves room for a game with many DIP switches. */
    public const MAX_INTEGRITY_BYTES = 16_384;

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
            // The cabinet's report on the game (D87), left out by the cabinets older than
            // it. Kept raw: only the parts the verdict reads are checked, and its size.
            'scores.*.integrity' => ['sometimes', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen((string) json_encode($value)) > self::MAX_INTEGRITY_BYTES) {
                    $fail('The integrity report is too large.');
                }
            }],
            // Every documented part needs a rule: validated() keeps nothing else.
            'scores.*.integrity.version' => ['required_with:scores.*.integrity', 'integer', 'min:1'],
            'scores.*.integrity.session_id' => ['sometimes', 'uuid'],
            'scores.*.integrity.mame_version' => ['sometimes', 'string', 'max:32'],
            'scores.*.integrity.maui_version' => ['sometimes', 'string', 'max:32'],
            'scores.*.integrity.duration_s' => ['sometimes', 'numeric', 'min:0'],
            'scores.*.integrity.emulated_s' => ['sometimes', 'numeric', 'min:0'],
            'scores.*.integrity.options' => ['sometimes', 'array'],
            'scores.*.integrity.options.speed' => ['sometimes', 'numeric'],
            'scores.*.integrity.options.*' => ['nullable'],
            'scores.*.integrity.plugins' => ['sometimes', 'list', 'max:64'],
            'scores.*.integrity.plugins.*' => ['string', 'max:64'],
            'scores.*.integrity.rom' => ['sometimes', 'string', 'in:good,best_available,bad,unknown'],
            'scores.*.integrity.dips' => ['sometimes', 'list', 'max:64'],
            'scores.*.integrity.dips.*.name' => ['required', 'string', 'max:64'],
            'scores.*.integrity.dips.*.value' => ['required', 'string', 'max:64'],
            'scores.*.integrity.dips.*.default' => ['required', 'string', 'max:64'],
            'scores.*.integrity.events' => ['sometimes', 'array'],
            'scores.*.integrity.events.*' => ['numeric', 'min:0'],
            'scores.*.integrity.speed' => ['sometimes', 'array'],
            'scores.*.integrity.speed.*' => ['numeric', 'min:0'],
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
