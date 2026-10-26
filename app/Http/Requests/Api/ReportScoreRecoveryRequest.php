<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** The cabinet did not find the score a request asks for (docs/DECISIONS.md D93). */
final class ReportScoreRecoveryRequest extends FormRequest
{
    /** Why: not in the table, the player is not on the cabinet, no extractor, no file. */
    public const REASONS = ['not_in_table', 'player_not_on_cabinet', 'unsupported', 'no_file', 'unreadable'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'in:not_found'],
            'reason' => ['required', 'in:'.implode(',', self::REASONS)],
            // The table the cabinet read, for the administrator.
            'table' => ['present', 'list', 'max:100'],
            'table.*.rank' => ['required', 'integer', 'min:1', 'max:999'],
            'table.*.score' => ['required'],
            'table.*.name' => ['present', 'nullable', 'string', 'max:32'],
        ];
    }
}
