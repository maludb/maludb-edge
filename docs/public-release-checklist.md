# Public Release Checklist

Use this checklist before publishing `maludb-edge` to a public GitHub repository.

## Current Recommendation

Publish a clean initial public import instead of pushing the private-server Git history. The tracked history has no obvious committed secrets from the current scan, but a clean import avoids exposing private server metadata, internal commit sequencing, and any future-discovered history issue.

## Files That Must Not Be Published

The repository `.gitignore` excludes these local artifacts:

- `.env` and `.env.*`
- `html/.env` and `html/.env.*`
- `var/`
- `html/var/`
- SQLite and DB files: `*.sqlite`, `*.sqlite3`, `*.db`, WAL/SHM/journal files
- `html/test-connection.php`
- `html/vendor/`
- logs and temporary files

`html/test-connection.php` existed locally during foundation development and contained connection credentials. Treat those credentials as exposed and rotate them before public release.

## Files Expected In The Public Repository

- `README.md`
- `.env.example`
- `.gitignore`
- `SECURITY.md`
- `docs/`
- `html/composer.json`
- `html/composer.lock`
- `html/.htaccess`
- `html/index.php`
- `html/bin/edge`
- `html/config/`
- `html/database/`
- `html/src/`
- `html/tests/`

## Local Audit Commands

Run from the repository root:

```sh
git status --short
git diff --check
git ls-files | sort
```

Scan tracked files for high-risk secret patterns:

```sh
git grep -n -I -E '(BEGIN (RSA|OPENSSH|EC|DSA|PRIVATE) KEY|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9_]{36,}|github_pat_[A-Za-z0-9_]+|malu_[A-Za-z0-9_-]{25,}|sk-[A-Za-z0-9]{20,})' -- . || true
```

Scan history for high-risk secret patterns:

```sh
git rev-list --all | while read rev; do
  git grep -n -I -E '(BEGIN (RSA|OPENSSH|EC|DSA|PRIVATE) KEY|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9_]{36,}|github_pat_[A-Za-z0-9_]+|malu_[A-Za-z0-9_-]{25,}|sk-[A-Za-z0-9]{20,})' "$rev" -- . || true
done
```

Verify ignored local artifacts are ignored:

```sh
git check-ignore -v html/test-connection.php var/edge.sqlite html/vendor
```

Verify PHP foundation tests:

```sh
cd html
php tests/run.php
```

If local PHP lacks SQLite extensions, use:

```sh
cd html
php -d extension=/tmp/php83-sqlite/usr/lib/php/20230831/sqlite3.so \
  -d extension=/tmp/php83-sqlite/usr/lib/php/20230831/pdo_sqlite.so \
  tests/run.php
```

## Clean Public Import Procedure

Create the GitHub repository first, then run these commands from the private checkout:

```sh
PUBLIC_REMOTE=git@github.com:maludb/maludb-edge.git
EXPORT_DIR=/tmp/maludb-edge-public

rm -rf "$EXPORT_DIR"
mkdir -p "$EXPORT_DIR"
git archive --format=tar HEAD | tar -x -C "$EXPORT_DIR"

cd "$EXPORT_DIR"
git init -b main
git add .
git commit -m "Initial public release"
git remote add origin "$PUBLIC_REMOTE"
git push -u origin main
```

Before pushing, inspect the clean import:

```sh
git status --short
git ls-files | sort
find . -maxdepth 3 -type f | sort
```

Confirm these paths are absent:

```sh
test ! -e html/test-connection.php
test ! -e var/edge.sqlite
test ! -d html/vendor
```

## GitHub Repository Setup

After pushing:

- Enable GitHub secret scanning.
- Enable branch protection on `main`.
- Require pull requests before merge.
- Add CI for Composer install and PHP tests.
- Add release tags only after the public repo has a clean audit.

## Release Blockers

Do not publish until these are resolved:

- Credentials from local `html/test-connection.php` are rotated.
- `git status --short` only shows intentional changes before export.
- Public import has been inspected before first push.
