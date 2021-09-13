<?php

namespace OptimistDigital\ScoutBatchSearchable;

use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Cache;

trait BatchSearchable
{
    use Searchable {
        queueMakeSearchable as public parentQueueMakeSearchable;
        queueRemoveFromSearch as public parentQueueRemoveFromSearch;
    }

    public static $batchModels = [];

    /**
     * Register the searchable macros.
     *
     * @return void
     */
    public function registerSearchableMacros()
    {
        // Let ServiceProvider know which models use the BatchSearchable trait
        // so the scheduled command knows which models to check for syncing via
        // the debounce timer
        ServiceProvider::$batchSearchableModels[] = static::class;

        $self = $this;

        BaseCollection::macro('searchable', function () use ($self) {
            $self->queueMakeSearchable($this);
        });

        BaseCollection::macro('unsearchable', function () use ($self) {
            $self->queueRemoveFromSearch($this);
        });
    }

    /**
     * Dispatch the job to make the given models searchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueMakeSearchable($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        // Save the model ID's to cache
        $this->addToBatchingQueue($models, true);
    }

    /**
     * Dispatch the job to make the given models unsearchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueRemoveFromSearch($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        // Save the model ID's to cache
        $this->addToBatchingQueue($models, false);
    }

    private function addToBatchingQueue($models, $makeSearchable = true)
    {
        if ($models->isEmpty()) return;
        $className = get_class($models->first());

        $cacheKey = $this->getCacheKey($className, $makeSearchable);
        $existingCacheValue = Cache::get($cacheKey) ?? ['updated_at' => now(), 'models' => []];

        $modelIds = $models->pluck($models->first()->getKeyName())->toArray();
        $newModelIds = array_unique(array_merge($existingCacheValue['models'], $modelIds));
        $newCacheValue = ['updated_at' => now(), 'models' => $newModelIds];

        Cache::put($cacheKey, $newCacheValue);

        $this->checkBatchingStatusAndDispatchIfNecessaryFor($className, $makeSearchable);
    }

    public function checkBatchingStatusAndDispatchIfNecessary($className)
    {
        $this->checkBatchingStatusAndDispatchIfNecessaryFor($className, true);
        $this->checkBatchingStatusAndDispatchIfNecessaryFor($className, false);
    }

    private function checkBatchingStatusAndDispatchIfNecessaryFor($className, $makeSearchable = true)
    {
        $cacheKey = $this->getCacheKey($className, $makeSearchable);
        $cachedValue = Cache::get($cacheKey) ?? ['updated_at' => now(), 'models' => []];

        $maxBatchSize = config('scout.batch_searchable_max_batch_size', 250);
        $maxBatchSizeExceeded = sizeof($cachedValue['models']) >= $maxBatchSize;

        $maxTimeInMin = config('batch_searchable_debounce_time_in_min', 1);
        $maxTimePassed = now()->diffInMinutes($cachedValue['updated_at']) >= $maxTimeInMin;

        if ($maxBatchSizeExceeded || $maxTimePassed) {
            Cache::forget($cacheKey);
            $models = method_exists($this, 'trashed')
                ? $className::withTrashed()->findMany($cachedValue['models'])
                : $className::findMany($cachedValue['models']);

            return $makeSearchable
                ? $this->parentQueueMakeSearchable($models)
                : $this->parentQueueRemoveFromSearch($models);
        }
    }

    private function getCacheKey($className, $makeSearchable)
    {
        return $makeSearchable
            ? $this->getGenericCacheKey($className, 'MAKE_SEARCHABLE')
            : $this->getGenericCacheKey($className, 'REMOVE_FROM_SEARCH');
    }

    private function getGenericCacheKey($className, $suffix)
    {
        $cacheKey = config('scout.batch_searchable_cache_key', 'SCOUT_BATCH_SEARCHABLE_QUEUE');
        $className = Str::upper(Str::snake(Str::replace('\\', '', $className)));
        return "{$cacheKey}_{$className}_{$suffix}";
    }
}
