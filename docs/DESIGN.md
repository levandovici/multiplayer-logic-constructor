# Multiplayer Logic Constructor — Platform Design

Status: draft v0.1 · Target: PHP 8.2–8.5 (Hostinger), MySQL, Node.js realtime sidecar

## 1. Vision

**MLC is a visual constructor for server-authoritative multiplayer game backends.** A game developer assembles
their game's server-side rules from visual blocks — no PHP, no servers, no
anti-cheat code to write. Player devices only *trigger* named actions over the
API; every rule, state change, and random roll is executed and verified on our
platform. Clients can never write game state directly — that is the anti-cheat
guarantee and the core selling point.

```
┌──────────────┐   block program (JSON)   ┌──────────────────────────────┐
│  Game dev    │ ───────────────────────▶ │  MLC cabinet (web editor)    │
│  (browser)   │                          │  Block editor + publish      │
└──────────────┘                          └──────────────┬───────────────┘
                                                        │
┌──────────────┐   POST /api/logic.php   ┌──────────────▼───────────────┐
│ Player device│ ── action=move {...} ──▶ │  MLC runtime (PHP)           │
│  (SDK)       │ ◀── {ok, result} ──────  │  auth → lock room → execute  │
└──────────────┘                          │  blocks → mutate state →     │
      ▲  ▲                                │  queue updates/broadcasts    │
      │  └─────── player_updates ────────▶└──────────────┬───────────────┘
      └──────────── WebSocket push ───────│  realtime service (Node.js)  │
                                          └──────────────────────────────┘
```

## 2. Standalone platform

**Decision: MLC is fully standalone.** It is installable and operable without
multiplayer-api — it owns its developer accounts, games (API keys), players,
rooms, update queues, and scheduled-event worker. The architecture, schema
patterns, and security posture are ported from multiplayer-api (proven on
Hostinger), but no table or codepath is shared at runtime.

Consequences:

- The base schema includes its own copies of the multiplayer primitives:
  `users`, `games`, `players`, `rooms`, `room_players`, `player_updates`
  (trimmed to what the logic platform needs — no friends/matchmaking/bans in
  v1; they can be ported later as more blocks/endpoints).
- SDKs for MLC are new thin clients (token + named-action calls + update
  polling). Porting the existing multiplayer-sdk surface is easy later.
- Realtime push starts as **polling `player_updates`**; an optional Node.js
  WebSocket sidecar (mirroring multiplayer-api's `realtime/`) is a post-MVP
  add-on behind the same `mlc_fire`/outbox seam.
- Deployment mirrors multiplayer-api: `public_html/` docroot + `src/` +
  `bin/` + `cron/` + `database/schema.sql`, Hostinger-compatible, no daemons.

## 3. The program model

The unit of user logic is a **program**: one per game, versioned, with a
draft → publish lifecycle. The editor stores block-workspace JSON; on save
the server compiles it into a **canonical AST** that the runtime executes.
The canonical form is editor-agnostic — a future Unity/BlocksEngine2-based
editor can emit the same format.

Canonical program (server-side, what the executor runs):

```json
{
  "version": 1,
  "variables": {
    "player":  { "score": 0, "hand": [] },
    "room":    { "deck": [], "turn": 0, "phase": "lobby" },
    "game":    { "totalMatches": 0 }
  },
  "functions": [
    { "name": "dealCards", "params": ["count"], "body": [ /* stmt blocks */ ] }
  ],
  "triggers": [
    {
      "event": "on_player_action",
      "action": "play_card",
      "body": [ /* stmt blocks, see below */ ]
    },
    { "event": "on_player_join",  "body": [] },
    { "event": "on_timer", "timer": "round_end", "body": [] }
  ]
}
```

A **statement** runs for effect; an **expression** returns a value. Both are
the same node shape — `op` + typed inputs — matching how BlocksEngine2 nests
"operation" blocks inside inputs:

```json
{ "op": "if",
  "cond":  { "op": "eq", "a": { "op": "var.room", "name": "phase" },
                          "b": { "op": "lit", "value": "play" } },
  "then":  [ { "op": "set.player", "name": "score",
               "value": { "op": "add", "a": {"op":"var.player","name":"score"},
                                        "b": {"op":"lit","value":10} } } ],
  "else":  [ { "op": "reject", "reason": "not in play phase" } ] }
```

### Variable scopes (the important design choice)

| Scope   | Lifetime                    | Backing store                     |
|---------|-----------------------------|-----------------------------------|
| `local` | One execution               | Engine scope map, never persisted |
| `player`| Persists per player per room| `player_state` (JSON per player)  |
| `room`  | Persists for the room       | `room_state` (JSON per room)      |
| `game`  | Persists for the game       | `game_state` (JSON per game)      |

The scope prefix is part of the block (`var.player`, `var.room`, …) so the
editor can colour-code persistence and the runtime can lazy-load only the
scopes a trigger actually touches.

## 4. Block catalogue — v1

### Triggers (hat blocks, program entry points)

| Event                | Fired when                                             |
|----------------------|--------------------------------------------------------|
| `on_player_action`   | Client calls `logic.php?action=<name>` with payload    |
| `on_player_join`     | Player enters the room                                 |
| `on_player_leave`    | Player leaves / times out                              |
| `on_room_created`    | Room row created                                       |
| `on_match_start`     | Program calls `match.start` or room activates          |
| `on_match_end`       | Program calls `match.end`                              |
| `on_timer`           | A `schedule` block's timer fires (cron-driven, ~1 min) |
| `on_message`         | Client sends a named fire-and-forget message           |

### Control
`if`, `if/else`, `repeat n`, `repeat until`, `while` (iteration-capped),
`for each item in list`, `for each player in room`, `break`,
`call function`, `return value` (function blocks), `respond` (set the API
response and stop), `reject` (fail the action with a reason),
`schedule timer <name> in <n> <unit> with <payload>`, `cancel timer`.

### Logic & math
`eq ne lt le gt ge`, `and or not xor`,
`add sub mul div mod`, `abs min max clamp round floor ceil pow sqrt`,
`random.int`, `random.float`, `random.pick`, `list.shuffle`
(**all RNG is server-seeded** — clients request, we roll).

### Text
`concat`, `length`, `contains`, `upper`, `lower`, `substring`, `format`
(`{player} scored {points}` templating for broadcast messages).

### Lists & maps
`list.create/of`, `list.add/insert/remove_at/set_at/get_at`,
`list.length/contains/index_of/clear/shuffle`,
`map.get/set/delete/keys/has`. Lists and maps are JSON values inside any scope.

### Game primitives (the multiplayer vocabulary)

- **Context**: `player.id`, `player.name`, `player.is_host`,
  `player.by_id`, `room.players` (list), `room.id`, `room.name`,
  `payload.<key>` (the client-sent arguments), `now`.
- **Turns**: `turn.current`, `turn.set <player>`, `turn.next`
  (round-robin over `room.players`), `turn.assert` (reject if not their turn).
- **Match**: `match.start`, `match.end <result>`, `phase` helpers are just
  room variables, but named blocks make intent visible.
- **Player state**: `player.prop get/set/delete`, `player.list_all`.
- **Kick/ban-lite**: `player.kick <reason>` (marks room_players row).

### Communication
`respond <value>` — the action's JSON response body;
`send.to_player <player> <type> <data>`;
`send.to_room <type> <data>`;
`send.to_others <type> <data>` — all write `player_updates` rows so existing
poll/realtime delivery works unchanged;
`broadcast.log <text>` — convenience: typed update + action-log entry.

### Anti-cheat / verification (flagship category)

- `require <cond> else reject <reason>` — declarative validation gate.
- `turn.assert` — server knows whose turn it is; client cannot fake it.
- `ratelimit.this <n> per <sec>` — per-player per-action bucket.
- `verify.move <from> <to> <max>` — position delta vs. allowed speed/range.
- `consume <scope>.<res> <amount>` — atomic check-and-decrement, rejects on
  insufficient; the single most common cheat (spend what you don't have).
- `verify.dice`, `verify.draw` — server-side RNG committed before reveal.

Everything outside the catalogue is impossible by construction: the
interpreter whitelists `op` codes — there is no `eval`, no SQL, no
filesystem, no network egress from user code.

## 5. Execution engine (`src/Engine/`)

```
LogicRequest ─▶ ProgramRegistry (published AST, cached)
            ─▶ Executor
                ├─ Context   { game, room, player, payload, actionLog }
                ├─ Scopes    { local[], player↔DB, room↔DB, game↔DB }
                ├─ Budget    { steps, loopIters, dbWrites, outUpdates }
                └─ Outbox    { response, updates[], scheduled[] }
            ─▶ commit: state writes + player_updates + scheduled events
```

Execution semantics:

- **One trigger = one transaction.** The endpoint wraps the run in
  `START TRANSACTION … COMMIT`; the room row is taken
  `SELECT … FOR UPDATE` first, so concurrent actions in the same room are
  serialized — turn-based correctness falls out of the DB for free.
- **Step budget** (default 10k ops) + per-loop iteration caps, mirroring
  BlocksEngine2's overflow guard — a malformed `repeat until` cannot melt
  shared hosting.
- **Size budgets** (all `BudgetExhausted` → rollback): one value ≤ 256 KiB
  serialized (`Value::MAX_BYTES`, checked on every expression result and
  scope write — kills concat-doubling amplification); each scope ≤ 1 MiB
  aggregate (`Context::MAX_SCOPE_BYTES`, byte tallies kept per scope);
  function call depth ≤ 64; per-run outboxes capped (256 updates, 64
  scheduled timers, 64 cancellations, 128 log lines). Request bodies are
  capped at 1 MiB in `json_input` (`php://input` ignores `post_max_size`).
- **No synchronous `wait`.** PHP is request-scoped; "do X later" is always
  `schedule` → `mlc_scheduled_events` row → cron worker re-enters the engine
  under the same locking. (Documented trade-off; sub-minute timers need the
  realtime service or a faster worker — post-MVP.)
- **Determinism**: `random.*` draws from `random_int()` — CSPRNG; seeds are
  never client-visible. Every run appends to `mlc_action_log`
  (action, payload hash, result, state version) → replay, dispute resolution,
  and the "prove the cheat didn't happen" story.
- **Failure model**: a block error or `reject` rolls the transaction back —
  no half-applied state. Client gets `{success:false, error, code}`; full
  detail goes to the action log and PHP error log, never the response.

## 6. Runtime API surface

All endpoints live under `public_html/api/`, share `src/bootstrap.php`, and
follow multiplayer-api conventions (secrets in headers only, `rate_limit`).

**Player-facing primitives** (standalone — MLC owns these):

| Endpoint                          | Purpose                                   |
|-----------------------------------|-------------------------------------------|
| `POST api/player.php?action=register` | Create player in game → returns token   |
| `POST api/player.php?action=login`    | Re-login by name → returns token        |
| `POST api/player.php?action=heartbeat`| Presence + liveness                     |
| `GET  api/player.php?action=updates`  | Poll `player_updates` (long-poll later) |

**Room wrappers** — each performs the DB op **then fires the matching
lifecycle trigger** (decision: wrapper endpoints, multiplayer-api untouched):

| Endpoint                          | Purpose                                   |
|-----------------------------------|-------------------------------------------|
| `POST api/room.php?action=create`  | Create room → fires `on_room_created`    |
| `POST api/room.php?action=join`    | Join room → fires `on_player_join`       |
| `POST api/room.php?action=leave`   | Leave → fires `on_player_leave`          |
| `GET  api/room.php?action=list`    | Open rooms for the game                  |

**Logic runtime:**

| Endpoint                          | Purpose                                   |
|-----------------------------------|-------------------------------------------|
| `GET  api/logic.php?action=meta`   | Published program version + trigger list |
| `POST api/logic.php?action=<n>`    | **The trigger endpoint** — `on_player_action` |
| `POST api/logic.php?action=<n>&mode=message` | Fire `on_message` (no body)   |
| `GET  api/state.php`               | Player-visible slice of room/player state|
| `POST api/simulate.php`            | Cabinet-only: dry-run draft on sandbox   |

Auth: `X-Api-Token` (game) + `X-Player-Token` (player scoped to game),
headers only — same model as multiplayer-api.

## 7. Editor & program lifecycle

- **Editor**: a visual block editor embedded in a
  cabinet page — MIT-licensed library, serializes workspaces to JSON, and the
  toolbox is driven by `api/blocks.php` so the block catalogue has a single
  server-side source of truth (blocks ship with tooltip, input types,
  colour, scope badge).
- **Cabinet**: program CRUD, draft autosave, block-by-block trace viewer.
  Same auth pattern as multiplayer-api's `cabinet.php` (session + CSRF).
- **Publish flow**: validate (unknown ops, missing inputs, type mismatches,
  trigger arity, budget static-check) → freeze immutable
  `mlc_program_versions` row → flip `api_keys.mlc_program_version`.
  Runtime always executes the published version; drafts never leak to prod.
- **Simulator**: `simulate.php` runs a draft against a sandbox room marked
  `is_test` and returns a step trace — the "green flag" play button.

## 8. Database schema (standalone)

Base primitives (ported patterns, trimmed for v1):

```sql
users               id, email UNIQUE, password_hash NULL (email-code login),
                    is_verified, created_at
login_codes         email PK, code_hash, expires_at, attempts, created_at
games               id, user_id→users, name, api_key UNIQUE,
                    api_private_key_hash/prefix/suffix/enc (as multiplayer-api),
                    published_program_id NULL, created_at, updated_at
players             id, game_id→games, name, token UNIQUE, token_hash,
                    player_data JSON, is_online, last_heartbeat, created_at
rooms               room_id PK, game_id→games, name, host_player_id→players,
                    password NULL, max_players, is_active,
                    status ENUM(lobby,playing,ended),
                    created_at, updated_at, last_activity
room_players        player_id PK→players, room_id→rooms, game_id, is_host,
                    is_online, seat INT NULL, joined_at, last_heartbeat
player_updates      update_id PK, room_id, game_id, from_player_id NULL,
                    target_player_id→players, type, data JSON,
                    status ENUM(pending,delivered,read), created_at, delivered_at
rate_limits         (bucket, rate_key) PK, attempts, window_start
```

Logic platform tables:

```sql
programs            id, game_id→games, name, draft_workspace JSON,
                    draft_ast JSON, updated_at, UNIQUE(game_id, name)
program_versions    id, program_id, version INT, ast JSON, checksum CHAR(64),
                    published_by, published_at, UNIQUE(program_id, version)
room_state          room_id PK→rooms, state JSON, state_version BIGINT,
                    updated_at
player_state        (room_id, player_id) PK, state JSON, updated_at
game_state          game_id PK, state JSON, state_version BIGINT, updated_at
scheduled_events    id, game_id, room_id, trigger_name, payload JSON, run_at,
                    status ENUM(pending,running,done,failed), attempts
action_log          id, game_id, room_id, player_id, action, payload JSON,
                    result ENUM(ok,rejected,error), reason, steps_used,
                    duration_ms, state_version_before, state_version_after,
                    created_at
```

`games.published_program_id → program_versions.id` is the single pointer the
runtime reads — publish is one UPDATE, rollback is one UPDATE back.

State rows are created lazily on first write. `state_version` increments on
every commit — optimistic-concurrency signal for the log and future
client-side diffing.

## 9. Security & anti-cheat posture

- Inherit multiplayer-api hardening wholesale: bootstrap, `send_security_headers`,
  CSP nonces, hashed/encrypted token storage, `rate_limits`, header-only secrets.
- **Authorization boundary**: clients can only (a) register/login,
  (b) join rooms, (c) fire actions. State writes are engine-internal.
- Program validation at publish is also a security control (no pathological
  loops, bounded lists/maps, payload size caps).
- Per-game execution quotas + action rate limits protect shared hosting.
- Action log gives per-room replayability — the core promise "logic runs on
  our platform" is auditable, not aspirational.

## 10. Testing & verification

- `composer test` — PHPUnit: unit per op-code family, contract tests that run
  fixture programs end-to-end against sqlite/MySQL and assert state
  transitions + emitted updates.
- Engine fixtures live in `tests/Fixtures/programs/*.json` — a tic-tac-toe
  program as the reference game is the first contract suite.
- Same PHPStan + php-cs-fixer toolchain and `.env` conventions as
  multiplayer-api.

## 11. Milestones

Status: ✅ = landed & verified (unit tests + PHPStan).

| # | Deliverable                                                        | Status |
|---|--------------------------------------------------------------------|--------|
| M0 | Repo skeleton: bootstrap, schema.sql, composer, lint/test config  | ✅ |
| M1 | Platform auth + primitives: dev register/login (email codes),     | ✅ |
|    | games CRUD, `api/player.php`, `api/room.php`, `player_updates`    | |
| M2 | Engine core: AST loader, executor, scopes, budgets, ~30 base ops  | ✅ |
| M3 | `api/logic.php` runtime path + action log + tic-tac-toe fixture   | ✅ |
| M4 | `scheduled_events` + `cron/tick.php` worker + `schedule` blocks   | ✅ |
| M5 | Cabinet: program CRUD + `api/blocks.php` + block editor + publish | ✅ |
| M6 | Simulator + step-trace viewer                                     | ✅ (`api/simulate.php` + editor modal) |
| M7 | Anti-cheat primitive blocks + publish-time validation             | ✅ |
| M8 | SDK `Logic` module (dotnet/unity first) + docs + llms.txt         | pending — SDK repo |
| M9 | Client SDKs ×4 (C#/.NET, Unity, C++, Unreal) per `docs/SDK.md`    | spec ✅ — code pending |
| M10| Kid-friendly onboarding: simple docs, genre starter programs      | templates ✅ — docs pending |
| M11| Marketing assets: `bin/make-marketing.php` genre cards + landing   | ✅ generator + page |

### M9 — Client SDKs (details in `docs/SDK.md`)

One client contract across four SDKs so a 12-year-old learns it once:
`RegisterPlayer` → `JoinRoom`/`CreateRoom` → `Do(action, args)` →
`OnUpdate`/`PollUpdates` → `GetState` (reconnect). `rejected` is a normal
result, not an error. Order: **C#/.NET first** (the same netstandard lib runs
in Unity), then Unity `MonoBehaviour` poller + `UnityEvent`s, then Unreal
(`UMlcClient` + Blueprint delegates), then plain C++ (libcurl/WinHTTP).
Ships in the `multiplayer-sdk` repo — one `Logic` module per SDK.

### M10 — Accessibility for young developers (12+)

- Docs voice: short sentences, "you ask — the server decides" mental model,
  every block page has one runnable example.
- ✅ Starter programs per genre ship as cabinet templates
  (`templates/<slug>.{workspace,ast}.json` — 6 genres: tic-tac-toe, clicker,
  quiz, racing, cards, arena). Authored as Blockly API calls in
  `bin/build-templates.mjs` and compiled with the real `generateAst`, so
  workspace↔AST can't drift. `games.php create?template=` seeds the draft
  AND publishes v1 — template games are playable immediately.
- Error messages stay plain ("cell already taken", "not your turn") — the
  reject `reason` is shown to players verbatim.
- Docs voice still pending: block reference pages, per-language quickstarts.

### M11 — Marketing/regenerable artwork

`bin/make-marketing.php` renders REAL constructor block stacks (puzzle
tabs/sockets, hat domes, C-blocks, the mlc-blocks.js hue palette) with GD
into `public_html/assets/marketing/` — the genre cards show the same logic
the matching template ships. Landing hero shows a mini editor scene
(`hero-constructor.png`). To update artwork: edit the script, re-run,
commit. `bin/make-icons.php` covers logo/favicon/og formats the same way.

## 12. Decisions & open questions

Decided:

- ✅ **Standalone** — no runtime dependency on multiplayer-api.
- ✅ **Visual block** web editor in the MLC cabinet; canonical AST stays
  editor-agnostic so a BlocksEngine2-based editor can emit it later.
- ✅ **Action-driven + scheduled timers** for v1; no continuous tick loop
  (needs a persistent worker — revisit post-MVP).
- ✅ **MLC-owned wrapper endpoints** fire lifecycle triggers;
  multiplayer-api is never patched.

Still open (non-blocking):

1. **Push channel** — `player_updates` polling for v1; optional Node WS
   sidecar mirrors multiplayer-api's `realtime/` later.
2. **Email delivery** for dev login codes — Hostinger SMTP (fsockopen/mail)
   vs a transactional provider; abstract behind `mailer.php`.
3. **Game templates** — ship a tic-tac-toe sample program as both fixture
   and "clone me" starter in the cabinet.
