# Multiplayer Logic Constructor — Client SDK Contract

Every SDK (C#/.NET, Unity, C++, Unreal) wraps the same HTTP API. The API is
deliberately tiny — a player device can only **do things**, never decide
things. If you remember one sentence: *"You ask, the server decides."*

## The mental model (aimed at 12+)

```
YOUR GAME CODE                          OUR PLATFORM
─────────────                          ──────────────
client.do("move", {x:1,y:2})  ──────▶  runs your blocks, checks the rules,
                                       updates state, replies ok / rejected
updates: poll or callback     ◀──────  "Bob moved", "you win", "turn changed"
client.state()                ──────▶  reconnect snapshot (your slice only)
```

- The server is the referee. A client saying "I scored 1000 points" means
  nothing — a client saying "I played card 5" gets checked.
- Tokens are secrets and travel in **headers**, never in URLs.
- Everything is JSON over HTTPS POST/GET — no sockets needed for v1.

## One client class, four languages

| Concept | C# / Unity | Unreal C++ | C++ |
|---|---|---|---|
| client | `new MlcClient(url, apiKey)` | `UMlcClient` (Blueprint-callable) | `MlcClient` |
| register | `RegisterPlayer(name)` | `RegisterPlayer(Name)` | `registerPlayer(name)` |
| login | `Login(name)` | `Login(Name)` | `login(name)` |
| create room | `CreateRoom(name, max, password)` | `CreateRoom(...)` | `createRoom(...)` |
| join | `JoinRoom(roomId \|\| joinToken)` | `JoinRoom(...)` | `joinRoom(...)` |
| leave | `LeaveRoom()` | `LeaveRoom()` | `leaveRoom()` |
| **the trigger** | **`Do("move", args)`** | **`Do(Action, Args)`** | **`do_("move", args)`** |
| message | `Send("chat", data)` | `Send(Message, Data)` | `send("chat", data)` |
| updates | `PollUpdates()` / event `OnUpdate` | delegate `OnMlcUpdate` | `pollUpdates()` |
| state | `GetState()` | `GetState()` | `getState()` |
| heartbeat | `Heartbeat()` (or auto) | `Heartbeat()` | `heartbeat()` |
| logout | `Logout()` | `Logout()` | `logout()` |

## Endpoint map (what the SDK calls)

| SDK call | HTTP |
|---|---|
| register / login | `POST /api/player.php?action=register|login` |
| heartbeat / logout / me | `POST|GET /api/player.php?action=…` |
| updates | `GET /api/player.php?action=updates` |
| rooms | `POST|GET /api/room.php?action=create|join|leave|list|info` |
| **do / send** | `POST /api/logic.php?action=do|message` body `{action, payload}` |
| state | `GET /api/state.php` |
| meta (tooling) | `GET /api/logic.php?action=meta` |

Headers: `X-Api-Token: <game key>` always; `X-Player-Token` after
register/login (SDK stores it).

## Result shape every SDK returns

```json
{ "success": true, "result": "ok|rejected", "reason": "not your turn"|null,
  "response": {…whatever the program's respond block set…}, "version": 3 }
```

`rejected` is a **normal answer**, not an exception — game code shows it to
the player ("that cell is taken"). `error` (500) means the program crashed —
treat as a bug report, never as gameplay.

## Per-language notes

- **C#/.NET** — `HttpClient`, `System.Text.Json`, async/await; PCL-compatible
  netstandard2.1 so it runs in Unity too. Events via `event Action<MlcUpdate>`.
- **Unity** — same C# client + `MlcPoller` MonoBehaviour that polls updates in
  `Update()` on an interval and invokes `UnityEvent`s. No coroutines needed in
  user code.
- **C++** — header + implementation over `libcurl`/`WinHTTP`, `nlohmann::json`.
  `std::function<void(const MlcUpdate&)>` for updates.
- **Unreal** — `UMlcClient : UObject`, HTTP via `IHttpRequest`, updates via a
  `FTicker` poll + `DECLARE_DYNAMIC_MULTICAST_DELEGATE` so Blueprints can bind.

## JSON — the one real difference between SDKs

The **wire format is identical everywhere** (plain JSON); only how the client
*materializes* free-form payloads differs. `Do(action, args)` and `u.data` are
arbitrary JSON — the platform can't ship typed DTOs for program-defined data.

| SDK | Outgoing args | Incoming payload | Why |
|---|---|---|---|
| **.NET** | `payload` object — free-form native | `data` / `response` parsed fields | `System.Text.Json` handles arbitrary JSON |
| **Unity** | `payload_json` **string** | `data_json` / `response_json` / `state_json` strings | `JsonUtility` can't serialize `Dictionary` or parse untyped trees |
| **C++** | `payload` — `nlohmann::json` | `data` / `response` | free-form native |
| **Unreal** | `payload_json` `FString` raw or `FJsonObject` | `*_json` strings + `JsonObjectConverter` helpers | UHT can't serialize open maps into DTOs |

**The `*_json` convention (implemented server-side — same as multiplayer-api):**
every free-form field is emitted twice — parsed (`data`, `response`, `state`)
and raw string (`data_json`, `response_json`, `state_json`). Endpoints that
take free-form input (`do`, `message`) accept `payload` object *or*
`payload_json` string. Unity SDK sends/reads only the `*_json` forms, so its
envelope DTOs stay `[Serializable]`-compatible and game code parses the
string with `JsonUtility.FromJson<T>` (typed) or the bundled MiniJSON dict.

The SDK embeds `MiniJSON.cs` (~200 lines, MIT, no dependency) rather than
pulling Newtonsoft — Unity 2021+ package friction isn't worth it for one file.

## Update delivery in v1

Polling (`?action=updates`, ~1–2s interval, rate-limit 120/min/head). SDKs
expose it as an event/callback so game code looks realtime. A WebSocket push
tier is a later milestone — SDK surface stays identical.

## Security notes for SDK docs

- Game API key is public-ish (ships in the client); the **private** key never
  ships — server/admin ops only.
- Player token is per-device; SDK persists it in PlayerPrefs/ini, not code.
