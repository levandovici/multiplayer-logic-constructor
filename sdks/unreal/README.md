# Multiplayer Logic Constructor — Unreal Engine SDK

Two files: `MlcClient.h` + `MlcClient.cpp` — a Blueprint-callable
`UMlcClient : UObject`. Drop into your project's `Source/` folder.

In your `.Build.cs`:

```csharp
PrivateDependencyModuleNames.AddRange(new[] { "HTTP", "Json", "JsonUtilities" });
```

Rename the `YOURGAME_API` macro in `MlcClient.h` to your module's API macro.

```cpp
UMlcClient* Game = NewObject<UMlcClient>();
Game->Init(TEXT("https://mlc.michitai.com"), TEXT("GAME_API_KEY"));

Game->OnMlcUpdate.AddDynamic(this, &AMyGameMode::HandleUpdate);
Game->OnMlcResult.AddDynamic(this, &AMyGameMode::HandleResult);
Game->OnMlcError.AddDynamic(this, &AMyGameMode::HandleError);

Game->RegisterPlayer(TEXT("nova"));
Game->JoinRoom(TEXT("room-id"));
Game->Do(TEXT("move"), TEXT("{\"x\":1,\"y\":2}"));   // raw JSON payload
Game->StartPolling(1.5f);
```

## Blueprints

Every method is `BlueprintCallable`; events are `BlueprintAssignable`
(`OnMlcUpdate`, `OnMlcResult`, `OnMlcError`). Designers can wire the whole
contract without C++.

## Payload convention

UHT can't serialize open maps into DTOs, so payloads are raw JSON
`FString`s sent as `payload_json`. Incoming data arrives as `DataJson`
strings — parse with `JsonObjectConverter`/`FJsonObjectConverter` into a
`UStruct`, or a MiniJSON-style helper for free-form access.

`Result == "rejected"` is a normal answer — `Reason` explains why
("not your turn"). `OnMlcError` means a real transport/auth failure.
