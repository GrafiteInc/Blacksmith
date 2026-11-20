# Change Log - Grafite Blacksmith
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).
----

## [v2.1.5] - 2026-11-20

### Fix
- match installed PHP versions the way Forge lists them

## [v2.1.4] - 2026-11-20

### Fix
- have Forge create DigitalOcean servers itself

## [v2.1.3] - 2026-11-20

### Fix
- send the SSH port Forge requires for custom servers

## [v2.1.2] - 2026-11-20

### Fix
- drop the incorrect specs
- report the fields Forge rejected instead of an uncaught validation error

## [v2.1.1] - 2026-11-20

### Docs
- document zero-downtime, rolling, and replace cutover

### Feat(replace)
- wait for deploys and certificates and drain DNS

### Feat(update-server)
- add rolling deployments for load balanced sites

### Feat(blueprints)
- support Forge zero-downtime deployments

### Feat(deploy)
- wait for deployments and add deployment settings

## [v2.1.0] - 2026-11-20

### Docs(dns)
- document Cloudflare DNS setup

### Test(dns)
- cover DNS provider, actions, and command integration

### Feat(dns)
- add DNS actions and command integrations
- add Cloudflare provider, config, and state zone support

### Build(deps)
- add Guzzle for Cloudflare DNS provider

### Test
- cover Forge v2 gateway, defaults and workers

### Docs
- document Forge v2 tokens and defaults

### Feat(forge)!
- add ForgeGateway and migrate actions to API v2

### Feat(config)
- add configurable defaults and Forge organization

### Build(deps)!
- bump forge-sdk and minimum Laravel version

### Ci(php)
- bump test matrix to PHP 8.4

## [v2.0.0] - 2026-11-19

### Docs(replace)
- document replace command workflow

### Test(replace)
- add replace tests and shared server mock helper

### Feat(replace)
- add blacksmith
- add state helpers and env warnings for replacements

### Refactor(scale)
- extract server deletion into DestroyInstance

### Feature
- overhaul for new version

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
