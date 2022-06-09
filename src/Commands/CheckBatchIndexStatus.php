<?php

namespace Outl1ne\ScoutBatchSearchable\Commands;

use Illuminate\Console\Command;
use Outl1ne\ScoutBatchSearchable\ServiceProvider;

class CheckBatchIndexStatus extends Command
{
    protected $signature = 'scout-batch-searchable:check';

    public function handle(): void
    {
        $batchedModelsClasses = ServiceProvider::getBatchedModelsClasses();

        foreach ($batchedModelsClasses as $batchClass) {
            $instantiatedBatchObject = new $batchClass();

            if (method_exists($instantiatedBatchObject, 'checkBatchingStatusAndDispatchIfNecessary')) {
                $instantiatedBatchObject->checkBatchingStatusAndDispatchIfNecessary($batchClass);
            } else {
                // Seems like the stored model has lost its BatchSearchable trait
                ServiceProvider::removeBatchedModelClass($batchClass);
            }
        }
    }
}
