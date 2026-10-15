// Multiplayer Logic Constructor — C++ SDK implementation (libcurl backend).
// Link with libcurl (WinHTTP alternative at the bottom is left as an
// exercise — swap httpRequest()'s body).

#include "mlc_client.hpp"

#include <curl/curl.h>
#include <algorithm>
#include <set>
#include <stdexcept>

namespace mlc {

namespace {

size_t writeCb(char* ptr, size_t size, size_t nmemb, void* userdata) {
    auto* out = static_cast<std::string*>(userdata);
    out->append(ptr, size * nmemb);
    return size * nmemb;
}

std::string optStr(const json& j, const char* key) {
    if (!j.is_object()) return "";
    auto it = j.find(key);
    return (it != j.end() && it->is_string()) ? it->get<std::string>() : "";
}

int optInt(const json& j, const char* key) {
    if (!j.is_object()) return 0;
    auto it = j.find(key);
    if (it == j.end()) return 0;
    if (it->is_number_integer()) return it->get<int>();
    if (it->is_number())         return static_cast<int>(it->get<double>());
    return 0;
}

bool optBool(const json& j, const char* key) {
    if (!j.is_object()) return false;
    auto it = j.find(key);
    return it != j.end() && it->is_boolean() && it->get<bool>();
}

} // namespace

Client::Client(std::string baseUrl, std::string apiKey)
    : base_(std::move(baseUrl)), apiKey_(std::move(apiKey)) {
    while (!base_.empty() && base_.back() == '/') base_.pop_back();
    curl_global_init(CURL_GLOBAL_DEFAULT);
}

Client::~Client() {
    stopPolling();
}

// ---------------- HTTP ----------------

std::string Client::httpRequest(const std::string& method,
                                const std::string& url,
                                const std::string& body,
                                const std::vector<std::string>& headers,
                                long* statusOut) {
    CURL* curl = curl_easy_init();
    if (!curl) throw Error("curl init failed");

    std::string out;
    struct curl_slist* hdrs = nullptr;
    for (const auto& h : headers) hdrs = curl_slist_append(hdrs, h.c_str());

    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, hdrs);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, writeCb);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &out);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 0L);   // secrets must not
                                                        // ride redirects

    if (method == "POST") {
        curl_easy_setopt(curl, CURLOPT_POST, 1L);
        curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
        curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)body.size());
    } else if (method != "GET") {
        curl_easy_setopt(curl, CURLOPT_CUSTOMREQUEST, method.c_str());
        if (!body.empty()) {
            curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
            curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)body.size());
        }
    }

    CURLcode rc = curl_easy_perform(curl);
    long status = 0;
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &status);
    curl_slist_free_all(hdrs);
    curl_easy_cleanup(curl);

    if (statusOut) *statusOut = status;
    if (rc != CURLE_OK)
        throw Error(std::string("network: ") + curl_easy_strerror(rc));
    return out;
}

json Client::call(const std::string& path, const std::string& method,
                  const json* body, bool needPlayer) {
    if (needPlayer && playerToken_.empty())
        throw Error("call registerPlayer()/login() first");

    std::vector<std::string> headers = {
        "X-Api-Token: " + apiKey_,
        "Content-Type: application/json",
    };
    if (!playerToken_.empty())
        headers.emplace_back("X-Player-Token: " + playerToken_);

    long status = 0;
    std::string text = httpRequest(method, base_ + path,
        body ? body->dump() : std::string(), headers, &status);

    json r;
    try { r = json::parse(text); }
    catch (...) {
        throw Error("bad server response (HTTP " + std::to_string(status) + ")",
                    (int)status);
    }

    if (status >= 400 ||
        (r.is_object() && r.contains("success") && r["success"].is_boolean()
         && !r["success"].get<bool>())) {
        std::string msg = optStr(r, "error");
        if (msg.empty()) msg = "HTTP " + std::to_string(status);
        throw Error(msg, (int)status);
    }
    return r;
}

// ---------------- players ----------------

void Client::applyPlayer(const json& r) {
    if (r.contains("player") && r["player"].is_object()) {
        playerToken_ = optStr(r["player"], "token");
        playerId_    = optInt(r["player"], "id");
    }
    if (playerToken_.empty())
        playerToken_ = optStr(r, "player_token");
}

int Client::registerPlayer(const std::string& name) {
    json body = {{"name", name}};
    applyPlayer(call("/api/player.php?action=register", "POST",
                     &body, /*needPlayer=*/false));
    return playerId_;
}

int Client::login(const std::string& name) {
    json body = {{"name", name}};
    applyPlayer(call("/api/player.php?action=login", "POST",
                     &body, /*needPlayer=*/false));
    return playerId_;
}

json Client::me()        { return call("/api/player.php?action=me", "GET", nullptr); }
void Client::heartbeat() { call("/api/player.php?action=heartbeat", "POST", nullptr); }

void Client::logout() {
    if (playerToken_.empty()) return;
    try { call("/api/player.php?action=logout", "POST", nullptr); }
    catch (...) { /* best effort — token cleared anyway */ }
    playerToken_.clear();
    playerId_ = 0;
}

// ---------------- rooms ----------------

std::string Client::createRoom(const std::string& name, int maxPlayers,
                               const std::string& password) {
    json body = {{"name", name}, {"max_players", maxPlayers}};
    if (!password.empty()) body["password"] = password;
    json r = call("/api/room.php?action=create", "POST", &body);
    if (r.contains("room") && r["room"].is_object())
        return optStr(r["room"], "id");
    return "";
}

void Client::joinRoom(const std::string& roomIdOrToken,
                      const std::string& password) {
    json body = {{"room", roomIdOrToken}, {"join_token", roomIdOrToken}};
    if (!password.empty()) body["password"] = password;
    call("/api/room.php?action=join", "POST", &body);
}

void Client::leaveRoom() { call("/api/room.php?action=leave", "POST", nullptr); }

std::vector<Room> Client::listRooms() {
    json r = call("/api/room.php?action=list", "GET", nullptr);
    std::vector<Room> out;
    if (!r.contains("rooms") || !r["rooms"].is_array()) return out;
    for (const auto& j : r["rooms"]) {
        out.push_back({optStr(j, "id"), optStr(j, "name"),
                       optInt(j, "players"), optInt(j, "max_players"),
                       optBool(j, "has_password")});
    }
    return out;
}

// ---------------- gameplay ----------------

Result Client::logicCall(const std::string& endpoint, const json& body) {
    json r = call("/api/logic.php?action=" + endpoint, "POST", &body);
    Result res;
    res.success       = optBool(r, "success");
    res.result        = optStr(r, "result");
    res.reason        = optStr(r, "reason");
    res.response      = r.value("response", json());
    res.response_json = optStr(r, "response_json");
    res.version       = optInt(r, "version");
    return res;
}

Result Client::do_(const std::string& action, const json& payload) {
    return logicCall("do", {{"action", action}, {"payload", payload}});
}

Result Client::doJson(const std::string& action,
                      const std::string& payloadJson) {
    return logicCall("do", {{"action", action}, {"payload_json", payloadJson}});
}

Result Client::send(const std::string& message, const json& payload) {
    return logicCall("message", {{"message", message}, {"payload", payload}});
}

Result Client::sendJson(const std::string& message,
                        const std::string& payloadJson) {
    return logicCall("message",
        {{"message", message}, {"payload_json", payloadJson}});
}

json Client::getState() { return call("/api/state.php", "GET", nullptr); }
json Client::getMeta()  { return call("/api/logic.php?action=meta", "GET", nullptr); }

// ---------------- updates ----------------

std::vector<Update> Client::pollUpdates() {
    json r = call("/api/player.php?action=updates", "GET", nullptr);
    std::vector<Update> out;
    if (!r.contains("updates") || !r["updates"].is_array()) return out;
    for (const auto& j : r["updates"]) {
        Update u;
        u.id        = optStr(j, "id");
        u.type      = optStr(j, "type");
        u.data      = j.value("data", json());
        u.data_json = optStr(j, "data_json");
        out.push_back(std::move(u));
    }
    return out;
}

void Client::onUpdate(std::function<void(const Update&)> cb) {
    std::lock_guard<std::mutex> lk(cbMutex_);
    updateCb_ = std::move(cb);
}

void Client::onError(std::function<void(const Error&)> cb) {
    std::lock_guard<std::mutex> lk(cbMutex_);
    errorCb_ = std::move(cb);
}

void Client::startPolling(std::chrono::milliseconds interval) {
    if (polling_.exchange(true)) return;   // already running
    pollThread_ = std::thread([this, interval] {
        while (polling_) {
            try {
                for (const Update& u : pollUpdates()) {
                    {
                        std::lock_guard<std::mutex> lk(seenMutex_);
                        if (!u.id.empty() &&
                            std::find(seenUpdateIds_.begin(),
                                      seenUpdateIds_.end(), u.id)
                                != seenUpdateIds_.end())
                            continue;
                        if (!u.id.empty()) seenUpdateIds_.push_back(u.id);
                    }
                    std::function<void(const Update&)> cb;
                    { std::lock_guard<std::mutex> lk(cbMutex_); cb = updateCb_; }
                    if (cb) cb(u);
                }
            } catch (const Error& e) {
                std::function<void(const Error&)> cb;
                { std::lock_guard<std::mutex> lk(cbMutex_); cb = errorCb_; }
                if (cb) cb(e);
            }
            for (long i = 0; i < interval.count() && polling_; i += 50)
                std::this_thread::sleep_for(std::chrono::milliseconds(50));
        }
    });
}

void Client::stopPolling() {
    polling_ = false;
    if (pollThread_.joinable()) pollThread_.join();
}

} // namespace mlc
