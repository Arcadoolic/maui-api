<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Diagnostic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<Diagnostic>
 */
class DiagnosticFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'romname' => 'mslug',
            'comment' => null,
            'report' => ['versions' => ['maui' => '2.18.0', 'mhiex' => '2.2.6', 'mame' => '0.289']],
            'files' => [],
            'size' => 0,
            'has_logs' => false,
            'received_at' => now(),
        ];
    }

    /** With its saveram stored on the disk. */
    public function withSaveram(string $content = "\x00\x01"): static
    {
        return $this->state(fn (): array => [
            'files' => [['area' => 'nvram', 'name' => 'saveram', 'size' => strlen($content), 'modified' => null, 'sha256' => hash('sha256', $content)]],
            'size' => strlen($content),
        ])->afterCreating(fn (Diagnostic $diagnostic) => Storage::disk(Diagnostic::DISK)->put($diagnostic->path('nvram', 'saveram'), $content));
    }
}
