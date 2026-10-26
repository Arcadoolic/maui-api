<?php

namespace App\Http\Requests\Api;

use App\Models\Diagnostic;
use App\Services\Diagnostics\DiagnosticIntake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A diagnostic from a cabinet's back office (docs/DECISIONS.md D92). */
final class StoreDiagnosticRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'romname' => ['required', 'string', 'regex:/^[a-z0-9_]{1,32}$/'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'report' => ['required', 'json', 'max:'.DiagnosticIntake::MAX_REPORT_SIZE],
            // Absent when the game has no file at all: its report says so.
            'files' => ['sometimes', 'array', 'max:'.DiagnosticIntake::MAX_FILES],
            'files.*.area' => ['required', 'string', Rule::in(Diagnostic::AREAS)],
            // A plain file name: no path, nothing hidden.
            'files.*.name' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            'files.*.modified' => ['nullable', 'date'],
            'files.*.content' => ['required', 'file', 'max:'.intdiv(DiagnosticIntake::MAX_SIZE, 1024)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $seen = [];
            $size = 0;
            foreach ($this->diagnosticFiles() as $index => $file) {
                // The .hi of the game itself, nothing else from the hiscore directory.
                if ($file['area'] === 'hiscore' && $file['name'] !== $this->string('romname').'.hi') {
                    $validator->errors()->add("files.{$index}.name", 'Only the .hi of the game.');
                }
                $key = $file['area'].'/'.$file['name'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("files.{$index}.name", 'This file is sent twice.');
                }
                $seen[$key] = true;
                $size += (int) $file['content']->getSize();
            }
            if ($size > DiagnosticIntake::MAX_SIZE) {
                $validator->errors()->add('files', 'The files are over 1 MB in all.');
            }
        }];
    }

    /**
     * @return list<array{area: string, name: string, modified: string|null, content: UploadedFile}>
     */
    public function diagnosticFiles(): array
    {
        /** @var array<int|string, array{area: string, name: string, modified?: string|null}> $files */
        $files = $this->input('files', []);
        $result = [];
        foreach ($files as $key => $file) {
            $content = $this->file("files.{$key}.content");
            assert($content instanceof UploadedFile);
            $result[] = [
                'area' => $file['area'],
                'name' => $file['name'],
                'modified' => $file['modified'] ?? null,
                'content' => $content,
            ];
        }

        return $result;
    }
}
