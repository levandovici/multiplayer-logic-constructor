# Multiplayer Logic Constructor — C# / .NET SDK

One file: `MlcClient.cs`. Drop it into any C# project (.NET 5+, .NET Core
3.1+, or netstandard2.1 — works inside Unity too).

The mental model: **you ask, the server decides.** Your game calls `Do()`
with an action name + arguments; the platform runs your block program,
checks the rules, updates state and replies `ok` or `rejected` with a reason.

```csharp
var game = new MlcClient("https://mlc.michitai.com", "GAME_API_KEY");

await game.RegisterPlayerAsync("nova");          // → player token (stored)
await game.JoinRoomAsync("room-id");             // or CreateRoomAsync(...)

var r = await game.DoAsync("move", new { x = 1, y = 2 });
if (r.Result == "rejected") ShowReason(r.Reason); // e.g. "not your turn"

// Updates — poll once, or run the background loop:
game.OnUpdate += u => ApplyUpdate(u.Type, u.Data);
_ = game.StartPollingAsync(TimeSpan.FromSeconds(1.5), cancellationToken);
```

## Reference

| Call | Purpose |
|---|---|
| `RegisterPlayerAsync(name)` / `LoginAsync(name)` | player identity → token stored on the client |
| `CreateRoomAsync(name, maxPlayers, password?)` | create room → returns room id |
| `JoinRoomAsync(idOrToken, password?)` / `LeaveRoomAsync()` | room lifecycle |
| `DoAsync(action, args)` | **the gameplay trigger** — fires `on_player_action` |
| `DoJsonAsync(action, json)` | same, payload as raw JSON string |
| `SendAsync(message, data)` / `SendJsonAsync(m, json)` | fires `on_message` |
| `GetUpdatesAsync()` / `StartPollingAsync()` | server updates → `OnUpdate` event |
| `GetStateAsync()` | reconnect snapshot (your slice only) |
| `HeartbeatAsync()` / `LogoutAsync()` | presence |

`MlcResult.Rejected` is a **normal answer**, not an exception — show
`Reason` to the player. `MlcException` means a real failure (network,
auth, program crash) — treat as an error, not gameplay.

Tokens travel in headers (`X-Api-Token`, `X-Player-Token`) — never URLs.
