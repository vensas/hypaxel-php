# AGENTS.md — Hypaxel PHP

Composer package `vensas/hypaxel`: `src/`, `tests/`, `benchmarks/`, `examples/`. Shared rules are in the root `AGENTS.md`.

- An independent native engine with no runtime dependencies. PHP writes only; it has no reader yet.
- Versioned by tag; `php-split.yml` feeds Packagist.
- CI enforces at least 80% line coverage (`tests/check-coverage.php`).

## Build, Test and Lint

Run from `php/`. PHP 8.2+.

```bash
composer install
composer check         # php-cs-fixer, phpstan, phpunit
```
