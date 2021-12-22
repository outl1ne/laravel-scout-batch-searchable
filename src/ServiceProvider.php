<?php

namespace OptimistDigital\ScoutBatchSearchable;

use Illuminate\Support\Facades\Cache;
use Illuminate\Console\Scheduling\Schedule;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
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
                    $batchedModelsClasses = static::getBatchedModelsClasses();
                    foreach ($batchedModelsClasses as $batchClass) {
                        $instantiatedBatchObject = new $batchClass;
                        if (method_exists($instantiatedBatchObject, 'checkBatchingStatusAndDispatchIfNecessary')) {
                            $instantiatedBatchObject->checkBatchingStatusAndDispatchIfNecessary($batchClass);
                        }
                    }
                })->description('Scout Batch Searchable')->everyMinute();
            });
        }
    }

    public static function getBatchedModelsClasses()
    {
        return Cache::get('BATCH_SEARCHABLE_QUEUED_MODELS') ?? [];
    }

    public static function addBatchedModelClass($className)
    {
        $queuedModels = static::getBatchedModelsClasses();
        $queuedModels[] = $className;
        $queuedModels = array_unique($queuedModels);
        Cache::put('BATCH_SEARCHABLE_QUEUED_MODELS', $queuedModels);
        return $queuedModels;
    }

    public static function removeBatchedModelClass($className)
    {
        $queuedModels = static::getBatchedModelsClasses();
        $queuedModels = array_filter($queuedModels, function ($cls) use ($className) {
            return $cls !== $className;
        });
        Cache::put('BATCH_SEARCHABLE_QUEUED_MODELS', $queuedModels);
        return $queuedModels;
    }
}
