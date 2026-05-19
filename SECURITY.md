# Security Policy

## Supported Versions

`maludb-edge` is in early foundation development. Security fixes should target the current `main` branch until release branches exist.

## Reporting a Vulnerability

Do not open a public issue for suspected secrets, credential exposure, authentication bypasses, SQL access problems, or file disclosure issues.

Report privately to the project maintainer or repository security contact. Include:

- A short summary of the issue.
- The affected endpoint, file, or command.
- Reproduction steps.
- Whether credentials, API keys, database DSNs, uploaded files, or SQL text may be exposed.

## Public Repository Hygiene

Before publishing or pushing changes:

- Do not commit `.env` files.
- Do not commit SQLite databases or runtime archive files.
- Do not commit `html/test-connection.php`.
- Do not commit `html/vendor/`; run `composer install` instead.
- Rotate any credentials that were ever stored in local test files.
- Run the public release audit checklist in `docs/public-release-checklist.md`.
