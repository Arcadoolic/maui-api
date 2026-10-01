<?php

namespace App\Http\Requests\Api;

use App\Support\Pseudo3;
use Illuminate\Foundation\Http\FormRequest;

final class StorePlayerRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'pseudo_3' => Pseudo3::rules(),
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
