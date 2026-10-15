// Multiplayer Logic Constructor — Unreal Engine SDK
// Contract: docs/SDK.md — "you ask, the server decides".
// Blueprint-callable UObject over IHttpRequest; updates via ticker poll +
// dynamic multicast delegates. Payloads are raw JSON FStrings (payload_json)
// because UHT can't serialize open maps into DTOs.
//
//   UMlcClient* Game = NewObject<UMlcClient>();
//   Game->Init(TEXT("https://mlc.michitai.com"), TEXT("GAME_API_KEY"));
//   Game->OnMlcUpdate.AddDynamic(this, &AMyGameMode::HandleUpdate);
//   Game->RegisterPlayer(TEXT("nova"));
//   Game->JoinRoom(TEXT("room-id"));
//   Game->Do(TEXT("move"), TEXT("{\"x\":1,\"y\":2}"));

#pragma once

#include "CoreMinimal.h"
#include "UObject/Object.h"
#include "Interfaces/IHttpRequest.h"
#include "MlcClient.generated.h"

DECLARE_DYNAMIC_MULTICAST_DELEGATE_TwoParams(FOnMlcUpdate, const FString&, Type,
                                             const FString&, DataJson);
DECLARE_DYNAMIC_MULTICAST_DELEGATE_TwoParams(FOnMlcResult, const FString&, Result,
                                             const FString&, Reason);
DECLARE_DYNAMIC_MULTICAST_DELEGATE_OneParam(FOnMlcError, const FString&, Message);

/**
 * The whole platform client: register -> join a room -> Do() actions ->
 * receive updates. Tokens travel in headers — never URLs.
 */
UCLASS(BlueprintType)
class YOURGAME_API UMlcClient : public UObject
{
    GENERATED_BODY()

public:
    UMlcClient();

    /** Platform base URL + the PUBLIC game API key from your cabinet. */
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void Init(const FString& InBaseUrl, const FString& InApiKey);

    // ---- players ----
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void RegisterPlayer(const FString& Name);
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void Login(const FString& Name);
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void Logout();

    // ---- rooms ----
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void CreateRoom(const FString& Name, int32 MaxPlayers,
                    const FString& Password);
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void JoinRoom(const FString& RoomIdOrToken, const FString& Password);
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void LeaveRoom();

    // ---- gameplay ----
    /** THE trigger — fires on_player_action in your published program.
     *  PayloadJson is a raw JSON object string, e.g. TEXT("{\"x\":1}"). */
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void Do(const FString& Action, const FString& PayloadJson);
    /** Fires on_message — server-validated custom messages. */
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void Send(const FString& Message, const FString& PayloadJson);
    /** Reconnect snapshot — your player_state + room members. */
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void GetState();

    // ---- updates ----
    /** Start/stop the update poller (default ~1.5s). */
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void StartPolling(float IntervalSeconds = 1.5f);
    UFUNCTION(BlueprintCallable, Category = "MLC")
    void StopPolling();

    /** Every new server update. Parse DataJson with a JsonObjectConverter or
     *  a MiniJSON-style helper — it is raw program-defined JSON. */
    UPROPERTY(BlueprintAssignable, Category = "MLC")
    FOnMlcUpdate OnMlcUpdate;
    /** Do/Send finished — Result is "ok" or "rejected"; Reason is the
     *  plain-English rejection reason. */
    UPROPERTY(BlueprintAssignable, Category = "MLC")
    FOnMlcResult OnMlcResult;
    /** Transport/auth failure (network down, bad key, HTTP 500). */
    UPROPERTY(BlueprintAssignable, Category = "MLC")
    FOnMlcError OnMlcError;

private:
    void Request(const FString& Path, const FString& Verb,
                 TSharedPtr<FJsonObject> Body,
                 TFunction<void(TSharedPtr<FJsonObject>)> OnOk,
                 bool bNeedPlayer = true);
    void LogicCall(const FString& Endpoint, TSharedPtr<FJsonObject> Body);
    void ApplyPlayer(TSharedPtr<FJsonObject> R);
    void PollUpdates();

    FString BaseUrl;
    FString ApiKey;
    FString PlayerToken;
    int32   PlayerId = 0;

    FTSTicker::FDelegateHandle PollHandle;
    TSet<FString> SeenUpdateIds;
};
