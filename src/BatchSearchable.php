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

        $this->addToBatchingQueue($models, false);
    }

    private function addToBatchingQueue($models, $makeSearchable = true)
    {
        if ($models->isEmpty()) return;

        $cacheKey = $makeSearchable ? $this->getMakeSearchableCacheKey() : $this->getRemoveFromSearchCacheKey();
        $existingCacheValue = Cache::get($cacheKey) ?? ['updated_at' => now(), 'models' => []];
        $modelIds = $models->pluck($models->first()->getKeyName())->toArray();
        $newModelIds = array_unique(array_merge($existingCacheValue['models'], $modelIds));
        $newCacheValue = ['updated_at' => now(), 'models' => $newModelIds];
        Cache::put($cacheKey, $newCacheValue);

        $this->checkBatchingStatusAndDispatchIfNecessaryFor($makeSearchable);
    }

    public function checkBatchingStatusAndDispatchIfNecessary()
    {
        $this->checkBatchingStatusAndDispatchIfNecessaryFor(true);
        $this->checkBatchingStatusAndDispatchIfNecessaryFor(false);
    }

    private function checkBatchingStatusAndDispatchIfNecessaryFor($makeSearchable = true)
    {
        $cacheKey = $makeSearchable ? $this->getMakeSearchableCacheKey() : $this->getRemoveFromSearchCacheKey();
        $cachedValue = Cache::get($cacheKey) ?? ['updated_at' => now(), 'models' => []];

        $maxBatchSize = config('scout.batch_searchable_max_batch_size', 250);
        $maxBatchSizeExceeded = sizeof($cachedValue['models']) >= $maxBatchSize;

        $maxTimeInMin = config('batch_searchable_debounce_time_in_min', 1);
        $maxTimePassed = now()->diffInMinutes($cachedValue['updated_at']) >= $maxTimeInMin;

        if ($maxBatchSizeExceeded || $maxTimePassed) {
            ray(['action' => 'Dispatching.', 'maxBatchSizeExceeded' => $maxBatchSizeExceeded, 'maxTimePassed' => $maxTimePassed]);
            Cache::forget($cacheKey);
            $models = (new static)->newQueryWithoutScopes()->findMany($cachedValue['models']);
            return $makeSearchable
                ? $this->parentQueueMakeSearchable($models)
                : $this->parentQueueRemoveFromSearch($models);
        }
    }

    private function getMakeSearchableCacheKey()
    {
        return $this->getGenericCacheKey('MAKE_SEARCHABLE');
    }

    private function getRemoveFromSearchCacheKey()
    {
        return $this->getGenericCacheKey('REMOVE_FROM_SEARCH');
    }

    private function getGenericCacheKey($suffix)
    {
        $cacheKey = config('scout.batch_searchable_cache_key', 'SCOUT_BATCH_SEARCHABLE_QUEUE');
        $className = Str::upper(Str::snake(Str::replace('\\', '', static::class)));
        return "{$cacheKey}_{$className}_{$suffix}";
    }
}
