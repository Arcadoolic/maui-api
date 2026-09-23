<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateCabinet;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }
}
