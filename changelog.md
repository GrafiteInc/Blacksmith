# Change Log - Grafite Blacksmith
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).
----

## [v2.0.0] - Unreleased

### Breaking
- Configuration split into portable blueprints (`servers/`, `sites/`) and `state.json`, the v1 layout is migrated by `blacksmith:localize`
- Commands reduced to: localize, make-server, update-server, workers, backup, instance, balancer, scale
- Removed: setup, build-server, build-site, clone-server, delete-server, update-site, deploy, site-up, site-down, add-worker, add-security, add-redirects, workers-up/down/reset/restart/list
- Requires PHP 8.2

### Feature
- `blacksmith:instance` creates a server with copies of your sites and deploys them
- `blacksmith:balancer` and `blacksmith:scale` load balance a site across web-only nodes
- `make-server` provisions custom servers end to end over SSH, with no manual step
- `update-server` creates missing sites, only pushes what changed, and supports `--dry-run`, `--only` and `--deploy`
- Per-instance env overrides with `.env.{instance}`

### Fix
- SSL used the wrong domains key, redirects were gated on security rules
- Workers were duplicated by workers-up
- Load balancing method sent as `round-robin` instead of `round_robin`
- Scheduler job used `php84` instead of the `php8.4` binary

## [v1.5.1] - 2026-04-22

### Chore
- version bump

## [v1.5.0] - 2026-01-29

### Fix
- code styles

### Feature
- added load balancing configs
- new restart workers command
- new restart workers command

### Refactor
- minor improvements

## [v1.4.2] - 2025-05-05

### Chore
-  dependency update

## [v1.4.1] - 2025-01-29

### Fixed
- Minor issue in cloning

## [v1.4.0] - 2025-01-29

### Added
- Ablity to quickly clone a server

## [v1.3.0] - 2025-01-16

### Added
- Worker managers
- Maintenance managers
- Deploy trigger

## [v1.2.1] - 2024-04-25

### Fixed
- Minor formatting issues

## [v1.2.0] - 2024-04-25

### Added
- Quick config references

## [v1.1.0] - 2024-03-12

### Added
- Support for Laravel 11

## [v1.0.1] - 2024-01-08

### Fixed
- Missing command

## [v1.0.0] - 2023-12-13

### Changed
- Overhaul for v1

## [v0.1.1] - 2023-12-13

### Fixed
- Minor issue with PHP versions

## [v0.1.0] - 2023-12-13

### Changed
- created
