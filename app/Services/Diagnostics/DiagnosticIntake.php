<?php

namespace App\Services\Diagnostics;

use App\Models\Client;
use App\Models\Diagnostic;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores what a cabinet sends from its back office about a game (docs/DECISIONS.md D92):
 * the row, and the files on the `local` disk under diagnostics/<uuid>/<area>/<name>.
 */
final class DiagnosticIntake
{
    /** Bytes, all files together. */
    public const MAX_SIZE = 1024 * 1024;

    public const MAX_FILES = 16;

    /** Characters of the report's JSON. */
    public const MAX_REPORT_SIZE = 65536;

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array{area: string, name: string, modified: string|null, content: UploadedFile}>  $files
     */
    public function take(Client $client, string $romname, ?string $comment, array $report, array $files): Diagnostic
    {
        $diagnostic = new Diagnostic;
        $diagnostic->romname = $romname;
        $diagnostic->comment = $comment === '' ? null : $comment;
        $diagnostic->report = $report;
        $diagnostic->uuid = (string) Str::uuid();
        $diagnostic->client()->associate($client);
        $diagnostic->received_at = Carbon::now();

        $stored = [];
        $size = 0;
        try {
            foreach ($files as $file) {
                $content = (string) $file['content']->get();
                Storage::disk(Diagnostic::DISK)->put($diagnostic->path($file['area'], $file['name']), $content);
                $stored[] = [
                    'area' => $file['area'],
                    'name' => $file['name'],
                    'size' => strlen($content),
                    'modified' => $file['modified'] === null ? null : Carbon::parse($file['modified'])->toIso8601ZuluString(),
                    'sha256' => hash('sha256', $content),
                ];
                $size += strlen($content);
            }
            $diagnostic->files = $stored;
            $diagnostic->size = $size;
            $diagnostic->has_logs = collect($stored)->contains('area', 'logs');
            DB::transaction(fn () => $diagnostic->save());
        } catch (Throwable $e) {
            Storage::disk(Diagnostic::DISK)->deleteDirectory($diagnostic->directory());
            throw $e;
        }

        return $diagnostic;
    }
}
