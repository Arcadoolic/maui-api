<?php

namespace App\Console\Commands;

use App\Models\Diagnostic;
use Illuminate\Console\Command;

/**
 * Deletes the diagnostics older than 90 days, their files with them
 * (docs/DECISIONS.md D92). Run every night by the scheduler.
 */
final class PurgeDiagnosticsCommand extends Command
{
    public const KEEP_DAYS = 90;

    protected $signature = 'diagnostics:purge';

    protected $description = 'Delete the diagnostics older than 90 days, with their files';

    public function handle(): int
    {
        $deleted = 0;
        // One by one: each deletion removes the diagnostic's files (Diagnostic::booted()).
        Diagnostic::query()
            ->where('received_at', '<', now()->subDays(self::KEEP_DAYS))
            ->lazyById()
            ->each(function (Diagnostic $diagnostic) use (&$deleted): void {
                $diagnostic->delete();
                $deleted++;
            });

        $this->info("{$deleted} diagnostic(s) older than ".self::KEEP_DAYS.' days deleted.');

        return self::SUCCESS;
    }
}
