# Laravel Scout Batch Searchable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/outl1ne/laravel-scout-batch-searchable.svg?style=flat-square)](https://packagist.org/packages/outl1ne/laravel-scout-batch-searchable)
[![Total Downloads](https://img.shields.io/packagist/dt/outl1ne/laravel-scout-batch-searchable.svg?style=flat-square)](https://packagist.org/packages/outl1ne/laravel-scout-batch-searchable)

This [Laravel](https://laravel.com) package allows for batching of Scout updates.

## Requirements

- Laravel Scout 9+
- Scheduler with cron

## Description

This package provides a new trait `BatchSearchable` that should be used instead of the regular `Searchable` trait provided by Laravel Scout.

Using that trait, all updates pushed through Scout to the search server (whether it be MeiliSearch, Algolia or whatever else), are batched together instead of being sent one-by-one.

The updates are sent on two possible conditions:

> Either `scout.batch_searchable_max_batch_size` (default 250) is exeeded

or

> `scout.batch_searchable_debounce_time_in_min` (default 1) minutes have passed from the last update to the pending queue

The IDs of models that require updating are stored in the default cache layer using the `Cache` helper.

The debounce check uses Laravel's Scheduler to schedule a job that checks through all the pending update queues and sees if the required time has passed. This requires that the system has a working cron setup that calls `schedule:run` every minute.

## Installation

Install the package in a Laravel Nova project via Composer and run migrations:

```bash
composer require outl1ne/laravel-scout-batch-searchable
```

## Usage

Where you previously used the Searchable trait, just use BatchSearchable instead:

```php
use Outl1ne\ScoutBatchSearchable\BatchSearchable;

class SomeModel extends Model {
    use BatchSearchable;
}
```

## Credits

- [Tarvo Reinpalu](https://github.com/tarpsvo)

## License

Laravel Scout Batch Searchable is open-sourced software licensed under the [MIT license](LICENSE.md).
