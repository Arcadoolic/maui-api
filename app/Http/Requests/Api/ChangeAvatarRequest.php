<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeAvatarRequest extends FormRequest
{
    /** Kilobytes: what the back office takes too (docs/DECISIONS.md D84). */
    public const MAX_SIZE = 12 * 1024;

    /**
     * Only that a file came: what it holds is read by AvatarImage.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'max:'.self::MAX_SIZE],
        ];
    }
}
