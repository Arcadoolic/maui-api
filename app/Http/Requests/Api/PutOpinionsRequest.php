<?php

namespace App\Http\Requests\Api;

use App\Models\GameOpinion;
use App\Services\Opinions\OpinionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PutOpinionsRequest extends FormRequest
{
    public const MAX_OPINIONS = 500;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'opinions' => ['required', 'list', 'max:'.self::MAX_OPINIONS],
            'opinions.*' => ['array'],
            // MAME short names, as in the catalog (PutCatalogGamesRequest).
            'opinions.*.romname' => ['required', 'string', 'regex:/^[a-z0-9_]{1,32}$/', 'distinct'],
            'opinions.*.vote' => ['required', 'integer', Rule::in([GameOpinion::VOTE_DOWN, GameOpinion::VOTE_NEUTRAL, GameOpinion::VOTE_UP])],
            'opinions.*.play_count' => ['required', 'integer', 'min:0', 'max:2147483647'],
            // The cabinet's clock: a few minutes ahead is tolerated, not the future.
            'opinions.*.last_played_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }

    /**
     * @return list<OpinionData>
     */
    public function opinions(): array
    {
        /** @var list<array<string, mixed>> $opinions */
        $opinions = $this->validated('opinions');

        return array_map(OpinionData::fromValidated(...), $opinions);
    }
}
