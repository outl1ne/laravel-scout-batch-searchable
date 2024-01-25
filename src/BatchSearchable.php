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
        ServiceProvider::addBatchedModelClass($className);

        // Add IDs to the requested queue
        $cacheKey = $this->getCacheKey($className, $makeSearchable);
        $existingCacheValue = Cache::get($cacheKey) ?? ['updated_at' => Carbon::now(), 'models' => []];

        // Upgrade backwards compatibility - the existing cache IDs might still be primary keys not whole models
        $existingCacheValue['models'] = collect($existingCacheValue['models'])->map(function ($keyOrModel) use ($className) {
            if (is_object($keyOrModel)) return $keyOrModel;

            return method_exists($className, 'trashed')
                ? $className::withTrashed()->with([])->find($keyOrModel)
                : $className::with([])->find($keyOrModel);
        });

        $newModels = $models
            ->map(fn ($model) => $model->unsetRelations()->setAppends([])) // Unset relations to reduce the size of the cache
            ->merge($existingCacheValue['models'] ?? [])
            ->reverse() // Reverse before ->unique() to keep the latest version
            ->unique($models->first()->getKeyName())
            ->values(); // Fix keys and keep the whole thing as a sequential array;

        $newCacheValue = ['updated_at' => Carbon::now(), 'models' => $newModels];

        // Remove IDs from the opposite queue
        $opCacheKey = $this->getCacheKey($className, !$makeSearchable);
        $opExistingCacheValue = Cache::get($opCacheKey) ?? ['updated_at' => Carbon::now(), 'models' => []];

        $newOppositeModels = collect($opExistingCacheValue['models'])
            ->filter(function ($keyOrModel) use ($newModels) {
                // Upgrade backwards compatibility, model can either be a primary key or an actual model
                $key = is_object($keyOrModel) ? $keyOrModel->getKey() : $keyOrModel;

                return $newModels->where($newModels->first()->getKeyName(), $key)->isEmpty();
            })
            ->values();

        $newOpCacheValue = ['updated_at' => Carbon::now(), 'models' => $newOppositeModels];

        // Store
        if ($newCacheValue['models']->isEmpty()) {
            Cache::forget($cacheKey);
        } else {
            Cache::put($cacheKey, $newCacheValue);
        }

        if ($newOpCacheValue['models']->isEmpty()) {
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
        $cachedValue = Cache::get($cacheKey) ?? ['updated_at' => Carbon::now(), 'models' => collect()];

        // Wrap in collect() for backwards compatibility
        $cachedValue['models'] = collect($cachedValue['models']);

        if ($cachedValue['models']->isEmpty()) return;

        $maxBatchSize = Config::get('scout.batch_searchable_max_batch_size', 250);
        $maxBatchSizeExceeded = $cachedValue['models']->count() >= $maxBatchSize;

        $maxTimeInMin = Config::get('scout.batch_searchable_debounce_time_in_min', 1);
        $maxTimePassed = Carbon::now()->diffInMinutes($cachedValue['updated_at']) >= $maxTimeInMin;

        if ($maxBatchSizeExceeded || $maxTimePassed) {
            ServiceProvider::removeBatchedModelClass($className);
            Cache::forget($cacheKey);
            $models = $cachedValue['models']->filter(fn ($model) => is_object($model))->values();

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
