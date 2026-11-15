<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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
        Schema::defaultStringLength(191);

        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email', '')));
            $emailKey = $email !== ''
                ? 'email:'.hash('sha256', $email)
                : 'invalid-email:'.$request->ip();

            return [
                Limit::perMinute(5)->by($emailKey),
                Limit::perMinute(120)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-checkout', function (Request $request) {
            $phone = preg_replace('/\D+/', '', (string) $request->input('customer_phone', ''));
            $phoneKey = $phone !== ''
                ? 'phone:'.hash('sha256', $phone)
                : 'invalid-phone:'.$request->ip();

            return [
                Limit::perMinute(10)->by($phoneKey),
                Limit::perMinute(600)->by('ip:'.$request->ip()),
            ];
        });

        if ($this->app->environment('local') && class_exists(ServeCommand::class)) {
            ServeCommand::$passthroughVariables = array_merge(
                ServeCommand::$passthroughVariables,
                ['SystemRoot', 'SystemDrive', 'Path', 'TEMP', 'TMP', 'windir', 'COMSPEC', 'ComSpec']
            );
        }
    }
}
