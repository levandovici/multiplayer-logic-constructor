// Multiplayer Logic Constructor — Unreal Engine SDK implementation.
// Requires the Http + Json + JsonUtilities modules in your .Build.cs:
//   PrivateDependencyModuleNames.AddRange(new[] { "HTTP", "Json", "JsonUtilities" });

#include "MlcClient.h"
#include "HttpModule.h"
#include "Interfaces/IHttpResponse.h"
#include "Serialization/JsonReader.h"
#include "Serialization/JsonSerializer.h"
#include "Dom/JsonObject.h"
#include "Containers/Ticker.h"

UMlcClient::UMlcClient() = default;

void UMlcClient::Init(const FString& InBaseUrl, const FString& InApiKey)
{
    BaseUrl = InBaseUrl;
    while (BaseUrl.EndsWith(TEXT("/"))) BaseUrl.RemoveAt(BaseUrl.Len() - 1);
    ApiKey = InApiKey;
}

// ---------------- plumbing ----------------

void UMlcClient::Request(const FString& Path, const FString& Verb,
                         TSharedPtr<FJsonObject> Body,
                         TFunction<void(TSharedPtr<FJsonObject>)> OnOk,
                         bool bNeedPlayer)
{
    if (BaseUrl.IsEmpty() || ApiKey.IsEmpty())
    {
        OnMlcError.Broadcast(TEXT("Call Init() first"));
        return;
    }
    if (bNeedPlayer && PlayerToken.IsEmpty())
    {
        OnMlcError.Broadcast(TEXT("RegisterPlayer/Login first"));
        return;
    }

    TSharedRef<IHttpRequest, ESPMode::ThreadSafe> Req =
        FHttpModule::Get().CreateRequest();
    Req->SetURL(BaseUrl + Path);
    Req->SetVerb(Verb);
    Req->SetHeader(TEXT("X-Api-Token"), ApiKey);
    Req->SetHeader(TEXT("Content-Type"), TEXT("application/json"));
    if (!PlayerToken.IsEmpty())
        Req->SetHeader(TEXT("X-Player-Token"), PlayerToken);

    if (Body.IsValid())
    {
        FString BodyText;
        TSharedRef<TJsonWriter<>> W =
            TJsonWriterFactory<>::Create(&BodyText);
        FJsonSerializer::Serialize(Body.ToSharedRef(), W);
        Req->SetContentAsString(BodyText);
    }

    TWeakObjectPtr<UMlcClient> Self(this);
    Req->OnProcessRequestComplete().BindLambda(
        [Self, OnOk](FHttpRequestPtr, FHttpResponsePtr Res, bool bConnected)
    {
        if (!Self.IsValid()) return;
        if (!bConnected || !Res.IsValid())
        {
            Self->OnMlcError.Broadcast(TEXT("network error"));
            return;
        }
        TSharedPtr<FJsonObject> Json;
        TSharedRef<TJsonReader<>> R =
            TJsonReaderFactory<>::Create(Res->GetContentAsString());
        if (!FJsonSerializer::Deserialize(R, Json) || !Json.IsValid())
        {
            Self->OnMlcError.Broadcast(
                FString::Printf(TEXT("bad response (HTTP %d)"),
                                Res->GetResponseCode()));
            return;
        }
        bool bSuccess = false;
        if (Res->GetResponseCode() >= 400 ||
            (Json->TryGetBoolField(TEXT("success"), bSuccess) && !bSuccess))
        {
            FString Msg;
            Self->OnMlcError.Broadcast(
                Json->TryGetStringField(TEXT("error"), Msg)
                    ? Msg
                    : FString::Printf(TEXT("HTTP %d"), Res->GetResponseCode()));
            return;
        }
        OnOk(Json);
    });
    Req->ProcessRequest();
}

void UMlcClient::LogicCall(const FString& Endpoint,
                           TSharedPtr<FJsonObject> Body)
{
    Request(TEXT("/api/logic.php?action=") + Endpoint, TEXT("POST"), Body,
        [this](TSharedPtr<FJsonObject> R)
    {
        FString Result, Reason;
        R->TryGetStringField(TEXT("result"), Result);
        R->TryGetStringField(TEXT("reason"), Reason);
        OnMlcResult.Broadcast(Result, Reason);
    });
}

void UMlcClient::ApplyPlayer(TSharedPtr<FJsonObject> R)
{
    const TSharedPtr<FJsonObject>* P;
    if (R->TryGetObjectField(TEXT("player"), P))
    {
        (*P)->TryGetStringField(TEXT("token"), PlayerToken);
        double Id = 0;
        if ((*P)->TryGetNumberField(TEXT("id"), Id)) PlayerId = (int32)Id;
    }
    if (PlayerToken.IsEmpty())
        R->TryGetStringField(TEXT("player_token"), PlayerToken);
}

// ---------------- players ----------------

void UMlcClient::RegisterPlayer(const FString& Name)
{
    TSharedPtr<FJsonObject> B = MakeShared<FJsonObject>();
    B->SetStringField(TEXT("name"), Name);
    Request(TEXT("/api/player.php?action=register"), TEXT("POST"), B,
        [this](TSharedPtr<FJsonObject> R) { ApplyPlayer(R); },
        /*bNeedPlayer=*/false);
}

void UMlcClient::Login(const FString& Name)
{
    TSharedPtr<FJsonObject> B = MakeShared<FJsonObject>();
    B->SetStringField(TEXT("name"), Name);
    Request(TEXT("/api/player.php?action=login"), TEXT("POST"), B,
        [this](TSharedPtr<FJsonObject> R) { ApplyPlayer(R); },
        /*bNeedPlayer=*/false);
}

void UMlcClient::Logout()
{
    if (PlayerToken.IsEmpty()) return;
    Request(TEXT("/api/player.php?action=logout"), TEXT("POST"), nullptr,
        [](TSharedPtr<FJsonObject>) {});
    PlayerToken.Empty();
    PlayerId = 0;
}

// ---------------- rooms ----------------

void UMlcClient::CreateRoom(const FString& Name, int32 MaxPlayers,
                            const FString& Password)
{
    TSharedPtr<FJsonObject> B = MakeShared<FJsonObject>();
    B->SetStringField(TEXT("name"), Name);
    B->SetNumberField(TEXT("max_players"), MaxPlayers);
    if (!Password.IsEmpty()) B->SetStringField(TEXT("password"), Password);
    Request(TEXT("/api/room.php?action=create"), TEXT("POST"), B,
        [](TSharedPtr<FJsonObject>) {});
}

void UMlcClient::JoinRoom(const FString& RoomIdOrToken, const FString& Password)
{
    TSharedPtr<FJsonObject> B = MakeShared<FJsonObject>();
    B->SetStringField(TEXT("room"), RoomIdOrToken);
    B->SetStringField(TEXT("join_token"), RoomIdOrToken);
    if (!Password.IsEmpty()) B->SetStringField(TEXT("password"), Password);
    Request(TEXT("/api/room.php?action=join"), TEXT("POST"), B,
        [](TSharedPtr<FJsonObject>) {});
}

void UMlcClient::LeaveRoom()
{
    Request(TEXT("/api/room.php?action=leave"), TEXT("POST"), nullptr,
        [](TSharedPtr<FJsonObject>) {});
}

// ---------------- gameplay ----------------

void UMlcClient::Do(const FString& Action, const FString& PayloadJson)
{
    TSharedPtr<FJsonObject> B = MakeShared<FJsonObject>();
    B->SetStringField(TEXT("action"), Action);
    B->SetStringField(TEXT("payload_json"), PayloadJson);
    LogicCall(TEXT("do"), B);
}

void UMlcClient::Send(const FString& Message, const FString& PayloadJson)
{
    TSharedPtr<FJsonObject> B = MakeShared<FJsonObject>();
    B->SetStringField(TEXT("message"), Message);
    B->SetStringField(TEXT("payload_json"), PayloadJson);
    LogicCall(TEXT("message"), B);
}

void UMlcClient::GetState()
{
    Request(TEXT("/api/state.php"), TEXT("GET"), nullptr,
        [this](TSharedPtr<FJsonObject> R)
    {
        FString DataJson;
        TSharedRef<TJsonWriter<>> W = TJsonWriterFactory<>::Create(&DataJson);
        FJsonSerializer::Serialize(R.ToSharedRef(), W);
        OnMlcUpdate.Broadcast(TEXT("state"), DataJson);
    });
}

// ---------------- updates ----------------

void UMlcClient::PollUpdates()
{
    Request(TEXT("/api/player.php?action=updates"), TEXT("GET"), nullptr,
        [this](TSharedPtr<FJsonObject> R)
    {
        const TArray<TSharedPtr<FJsonValue>>* Arr;
        if (!R->TryGetArrayField(TEXT("updates"), Arr)) return;
        for (const TSharedPtr<FJsonValue>& V : *Arr)
        {
            const TSharedPtr<FJsonObject>& U = V->AsObject();
            FString Id, Type, DataJson;
            U->TryGetStringField(TEXT("id"), Id);
            U->TryGetStringField(TEXT("type"), Type);
            // Prefer the raw data_json string — parse with JsonObjectConverter.
            if (!U->TryGetStringField(TEXT("data_json"), DataJson))
            {
                const TSharedPtr<FJsonObject>* D;
                if (U->TryGetObjectField(TEXT("data"), D))
                {
                    TSharedRef<TJsonWriter<>> W =
                        TJsonWriterFactory<>::Create(&DataJson);
                    FJsonSerializer::Serialize(D->ToSharedRef(), W);
                }
            }
            if (!Id.IsEmpty() && SeenUpdateIds.Contains(Id)) continue;
            if (!Id.IsEmpty()) SeenUpdateIds.Add(Id);
            OnMlcUpdate.Broadcast(Type, DataJson);
        }
    });
}

void UMlcClient::StartPolling(float IntervalSeconds)
{
    StopPolling();
    PollHandle = FTSTicker::GetCoreTicker().AddTicker(
        FTickerDelegate::CreateLambda([this](float) { PollUpdates(); return true; }),
        FMath::Max(0.25f, IntervalSeconds));
}

void UMlcClient::StopPolling()
{
    if (PollHandle.IsValid())
    {
        FTSTicker::GetCoreTicker().RemoveTicker(PollHandle);
        PollHandle.Reset();
    }
}
