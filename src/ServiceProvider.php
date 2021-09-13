<?php

namespace OptimistDigital\ScoutBatchSearchable;

use Illuminate\Console\Scheduling\Schedule;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public static $batchSearchableModels = [];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scout.php', 'scout');
    }

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                /** @var Schedule */
                $schedule = $this->app->make(Schedule::class);
                $schedule->call(function () {
                    foreach (static::$batchSearchableModels as $batchClass) {
                        (new $batchClass)->checkBatchingStatusAndDispatchIfNecessary($batchClass);
                    }
                })->description('Scout Batch Searchable')->everyMinute();
            });
        }
    }
}
