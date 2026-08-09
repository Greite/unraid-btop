# Contributing

Thanks for your interest in improving this plugin!

**Scope first:** this repository is the **Unraid integration** of btop -
packaging, dashboard tile, endpoints and release automation. btop itself is
developed at [aristocratos/btop](https://github.com/aristocratos/btop) by its
own authors. Issues about btop's behavior, UI, metrics or performance belong
upstream; issues about the Unraid plugin (installation, dashboard tile,
packaging, updates) belong here.

## Development setup

Requirements: bash, PHP 8+, `xmllint`, tar (GNU or BSD - both handled), curl.
Works on Linux and macOS.

```bash
bash scripts/build.sh "$(cat BTOP_VERSION)"   # build dist/unraid.btop-<date>-x86_64-1.txz
bash scripts/test_build.sh                    # build + verify package contents
php tests/php/run_tests.php                   # unit tests for the stats library
bash scripts/test_php_syntax.sh               # php -l on all php/page files
```

## Repo layout

- `btop.plg` - Unraid plugin manifest. The `version`/`md5` entities and the
  `<CHANGES>` section are **bumped by CI - never edit them manually**.
- `source/btop/usr/local/emhttp/plugins/btop/` - webgui files: dashboard tile
  (`btop-dashboard.page` + `scripts/btop-tile.js`) and PHP endpoints
  (`include/stats.php`, `include/stats_lib.php`).
- `scripts/` - build and test scripts.
- `tests/php/` - fixture-based unit tests for the stats library.
- `.github/workflows/` - CI (`ci.yml`), automated releases (`release.yml`),
  daily upstream version tracking (`check-btop-update.yml`).

## Pull requests

- Keep changes focused on one thing.
- Changes to `stats_lib.php` must come with fixture tests (see
  `tests/php/fixtures/` for the pattern).
- Run the four commands above locally before opening the PR - CI runs the
  same ones.
- Do not bump `btop.plg` version/md5 or `BTOP_VERSION` in a PR - `release.yml`
  owns them.

## Releases

Fully automated: `check-btop-update.yml` (daily cron) detects new btop
releases and triggers `release.yml`, which builds, smoke tests, publishes the
GitHub Release, then bumps `btop.plg` + `BTOP_VERSION` on `main`. Maintainers
can also trigger `release.yml` manually (workflow_dispatch, optional
`btop_version` input).

## License

By contributing you agree that your contributions are licensed under the
[MIT License](LICENSE). The bundled btop binary keeps its own Apache-2.0
license.
