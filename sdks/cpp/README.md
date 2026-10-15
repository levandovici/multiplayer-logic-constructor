# Multiplayer Logic Constructor — C++ SDK

Two files: `mlc_client.hpp` + `mlc_client.cpp`, no engine dependency.

Dependencies: **libcurl** + **nlohmann::json**
(`vcpkg install curl nlohmann-json`, or `apt install libcurl4-openssl-dev
nlohmann-json3-dev`, or `brew install curl nlohmann-json`).

```cpp
mlc::Client game("https://mlc.michitai.com", "GAME_API_KEY");

game.registerPlayer("nova");            // → player token (stored)
game.joinRoom("room-id");               // or createRoom("lobby", 4)

game.onUpdate([](const mlc::Update& u) {
    applyUpdate(u.type, u.data);        // nlohmann::json — free-form
});
game.startPolling();                    // ~1.5s background thread

auto r = game.do_("move", {{"x", 1}, {"y", 2}});
if (r.rejected()) showReason(r.reason); // e.g. "not your turn"
```

## Reference

| Call | Purpose |
|---|---|
| `registerPlayer(name)` / `login(name)` | identity → token stored |
| `createRoom(name, max, password)` / `joinRoom(idOrToken)` / `leaveRoom()` | rooms |
| `do_(action, json)` / `doJson(action, raw)` | **the trigger** — `on_player_action` |
| `send(msg, json)` / `sendJson(msg, raw)` | `on_message` |
| `pollUpdates()` / `startPolling()` | updates → `onUpdate` callback |
| `getState()` / `getMeta()` | snapshot / program metadata |
| `heartbeat()` / `logout()` | presence |

`r.rejected()` is a normal answer — show `r.reason`. `mlc::Error` is thrown
on transport/auth failures — that's a bug report, not gameplay.

The poll thread invokes callbacks on a background thread — marshal to your
game thread if your engine needs it. Tokens are headers-only, never URLs.
