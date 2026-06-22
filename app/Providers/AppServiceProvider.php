<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

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
        if ($this->app->environment('local') && class_exists(\Illuminate\Foundation\Console\ServeCommand::class)) {
            \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables = array_merge(
                \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables,
                ['SystemRoot', 'SystemDrive', 'Path', 'TEMP', 'TMP', 'windir', 'COMSPEC', 'ComSpec']
            );
        }

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Database\Events\ConnectionEstablished::class,
            function ($event) {
                $connection = $event->connection;
                if ($connection->getDriverName() === 'sqlite') {
                    $connection->getPdo()->sqliteCreateFunction('NOW', function () {
                        return date('Y-m-d H:i:s');
                    });
                }
            }
        );
    }
}
