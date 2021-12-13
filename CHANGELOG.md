# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2021-12-13

### Added

- Added `searchableImmediately` and `unsearchableImmediately` methods (thanks to [@keithbrink](https://github.com/keithbrink))

### Changed

- Improved how batched models are registered for the debounce job

## [1.0.1] - 2021-10-08

### Changed

- Fixed a scenario where a model could end up in both searchable and unsearchable queues

## [1.0.0] - 2021-09-13

Initial release.
