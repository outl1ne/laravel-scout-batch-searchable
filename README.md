# Laravel Scout Batch Searchable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/optimistdigital/laravel-scout-batch-searchable.svg?style=flat-square)](https://packagist.org/packages/optimistdigital/laravel-scout-batch-searchable)
[![Total Downloads](https://img.shields.io/packagist/dt/optimistdigital/laravel-scout-batch-searchable.svg?style=flat-square)](https://packagist.org/packages/optimistdigital/laravel-scout-batch-searchable)

This [Laravel](https://laravel.com) package allows for batching of Scout updates.

## Requirements

- Laravel Scout 9+
- Scheduler/cron

## Features

- Batches Laravel Scout updates

## Installation

Install the package in a Laravel Nova project via Composer and run migrations:

```bash
composer require optimistdigital/laravel-scout-batch-searchable
```

## Usage

Where you previously used the Searchable trait, just use BatchSearchable instead:

```php
use OptimistDigital\ScoutBatchSearchable\BatchSearchable;

class SomeModel extends Model {
    use BatchSearchable;
}
```

## Credits

- [Tarvo Reinpalu](https://github.com/tarpsvo)

## License

Laravel Scout Batch Searchable is open-sourced software licensed under the [MIT license](LICENSE.md).
