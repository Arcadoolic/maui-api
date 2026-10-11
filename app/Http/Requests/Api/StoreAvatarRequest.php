<?php

namespace App\Http\Requests\Api;

use App\Services\Players\PlayerAvatars;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAvatarRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $max = PlayerAvatars::MAX_DIMENSION;

        return [
            // The content is checked, not the name: a real PNG image.
            'avatar' => [
                'required', 'file', 'mimetypes:image/png', 'max:'.PlayerAvatars::MAX_SIZE,
                "dimensions:max_width={$max},max_height={$max}",
            ],
            // SHA-256 of the avatar the cabinet knows the player by (D83).
            'replaces' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
