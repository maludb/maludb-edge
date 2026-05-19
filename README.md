# maludb-edge

PHP edge service for the MaluDB API foundation.

## Documentation

- [Application integration roadmap](docs/maludb-edge-application-integration-roadmap.md)
- [Public release checklist](docs/public-release-checklist.md)
- [Security policy](SECURITY.md)
- [License](LICENSE)
- [API and MCP design](docs/api-and-mcp-design.md)

## Requirements

- PHP 8.2 or newer.
- Composer.
- PHP `sqlite3` and `pdo_sqlite` extensions for tests, migrations, CLI bootstrap, and local smoke runs.
- `MALUDB_EDGE_APP_KEY` set to at least 32 bytes before running commands that use encrypted credentials.

If the system PHP does not load SQLite extensions, pass local extension overrides to each PHP command:

```sh
PHP_SQLITE_EXTS="-d extension=/tmp/php83-sqlite/usr/lib/php/20230831/sqlite3.so -d extension=/tmp/php83-sqlite/usr/lib/php/20230831/pdo_sqlite.so"
php $PHP_SQLITE_EXTS --ri pdo_sqlite
```

## Install, Autoload, and Test

Run Composer from the app directory:

```sh
cd html
composer install
composer dump-autoload
php $PHP_SQLITE_EXTS tests/run.php
```

If the system PHP already has `pdo_sqlite` enabled, the Composer test script is:

```sh
composer test
```

## Foundation Bootstrap

The default runtime state is outside the web docroot:

- SQLite database: `/var/www/var/edge.sqlite`
- Archive directory: `/var/www/var/archive`

Set an explicit app key before migration and admin bootstrap:

```sh
cd html
export MALUDB_EDGE_APP_KEY="replace-with-at-least-32-bytes-value"
php $PHP_SQLITE_EXTS bin/edge migrate
php $PHP_SQLITE_EXTS bin/edge admin:create \
  --email=admin@example.test \
  --tenant=default \
  --dsn="pgsql:host=127.0.0.1;port=5432;dbname=maludb" \
  --username=maludb \
  --password=change-me
```

For repeatable local bootstrap runs, remove the default database from `html` with:

```sh
rm -f ../var/edge.sqlite
```

Or keep scratch state somewhere else:

```sh
export MALUDB_EDGE_SQLITE=/tmp/maludb-edge.sqlite
php $PHP_SQLITE_EXTS bin/edge migrate
```

## Local Server Smoke

Start the PHP built-in server from `html`, using a free port:

```sh
cd html
export MALUDB_EDGE_APP_KEY="replace-with-at-least-32-bytes-value"
export MALUDB_EDGE_SQLITE=/tmp/maludb-edge.sqlite
php $PHP_SQLITE_EXTS bin/edge migrate
php $PHP_SQLITE_EXTS -S 127.0.0.1:8080 index.php
```

In another shell, verify the foundation endpoints:

```sh
curl -fsS http://127.0.0.1:8080/v1/health
curl -fsS http://127.0.0.1:8080/v1/version
curl -fsS http://127.0.0.1:8080/v1/openapi.json
```

Stop the local server with `Ctrl-C` when finished.
