# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Leitwerk Key Authority (LKA) is a PHP web application (fork of Opera's "SSH Key Authority") for managing SSH key access across servers. It has no frontend build step and no test suite — it's a classic server-rendered PHP app talking to MySQL/MariaDB and LDAP.

## Commands

There is no build system, package.json, linter, or test suite in this repo. Development commands are limited to:

```bash
composer install          # install PHP deps (phpseclib) into vendor/
```

Local/dev environment is Docker-based:

```bash
docker compose up --build   # builds the Apache+PHP8.4 app container and a MariaDB container
```
- On first start, `docker/startup.sh` copies `config/config-sample.ini` to `config/config.ini` and generates the `config/keys-sync` SSH keypair if missing, then starts cron, the `syncd.php` daemon, and Apache.
- `.env.docker` (referenced by `docker-compose.yml`) holds the MariaDB root/app credentials for the compose stack.
- The app expects HTTP basic auth (`PHP_AUTH_USER`) to already be set by Apache (e.g. via `authnz_ldap`) — there is no login page in the app itself.

There are no automated tests to run. Verify PHP changes by syntax-checking and exercising the relevant route in a running container:
```bash
php -l path/to/file.php
```

## Architecture

### Request lifecycle
`public_html/init.php` → `requesthandler.php` → `core.php` (bootstraps config, autoloading, LDAP, DB, extensions) → `router.php`/`routes.php` (regex-based path → view mapping) → a file in `views/*.php`.

- `routes.php` defines two arrays: `$routes` (path → view name, with `{var}` placeholders) and `$public_routes` (subset of paths reachable without an LDAP-authenticated session, e.g. machine-readable `.json`/`.txt` endpoints used by the sync daemon and external tooling).
- `requesthandler.php` resolves the active user from `PHP_AUTH_USER`, enforces CSRF checks on POST (bypassable via `X-Bypass-Csrf-Protection: 1` header for script-driven calls), and denies non-LDAP/non-public views with `views/error403.php`.
- Each `views/*.php` file is a single procedural script: it loads data via model/directory objects, handles `$_POST` actions inline (no controller classes — look at `views/server.php` for a representative example with many `elseif(isset($_POST['...']))` branches), then builds a `PageSection` and echoes it.
- `PageSection` (`pagesection.php`) renders a template from `templates/*.php` by `include()`, with data passed via `->set()`/`->get()`; `templates/base.php` is the outer HTML shell, others are page bodies or `_json`/`_txt` variants for machine-readable output.

### Data model (`model/`)
- `Record` (`model/record.php`) is the base for anything backed by a DB row: lazy-loads fields via `__get` (queries DB on first access), tracks dirty state, and `update()` diffs against the DB and writes only changed fields. Field access is always via magic properties (`$server->hostname`), not getters.
- `DBDirectory` (`model/dbdirectory.php`) is the base for "directory" classes (`ServerDirectory`, `UserDirectory`, `GroupDirectory`, etc.) that hold the CRUD/query methods (`get_*_by_*`, `list_*`, `add_*`) for a given record type. Global directory instances (`$server_dir`, `$user_dir`, `$group_dir`, `$server_account_dir`, `$event_dir`, `$sync_request_dir`, `$pubkey_dir`) are created once in `setup_database()` (`core.php`) and used throughout views/models via `global`.
- `Entity` (`model/entity.php`) is an abstract `Record` subclass shared by `User`, `Group`, and `ServerAccount` — anything that can hold public keys, have access rules granted to/from it, or have admins/leaders. Access control graph: `Access`/`AccessRequest` rows link a source entity to a dest entity; `Entity::sync_remote_access()` recursively re-triggers syncs for everything reachable from a changed entity (including group membership and, for users, all LDAP-authorized servers).
- Every DB write to an `Entity` logs to both syslog and the `entity_event` table via `Entity::log()` — preserve this pattern when adding new mutating operations.
- Autoloading is name-convention based: `autoload_model()` in `core.php` lowercases the class name and strips non-letters to find `model/<lowercased>.php`. Keep new model class names and filenames consistent with this (one class per file, filename = strtolower(classname), no underscores).

### Migrations (`migrations/`)
Numbered PHP files (`001.php`, `002.php`, ...) run automatically on every DB connection via `MigrationDirectory` (`model/migrationdirectory.php`), which compares the DB's `migration` table against `MigrationDirectory::LAST_MIGRATION` and applies any missing ones in order. To add a schema change: create the next-numbered file in `migrations/` containing raw SQL against `$this->database`, and bump `LAST_MIGRATION` in `model/migrationdirectory.php`.

### Key sync daemon (`scripts/`)
This is the part of the system that actually pushes SSH keys to managed servers, separate from the web app:
- `scripts/syncd.php` is a long-running daemon (forks/detaches when run standalone, or runs foreground under `--systemd`) that polls `sync_request_dir->list_pending_sync_requests()` and spawns up to `MAX_PROCS` (20) parallel `scripts/sync.php` child processes via `SyncProcess` (`scripts/sync-common.php`), each wrapped in a 60s `timeout`.
- `scripts/sync.php` is the actual per-server/per-account sync worker (uses `scripts/ssh.php` to connect out and write authorized-keys files under `/var/local/keys-sync/` on Linux targets or `/ProgramData/ssh/keys-sync/` on Windows targets).
- `scripts/ldap_update.php` and `scripts/supervise_external_keys.php` are run periodically via cron (installed in the Dockerfile, or via the OS cron on bare-metal installs) — LDAP sync of users/groups and detection of externally-added (unmanaged) keys, respectively.
- Authentication for these background scripts uses a dedicated system user obtained via `User::get_keys_sync_user()`, not a real logged-in user.

### Configuration
All runtime config lives in `config/config.ini` (gitignored; `config/config-sample.ini` is the annotated template and source of truth for available options — LDAP attribute mapping, key-supervision defaults, host-key/hostname verification strictness, email, GPG signing, etc). Read `config-sample.ini`'s comments before adding new config keys; it documents the security trade-offs of several settings (e.g. `host_key_collision_protection`, `hostname_verification`).
