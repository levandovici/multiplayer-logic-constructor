// Multiplayer Logic Constructor — C++ SDK
// Contract: docs/SDK.md — "you ask, the server decides".
// Dependencies: libcurl (or WinHTTP — see mlc_client.cpp) + nlohmann::json.
//
//   mlc::Client game("https://mlc.michitai.com", "GAME_API_KEY");
//   game.registerPlayer("nova");
//   game.joinRoom("room-id");
//   game.onUpdate([](const mlc::Update& u){ apply(u.type, u.data); });
//   auto r = game.do_("move", {{"x",1},{"y",2}});
//   if (r.rejected()) showReason(r.reason);

#pragma once

#include <atomic>
#include <chrono>
#include <exception>
#include <functional>
#include <memory>
#include <mutex>
#include <string>
#include <thread>
#include <vector>

#include <nlohmann/json.hpp>

namespace mlc {

using json = nlohmann::json;

/// Answer from do_/send. "rejected" is a NORMAL gameplay answer —
/// show reason to the player. HTTP/transport failures throw MlcError.
struct Result {
    bool        success  = false;
    std::string result;          ///< "ok" or "rejected"
    std::string reason;          ///< rejection reason when rejected
    json        response;        ///< whatever the respond block set
    std::string response_json;   ///< same payload, raw string
    int         version  = 0;    ///< published program version
    bool ok()       const { return result == "ok"; }
    bool rejected() const { return result == "rejected"; }
};

/// One server update (send block, join/leave notice, timer…).
struct Update {
    std::string id;
    std::string type;
    json        data;
    std::string data_json;      ///< raw string form (Unity-style parity)
};

struct Room {
    std::string id;
    std::string name;
    int  players      = 0;
    int  max_players  = 0;
    bool has_password = false;
};

/// Transport failure or success:false from the platform.
struct Error : std::exception {
    std::string msg;
    int status = 0;
    explicit Error(std::string m, int s = 0) : msg(std::move(m)), status(s) {}
    const char* what() const noexcept override { return msg.c_str(); }
};

/// The whole platform client. Tokens travel in headers — never URLs.
class Client {
public:
    Client(std::string baseUrl, std::string apiKey);
    ~Client();                                  // stops polling, joins thread

    Client(const Client&)            = delete;
    Client& operator=(const Client&) = delete;

    // ---- players ----
    int  registerPlayer(const std::string& name);   // stores player token
    int  login(const std::string& name);
    json me();
    void heartbeat();
    void logout();                                  // marks offline, clears token

    // ---- rooms ----
    std::string createRoom(const std::string& name, int maxPlayers = 4,
                           const std::string& password = "");
    void        joinRoom(const std::string& roomIdOrToken,
                         const std::string& password = "");
    void        leaveRoom();
    std::vector<Room> listRooms();

    // ---- gameplay ----
    /// THE trigger — fires on_player_action in your published program.
    Result do_(const std::string& action, const json& payload);
    /// Same with a raw JSON string (payload_json — Unity/Unreal parity).
    Result doJson(const std::string& action, const std::string& payloadJson);
    /// Fires on_message — server-validated custom messages.
    Result send(const std::string& message, const json& payload);
    Result sendJson(const std::string& message, const std::string& payloadJson);

    /// Reconnect snapshot — your player_state + room members.
    json   getState();
    /// Published program metadata — trigger table for tooling.
    json   getMeta();

    // ---- updates ----
    std::vector<Update> pollUpdates();

    /// Called for every unseen update from startPolling(). The callback
    /// runs on the poll thread — marshal to your game thread if needed.
    void onUpdate(std::function<void(const Update&)> cb);
    /// Optional: called when a poll fails (network, auth).
    void onError(std::function<void(const Error&)> cb);
    /// Start/stop the ~1.5s background poll thread.
    void startPolling(std::chrono::milliseconds interval = std::chrono::milliseconds(1500));
    void stopPolling();

    const std::string& playerToken() const { return playerToken_; }
    int playerId() const { return playerId_; }

private:
    json call(const std::string& path, const std::string& method,
              const json* body, bool needPlayer = true);
    Result logicCall(const std::string& endpoint, const json& body);
    void   applyPlayer(const json& r);
    std::string httpRequest(const std::string& method, const std::string& url,
                            const std::string& body,
                            const std::vector<std::string>& headers,
                            long* statusOut);

    std::string base_;
    std::string apiKey_;
    std::string playerToken_;
    int         playerId_ = 0;

    std::function<void(const Update&)> updateCb_;
    std::function<void(const Error&)>  errorCb_;
    std::mutex cbMutex_;

    std::atomic<bool> polling_{false};
    std::thread       pollThread_;
    std::vector<std::string> seenUpdateIds_;
    std::mutex              seenMutex_;
};

} // namespace mlc
