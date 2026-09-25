<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStartupRequest extends FormRequest
{
    /** Node.js `process.platform` values MAUI can run on. */
    public const OPERATING_SYSTEMS = ['linux', 'darwin', 'win32'];

    /**
     * RFC 3339 date-times: offset required (`Z` or `+02:00`), optional
     * milliseconds or microseconds. `p` accepts `Z`, `P` accepts `+00:00`;
     * JavaScript `toISOString()` produces the `.vp` form.
     */
    private const DATETIME_FORMATS = 'Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.vp,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.up';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mame_version' => ['required', 'string', 'max:32'],
            'maui_version' => ['required', 'string', 'max:32'],
            'os' => ['required', Rule::in(self::OPERATING_SYSTEMS)],
            'os_version' => ['required', 'string', 'max:64'],
            // Optional: older MAUI versions do not send it (D42).
            'os_name' => ['nullable', 'string', 'max:64'],
            'client_datetime' => ['required', 'string', 'date_format:'.self::DATETIME_FORMATS],
        ];
    }
}
