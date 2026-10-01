<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateCabinet;
use App\Models\User;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Dates are stored in UTC and shown in the timezone of the logged-in
        // admin. The closure is evaluated on every read, Livewire requests included.
        FilamentTimezone::set(function (): string {
            $user = Auth::user();

            return $user instanceof User ? $user->timezone : (string) config('maui.admin_default_timezone');
        });
    }

    private function configureRateLimiting(): void
    {
        // Per client key, plus a wider per-IP cap so that rotating fake keys
        // does not bypass the limit (docs/PLAN.md 1.2).
        RateLimiter::for('cabinet', function (Request $request): array {
            $key = $request->header(AuthenticateCabinet::KEY_HEADER);

            return [
                Limit::perMinute(60)->by(is_string($key) ? 'key:'.$key : 'ip:'.$request->ip()),
                Limit::perMinute(600)->by('ip-wide:'.$request->ip()),
            ];
        });

        // forward_auth of the repository: one call per file request, Range
        // requests of a pack import included (docs/DECISIONS.md D46). The
        // per-IP cap sees the repository server, not the cabinets.
        RateLimiter::for('repository', function (Request $request): array {
            $key = $request->header(AuthenticateCabinet::KEY_HEADER);

            return [
                Limit::perMinute(1200)->by(is_string($key) ? 'key:'.$key : 'ip:'.$request->ip()),
                Limit::perMinute(2400)->by('ip-wide:'.$request->ip()),
            ];
        });

        // Public invitation pages: the token space is too large to guess, this
        // only slows down scanning (docs/PLAN.md 1.2). High enough for an owner
        // drawing several cabinet names (two requests per draw).
        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
    }
}
