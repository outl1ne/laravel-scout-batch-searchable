<?php

namespace OptimistDigital\ScoutBatchSearchable;

use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Illuminate\Support\Cache;

trait BatchSearchable
{
    use Searchable {
        queueMakeSearchable as public parentQueueMakeSearchable;
        queueRemoveFromSearch as public parentQueueRemoveFromSearch;
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

    public function originalQueueMakeSearchable($models)
    {
        return $this->parentQueueMakeSearchable($models);
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

    public function originalQueueRemoveFromSearch($models)
    {
        return $this->parentQueueRemoveFromSearch($models);
    }

    private function addToBatchingQueue($models, $makeSearchable = true)
    {
        if ($models->isEmpty()) return;

        $cacheKey = $makeSearchable ? $this->getMakeSearchableCacheKey() : $this->getRemoveFromSearchCacheKey();
        $existingCacheValue = Cache::get($cacheKey) ?? ['updated_at' => now(), 'models' => []];
        $modelIds = $models->pluck($models->first()->getKeyName());
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

        $batchSize = config('scout.batch_searchable_min_batch_size');
        ray(['batchSize' => $batchSize, 'cacheKey' => $cacheKey, 'value' => $cachedValue]);
        if (sizeof($cachedValue['models']) > $batchSize) {
            $models = static::findMany($cachedValue['models']);
            return $makeSearchable
                ? $this->originalQueueMakeSearchable($models)
                : $this->originalQueueRemoveFromSearch($mdoels);
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
        $className = Str::snake(static::class);
        return "{$cacheKey}_{$className}_{$suffix}";
    }
}
