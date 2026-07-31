<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }

    /**
     * Configure rate limits for public application endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('provider-rpc', fn (Request $request): Limit => Limit::perMinute(
            $this->configuredRateLimit(),
        )->by((string) $request->ip()));
    }

    private function configuredRateLimit(): int
    {
        $rateLimit = config('shipit_return_rpc.rate_limit_per_minute', 300);

        return \is_int($rateLimit) && $rateLimit > 0 ? $rateLimit : 300;
    }
}
