<?php

namespace Outl1ne\ScoutBatchSearchable;

use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Collection as BaseCollection;

trait BatchSearchable
{
    use Searchable {
        queueMakeSearchable as public parentQueueMakeSearchable;
        queueRemoveFromSearch as public parentQueueRemoveFromSearch;
    }

    /**
     * Register the searchable macros.
     *
     * @return void
     */
    public function registerSearchableMacros()
    {
        $self = $this;

        BaseCollection::macro('searchable', function () use ($self) {
            $self->queueMakeSearchable($this);
        });

        BaseCollection::macro('searchableImmediately', function () use ($self) {
            $self->parentQueueMakeSearchable($this);
        });

        BaseCollection::macro('unsearchable', function () use ($self) {
            $self->queueRemoveFromSearch($this);
        });

        BaseCollection::macro('unsearchableImmediately', function () use ($self) {
            $self->parentQueueRemoveFromSearch($this);
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
        $modelIds = $models->pluck($models->first()->getScoutKeyName())->toArray();
        ServiceProvider::addBatchedModelClass($className);

        // Add IDs to the requested queue
        $cacheKey = $this->getCacheKey($className, $makeSearchable);
        $existingCacheValue = Cache::get($cacheKey) ?? ['updated_at' => Carbon::now(), 'models' => []];

        $newModelIds = array_unique(array_merge($existingCacheValue['models'], $modelIds));
        $newCacheValue = ['updated_at' => Carbon::now(), 'models' => $newModelIds];

        // Remove IDs from the opposite queue
        $opCacheKey = $this->getCacheKey($className, !$makeSearchable);
        $opExistingCacheValue = Cache::get($opCacheKey) ?? ['updated_at' => Carbon::now(), 'models' => []];

        $newOpModelIds = array_filter($opExistingCacheValue['models'], function ($id) use ($modelIds) {
            return !in_array($id, $modelIds);
        });
        $newOpCacheValue = ['updated_at' => Carbon::now(), 'models' => array_values($newOpModelIds)];

        // Store
        if (empty($newCacheValue['models'])) {
            Cache::forget($cacheKey);
        } else {
            Cache::put($cacheKey, $newCacheValue);
        }

        if (empty($newOpCacheValue['models'])) {
            Cache::forget($opCacheKey);
        } else {
            Cache::put($opCacheKey, $newOpCacheValue);
        }


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
        $cachedValue = Cache::get($cacheKey) ?? ['updated_at' => Carbon::now(), 'models' => []];
        if (empty($cachedValue['models'])) return;

        $maxBatchSize = Config::get('scout.batch_searchable_max_batch_size', 250);
        $maxBatchSizeExceeded = sizeof($cachedValue['models']) >= $maxBatchSize;

        $maxTimeInMin = Config::get('scout.batch_searchable_debounce_time_in_min', 1);
        $maxTimePassed = Carbon::now()->diffInMinutes($cachedValue['updated_at']) >= $maxTimeInMin;

        if ($maxBatchSizeExceeded || $maxTimePassed) {
            ServiceProvider::removeBatchedModelClass($className);
            Cache::forget($cacheKey);

            $fakeModel = new $className;
            $keyName = $fakeModel->getScoutKeyName();

            $models = collect();

            if ($makeSearchable) {
                $models = method_exists($this, 'trashed')
                    ? $className::withTrashed()->whereIn($keyName, $cachedValue['models'])->get()
                    : $className::whereIn($keyName, $cachedValue['models'])->get();
            } else {
                $models = collect($cachedValue['models'])->map(function ($id) use ($className) {
                    $model = new $className;
                    $model->{$model->getScoutKeyName()} = $id;
                    return $model;
                });
            }

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
        $cacheKey = Config::get('scout.batch_searchable_cache_key', 'SCOUT_BATCH_SEARCHABLE_QUEUE');
        $className = Str::upper(Str::snake(Str::replace('\\', '', $className)));
        return "{$cacheKey}_{$className}_{$suffix}";
    }
}
