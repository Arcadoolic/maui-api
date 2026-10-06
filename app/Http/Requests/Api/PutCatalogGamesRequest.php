<?php

namespace App\Http\Requests\Api;

use App\Services\Catalog\CatalogGameData;
use Illuminate\Foundation\Http\FormRequest;

final class PutCatalogGamesRequest extends FormRequest
{
    public const MAX_GAMES = 500;

    /** MAME short names: lowercase letters, digits and underscores. */
    private const ROMNAME = 'regex:/^[a-z0-9_]{1,32}$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'games' => ['required', 'list', 'max:'.self::MAX_GAMES],
            'games.*' => ['array'],
            'games.*.romname' => ['required', 'string', self::ROMNAME, 'distinct'],
            'games.*.description' => ['required', 'string', 'max:255'],
            'games.*.manufacturer' => ['nullable', 'string', 'max:255'],
            // MAME years are not always numbers: "198?", "19??".
            'games.*.year' => ['nullable', 'string', 'regex:/^[0-9?]{4}$/'],
            'games.*.parent_romname' => ['nullable', 'string', self::ROMNAME],
            'games.*.player_sim' => ['nullable', 'integer', 'min:0', 'max:255'],
            'games.*.player_alt' => ['nullable', 'integer', 'min:0', 'max:255'],
            'games.*.genre' => ['nullable', 'string', 'max:128'],
            'games.*.catver_genre' => ['nullable', 'required_with:games.*.catver_subgenre', 'string', 'max:128'],
            'games.*.catver_subgenre' => ['nullable', 'string', 'max:128'],
            'games.*.mature' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<CatalogGameData>
     */
    public function games(): array
    {
        /** @var list<array<string, mixed>> $games */
        $games = $this->validated('games');

        return array_map(CatalogGameData::fromValidated(...), $games);
    }
}
