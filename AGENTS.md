# Project guidance — multiplayer-logic-constructor

**MLC is a standalone platform**: game developers assemble server-authoritative
multiplayer logic from visual blocks; player devices only trigger named API
actions. See `docs/DESIGN.md` — it is the architecture source of truth.

## Layout

- `public_html/` — the only web-accessible directory (docroot, named for
  Hostinger). Pages, `api/` endpoints, statics.
- `src/` — application code; `bootstrap.php` is the single entry every
  endpoint loads. Never inside the docroot. `src/Engine/` = the block-program
  interpreter: `Ops` (whitelist), `Program` (AST validation), `Executor`
  (interpreter), `Context` (scopes + outbox), `StateStore` seam with
  `ArrayStateStore` (tests/simulator) and `PdoStateStore` (production),
  `Runtime` (one trigger = one transaction, room row FOR UPDATE).
- `bin/` — CLI tools (`migrate.php`, `seed-demo.php`); `cron/` —
  `tick.php` scheduled-events worker (fires `on_timer`). Both require CLI
  (or `?cron_token=` matching `CRON_TOKEN` for web cron).
- `database/` — `schema.sql`, the complete schema and single source of truth.
  No migration files — import it directly or `php bin/migrate.php init`.
- `tests/` — PHPUnit (`Unit`, `Contract`, `Integration` suites).
- `docs/` — design documentation.

## Conventions

- PHP 8.2 through 8.5 (Hostinger). No PHP-8.3+-only APIs. No framework —
  vanilla PHP + PDO, matching multiplayer-api style.
- Every entry point: `require_once dirname(__DIR__, N) . '/src/bootstrap.php';`
  — N = depth below repo root (public_html page=1, public_html/api=2,
  bin/cron=1).
- `APP_ROOT` = repo root (holds `.env`, `vendor/`, `logs/`);
  `APP_SRC` = `src/`.
- API endpoints start with `api_headers()` (CORS + OPTIONS) and reply via
  `respond_json()`. Secrets never in URLs — headers only
  (`X-Api-Token`, `X-Api-Private-Token`, `X-Player-Token`).
- No new inline `<script>` without `nonce="<?= csp_nonce() ?>"`; no inline
  event handlers. `send_security_headers()` must be called before output on
  every HTML page.
- User logic is **data, not code**: the engine whitelists `op` codes — never
  `eval`, never SQL from a program, no filesystem/network access.
- Transactions: every trigger execution = one DB transaction with the room
  row `SELECT … FOR UPDATE` first. A rejected/failed run rolls back fully.

## Tests

- Unit + Contract (no DB): `composer install` then `composer test`.
  Contract tests guard editor↔engine op drift (mlc-blocks.js vs Ops).
- Integration (needs MySQL): `TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test_mlc
  TEST_DB_USER=test TEST_DB_PASS=test composer test:integration`.
  `EngineRuntimeTest` runs the published tic-tac-toe fixture through
  Runtime against real MySQL (transaction/rollback assertions).
- Smoke (needs a deployed target): `composer test:smoke -- https://host
  [api_token] [player_token]` or `bash tests/Smoke/smoke.sh …`.
- CI: `.github/workflows/ci.yml` — PHP 8.2–8.5 matrix (unit+contract+lint+
  PHPStan) and a MySQL-service job running the Integration suite.
- Engine fixtures live in `tests/Fixtures/programs/*.json` — canonical AST
  programs executed end-to-end (tictactoe.json covers joins, turns, win
  detection, rejections).
- Signal classes (Reject, RespondSignal, ReturnSignal, BreakSignal,
  BudgetExhausted, EngineError) are one-class-per-file under src/Engine/ —
  PSR-4 autoload requires it.
- The player action endpoint is `POST api/logic.php?action=do` with body
  `{action, payload}`; `action=message` fires `on_message`;
  `action=meta` describes the published program. `api/simulate.php` is the
  cabinet dry-run (session+CSRF, in-memory store).

## Verify

- `php -l` every touched PHP file; `git diff --check`.
- `composer lint` (PHPStan), `composer cs` (php-cs-fixer) — after `composer install`.
- Windows without a php.ini (php -m shows no mbstring/openssl): write a
  minimal php.ini enabling `mbstring openssl curl pdo_mysql pdo_sqlite` and
  run tools as `PHPRC=<dir> php vendor/bin/phpunit|phpstan|php-cs-fixer` —
  PHPStan's spawned workers don't inherit `-d` flags, so PHPRC is required.
