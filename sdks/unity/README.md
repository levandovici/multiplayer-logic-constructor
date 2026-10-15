# Multiplayer Logic Constructor — Unity SDK

Three files, drop into `Assets/`:

- `MlcClient.cs` — the shared C# client (async API, update poller)
- `MlcClientBehaviour.cs` — MonoBehaviour wrapper: polls updates, raises
  UnityEvents you wire in the inspector. No coroutines needed.
- `MiniJSON.cs` — free-form JSON decode/encode (JsonUtility can't do it)

Requires **System.Text.Json** — install via NuGet-for-Unity or the
`com.unity.nuget.newtonsoft-json`-style package manager, or any recent
Unity version with the package added.

```csharp
public class GameController : MonoBehaviour {
    public MlcClientBehaviour client;   // set BaseUrl + ApiKey in inspector

    void Start() {
        client.OnUpdate.AddListener(u => {
            var m = JsonUtility.FromJson<MoveData>(u.DataJson);
            ApplyUpdate(u.Type, m);
        });
        client.OnDoResult.AddListener(r => {
            if (r.Result == "rejected") Warn(r.Reason);   // "not your turn"
        });
        client.RegisterPlayer("nova");
        client.JoinRoom("room-id");
    }

    public void OnCellClicked(int x, int y) =>
        client.Do("move", new MoveArgs { x = x, y = y });
}

[Serializable] class MoveArgs { public int x; public int y; }
[Serializable] class MoveData { public int x; public int y; public string player; }
```

## Unity JSON notes (important!)

`JsonUtility` can't serialize `Dictionary` or parse arbitrary objects, so:

- **Outgoing**: `client.Do("move", args)` uses `JsonUtility.ToJson(args)` on
  your `[Serializable]` class, or pass a raw JSON string directly, or a
  `Dictionary` via `MiniJSON.Json.Encode(dict)`. All sent as `payload_json`.
- **Incoming**: payloads arrive as strings — `u.DataJson`, `r.ResponseJson`.
  Parse typed with `JsonUtility.FromJson<T>(s)` or free-form with
  `MiniJSON.Json.Decode(s)`.

`rejected` is a normal answer — show the reason to the player.
The **public** API key goes in the inspector; the private key never ships.
