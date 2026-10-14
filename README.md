# Multiplayer Logic Constructor

**Scratch for multiplayer game backends.** Game developers assemble
server-authoritative rules from visual blocks — players only trigger named
API actions; every move, roll and resource spend is verified on our platform.
No backend code for developers, no cheating clients.

See **[docs/DESIGN.md](docs/DESIGN.md)** for the full architecture.

## How it works

1. A developer builds a **program** in the web editor (Blockly): trigger
   blocks (`when player plays card`), control flow, scoped variables
   (`local`/`player`/`room`/`game`), game primitives (turns, broadcasts,
   timers) and anti-cheat blocks (`require`, `consume`, server-side RNG).
2. Publishing freezes an immutable version; the game's runtime pointer flips.
3. Player devices call `POST /api/logic.php?action=play_card` — the engine
   executes the matching trigger inside a transaction (room row locked,
   actions serialized), mutates state, queues `player_updates`, replies.
4. Clients poll updates (WebSocket sidecar post-MVP). `schedule` blocks fire
   `on_timer` triggers via `cron/tick.php`.

## Stack

- PHP 8.2–8.5, vanilla + PDO (no framework) — Hostinger shared hosting.
- MySQL; `database/schema.sql` is the single source of truth.
- Blockly (web cabinet) — editor; canonical program AST is editor-agnostic.

## Dev setup

```bash
cp .env.example .env          # fill DB_* and TOKEN_PEPPER
composer install
php bin/migrate.php init      # create schema (drops existing tables!)
composer test                 # Unit + Contract suites (no DB needed)
php -S localhost:8080 -t public_html   # local dev server
```

Cron (production): `*/1 * * * * php /path/to/cron/tick.php` or web cron with
`?cron_token=<CRON_TOKEN>`.

## Repo layout

```
public_html/   docroot — pages + api/ endpoints
src/           application code (bootstrap.php, Engine/ in M2)
bin/           CLI tools (migrate.php)
cron/          tick.php — scheduled-events worker
database/      schema.sql — single source of truth
tests/         PHPUnit suites + engine fixtures
docs/          DESIGN.md — architecture source of truth
```
