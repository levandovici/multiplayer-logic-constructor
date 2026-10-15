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
- `.htaccess` files are part of the security model — keep them in sync:
  repo root + `src/` `bin/` `cron/` `database/` `templates/` deny all
  (deploy.sh also writes deny files into `~/vendor` and `~/logs`);
  `public_html/.htaccess` re-grants, forces HTTPS, 404s dot-segments
  (`.well-known` is exempt — ACME SSL renewal), blocks dev files;
  `assets/` and `js/` refuse script execution. Any NEW non-public
  top-level dir needs its own `Require all denied` .htaccess.

## Tests

- Unit + Contract (no DB): `composer install` then `composer test`.
  Contract tests guard editor↔engine op drift (mlc-blocks.js vs Ops).
- Integration (needs MySQL): `TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test_mlc
  TEST_DB_USER=test TEST_DB_PASS=test composer test:integration`.
  `EngineRuntimeTest` runs the published tic-tac-toe fixture through
  Runtime against real MySQL (transaction/rollback assertions).
- Security (needs a deployed target): `TEST_BASE_URI=https://staging…
  composer test:security` — docroot leaks, query-token auth, session walls.
- Smoke (needs a deployed target): `composer test:smoke -- https://host
  [api_token] [player_token]` or `bash tests/Smoke/smoke.sh …`.
- CI: `.github/workflows/ci.yml` — PHP 8.2–8.5 matrix (unit+contract+lint+
  PHPStan) and a MySQL-service job running the Integration suite.
  `.github/workflows/security.yml` — weekly + on-push: semgrep static scan,
  `composer audit`, gitleaks secret scan (`.gitleaks.toml`).
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

## Brand & assets

- Product name in user-facing text is always "Multiplayer Logic Constructor"
  (never bare "MLC"/"Logic Constructor"); no third-party brand names in copy.
- `public_html/assets/` — generated brand/marketing art, committed. Never
  hand-edit binaries: `bin/make-icons.php` (logo/favicon/og) and
  `bin/make-marketing.php` (landing genre cards) regenerate everything —
  PHP + GD only (`PHPRC=.dev-php php bin/make-*.php` locally).
- `brand_head_tags()` in src/helpers.php emits icons/manifest/OG/Twitter tags;
  every HTML page calls it in <head>.

## Roadmap context (so it's not forgotten)

- docs/SDK.md = the client API contract the SDKs must implement.
- M9 = client SDKs ×4 in the multiplayer-sdk repo (C#/.NET → Unity → Unreal → C++).
- M10 = kid-friendly docs + per-genre starter programs (12+ audience).

## Templates (new game from template)

- `templates/<slug>.{workspace,ast}.json` — committed pairs: Blockly
  serialization (editor draft) + canonical AST (published v1 on create).
- NEVER hand-edit `*.ast.json` or `*.workspace.json`: author programs in
  `bin/build-templates.mjs` (Blockly API calls, real headless Blockly),
  then `node bin/build-templates.mjs` regenerates both files.
- Requires dev dep: `npm install --save-dev blockly@10.4.3` (package.json).
- Cabinet `?template=slug` on games.php create → seeds program + publishes v1.
- `TemplatesContractTest` guards: ASTs publishable, ws/ast pairs exist,
  workspace block types ⊆ mlc-blocks.js registry.

## Commits

- NEVER reference Devin (or any AI agent) in commit messages — no
  "Generated with", no Co-Authored-By trailers, no co-authors at all.
  Commit messages: imperative, why-focused, plain text only.
