<?php

namespace App\Services\Diagnostics;

use App\Models\Diagnostic;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * A diagnostic as a zip for an administrator (docs/DECISIONS.md D92), laid out
 * as a MAME home so that mhiex reads it as it is: hiscore/<rom>.hi,
 * nvram/<rom>/<file>, plus logs/, sessions/ and diagnostic.json (the cabinet,
 * the comment, the report, the list of files).
 */
final class DiagnosticArchive
{
    public const LOG_NAME = 'diagnostics';

    /** Builds the zip in a temporary file and returns its path: the caller deletes it. */
    public function build(Diagnostic $diagnostic): string
    {
        $path = tempnam(sys_get_temp_dir(), 'diagnostic-');
        if ($path === false) {
            throw new RuntimeException('No temporary file for the diagnostic archive.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The diagnostic archive could not be created.');
        }
        foreach ($diagnostic->files as $file) {
            $content = Storage::disk(Diagnostic::DISK)->get($diagnostic->path($file['area'], $file['name']));
            if ($content !== null) {
                $zip->addFromString($this->entryName($diagnostic, $file['area'], $file['name']), $content);
            }
        }
        $zip->addFromString('diagnostic.json', (string) json_encode([
            'id' => $diagnostic->uuid,
            'cabinet' => $diagnostic->client->name,
            'romname' => $diagnostic->romname,
            'comment' => $diagnostic->comment,
            'received_at' => $diagnostic->received_at->toIso8601ZuluString(),
            'report' => $diagnostic->report,
            'files' => $diagnostic->files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return $path;
    }

    public function fileName(Diagnostic $diagnostic): string
    {
        return "diagnostic-{$diagnostic->romname}-{$diagnostic->received_at->format('Ymd-His')}.zip";
    }

    private function entryName(Diagnostic $diagnostic, string $area, string $name): string
    {
        return $area === 'nvram' ? "nvram/{$diagnostic->romname}/{$name}" : "{$area}/{$name}";
    }
}
