// Multiplayer Logic Constructor — C#/.NET SDK
// Contract: docs/SDK.md — "you ask, the server decides".
// netstandard2.1-compatible: runs on .NET 5+, .NET Core 3.x+, and Unity
// 2021.2+ (add the System.Text.Json NuGet package there).
//
//   var game = new MlcClient("https://mlc.michitai.com", "GAME_API_KEY");
//   await game.RegisterPlayerAsync("nova");
//   await game.JoinRoomAsync("room-id");
//   var r = await game.DoAsync("move", new { x = 1, y = 2 });
//   if (r.Result == "rejected") Console.WriteLine(r.Reason);

using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Mlc
{
    /// <summary>Answer from <see cref="MlcClient.DoAsync"/> / SendAsync.
    /// "rejected" is a NORMAL gameplay answer — show Reason to the player.
    /// An HTTP-level failure throws <see cref="MlcException"/> instead.</summary>
    public sealed class MlcResult
    {
        public bool Success;
        /// <summary>"ok" or "rejected"</summary>
        public string Result = "";
        /// <summary>Plain-English rejection reason when Result == "rejected".</summary>
        public string? Reason;
        /// <summary>Whatever the program's respond block set — parsed.</summary>
        public JsonElement? Response;
        /// <summary>Same payload as a raw JSON string (the Unity/Unreal path).</summary>
        public string? ResponseJson;
        /// <summary>Published program version that executed.</summary>
        public int Version;
        public bool Ok => Result == "ok";
    }

    /// <summary>One server update (a send block, a join/leave notice, a timer…).</summary>
    public sealed class MlcUpdate
    {
        public string Id = "";
        public string Type = "";
        public JsonElement? Data;
        /// <summary>Raw JSON string — parse with JsonUtility.FromJson&lt;T&gt; in Unity,
        /// or JsonElement here.</summary>
        public string? DataJson;
    }

    /// <summary>Open room as returned by ListRoomsAsync.</summary>
    public sealed class MlcRoom
    {
        public string Id = "";
        public string Name = "";
        public int Players;
        public int MaxPlayers;
        public bool HasPassword;
    }

    /// <summary>HTTP/transport failure or success:false from the platform.</summary>
    public sealed class MlcException : Exception
    {
        public int StatusCode;
        public MlcException(string message, int statusCode = 0) : base(message)
        {
            StatusCode = statusCode;
        }
    }

    /// <summary>
    /// The whole platform client: register → join a room → Do() actions →
    /// receive updates. All tokens are sent as headers — never in URLs.
    /// </summary>
    public sealed class MlcClient : IDisposable
    {
        public string BaseUrl { get; }
        public string ApiKey { get; }
        /// <summary>Set after RegisterPlayerAsync/LoginAsync — SDK keeps it for you.</summary>
        public string? PlayerToken { get; private set; }
        public int PlayerId { get; private set; }
        /// <summary>Raised by StartPollingAsync for every new server update.</summary>
        public event Action<MlcUpdate>? OnUpdate;
        /// <summary>Raised when a poll/update fails — decide whether to retry or bail.</summary>
        public event Action<Exception>? OnPollError;

        private readonly HttpClient _http;
        private readonly bool _ownsHttp;
        private readonly HashSet<string> _seenUpdateIds = new HashSet<string>();

        public MlcClient(string baseUrl, string apiKey, HttpClient? http = null)
        {
            BaseUrl = baseUrl.TrimEnd('/');
            ApiKey = apiKey;
            _ownsHttp = http == null;
            _http = http ?? new HttpClient();
        }

        // ---------------- players ----------------

        /// <summary>Creates (or re-registers) a player by name. Stores the
        /// returned player token on this client.</summary>
        public async Task<int> RegisterPlayerAsync(string name)
        {
            JsonElement r = await CallAsync("/api/player.php?action=register",
                HttpMethod.Post, new { name }, needPlayer: false);
            ApplyPlayer(r);
            return PlayerId;
        }

        /// <summary>Re-login for a previously registered player name.</summary>
        public async Task<int> LoginAsync(string name)
        {
            JsonElement r = await CallAsync("/api/player.php?action=login",
                HttpMethod.Post, new { name }, needPlayer: false);
            ApplyPlayer(r);
            return PlayerId;
        }

        public Task<JsonElement> MeAsync()
            => CallAsync("/api/player.php?action=me", HttpMethod.Get);

        public Task HeartbeatAsync()
            => CallVoidAsync("/api/player.php?action=heartbeat", HttpMethod.Post);

        /// <summary>Marks the player offline and clears the stored token.</summary>
        public async Task LogoutAsync()
        {
            if (PlayerToken == null) return;
            try { await CallVoidAsync("/api/player.php?action=logout", HttpMethod.Post); }
            finally { PlayerToken = null; PlayerId = 0; }
        }

        // ---------------- rooms ----------------

        /// <summary>Creates a room and fires on_room_created. Returns room id.</summary>
        public async Task<string> CreateRoomAsync(string name, int maxPlayers = 4,
            string? password = null)
        {
            JsonElement r = await CallAsync("/api/room.php?action=create",
                HttpMethod.Post, new { name, max_players = maxPlayers, password });
            return r.TryGetProperty("room", out JsonElement room)
                && room.TryGetProperty("id", out JsonElement id)
                ? id.GetString() ?? ""
                : "";
        }

        /// <summary>Joins by room id, or by join_token when the room is private.</summary>
        public Task JoinRoomAsync(string roomIdOrToken, string? password = null)
            => CallVoidAsync("/api/room.php?action=join", HttpMethod.Post,
                new { room = roomIdOrToken, join_token = roomIdOrToken, password });

        public Task LeaveRoomAsync()
            => CallVoidAsync("/api/room.php?action=leave", HttpMethod.Post);

        public async Task<IReadOnlyList<MlcRoom>> ListRoomsAsync()
        {
            JsonElement r = await CallAsync("/api/room.php?action=list", HttpMethod.Get);
            var rooms = new List<MlcRoom>();
            if (!r.TryGetProperty("rooms", out JsonElement arr)) return rooms;
            foreach (JsonElement j in arr.EnumerateArray())
            {
                rooms.Add(new MlcRoom
                {
                    Id = Str(j, "id"), Name = Str(j, "name"),
                    Players = Int(j, "players"), MaxPlayers = Int(j, "max_players"),
                    HasPassword = Bool(j, "has_password"),
                });
            }
            return rooms;
        }

        // ---------------- gameplay ----------------

        /// <summary>THE trigger. Fires on_player_action in your published
        /// program; the server validates and owns all state. Args serialize
        /// to a JSON object (System.Text.Json) — in Unity prefer DoJsonAsync
        /// with a raw JSON string or a [Serializable] class.</summary>
        public Task<MlcResult> DoAsync(string action, object? args = null)
            => LogicCallAsync("do", new Dictionary<string, object?>
            {
                ["action"] = action,
                ["payload"] = args ?? new Dictionary<string, object?>(),
            });

        /// <summary>Same as DoAsync but the payload is a pre-serialized JSON
        /// string — sent as payload_json (the Unity/Unreal convention).</summary>
        public Task<MlcResult> DoJsonAsync(string action, string payloadJson)
            => LogicCallAsync("do", new Dictionary<string, object?>
            {
                ["action"] = action,
                ["payload_json"] = payloadJson,
            });

        /// <summary>Fires on_message — server-validated custom messages
        /// (chat, votes, emotes…).</summary>
        public Task<MlcResult> SendAsync(string message, object? data = null)
            => LogicCallAsync("message", new Dictionary<string, object?>
            {
                ["message"] = message,
                ["payload"] = data ?? new Dictionary<string, object?>(),
            });

        /// <summary>SendAsync with a pre-serialized JSON payload string —
        /// the Unity/Unreal path (payload_json).</summary>
        public Task<MlcResult> SendJsonAsync(string message, string payloadJson)
            => LogicCallAsync("message", new Dictionary<string, object?>
            {
                ["message"] = message,
                ["payload_json"] = payloadJson,
            });

        /// <summary>Reconnect snapshot — your player_state + room members.
        /// Never leaks hidden state.</summary>
        public Task<JsonElement> GetStateAsync()
            => CallAsync("/api/state.php", HttpMethod.Get);

        /// <summary>Published program metadata — trigger table for tooling.</summary>
        public Task<JsonElement> GetMetaAsync()
            => CallAsync("/api/logic.php?action=meta", HttpMethod.Get);

        // ---------------- updates ----------------

        /// <summary>Polls pending player_updates once. New updates are also
        /// delivered through OnUpdate when StartPollingAsync is running.</summary>
        public async Task<IReadOnlyList<MlcUpdate>> GetUpdatesAsync()
        {
            JsonElement r = await CallAsync("/api/player.php?action=updates",
                HttpMethod.Get);
            var list = new List<MlcUpdate>();
            if (!r.TryGetProperty("updates", out JsonElement arr)) return list;
            foreach (JsonElement j in arr.EnumerateArray())
            {
                list.Add(new MlcUpdate
                {
                    Id = Str(j, "id"), Type = Str(j, "type"),
                    DataJson = StrOrNull(j, "data_json"),
                    Data = TryProp(j, "data"),
                });
            }
            return list;
        }

        /// <summary>Background poll loop (~1–2s). Each unseen update raises
        /// OnUpdate. Runs until cancelled — call from your game lifetime.</summary>
        public async Task StartPollingAsync(TimeSpan? interval = null,
            CancellationToken ct = default)
        {
            TimeSpan every = interval ?? TimeSpan.FromSeconds(1.5);
            while (!ct.IsCancellationRequested)
            {
                try
                {
                    foreach (MlcUpdate u in await GetUpdatesAsync())
                    {
                        if (u.Id != "" && !_seenUpdateIds.Add(u.Id)) continue;
                        OnUpdate?.Invoke(u);
                    }
                }
                catch (Exception ex) { OnPollError?.Invoke(ex); }
                try { await Task.Delay(every, ct); }
                catch (TaskCanceledException) { return; }
            }
        }

        // ---------------- plumbing ----------------

        private async Task<MlcResult> LogicCallAsync(string endpoint,
            Dictionary<string, object?> body)
        {
            JsonElement r = await CallAsync("/api/logic.php?action=" + endpoint,
                HttpMethod.Post, body);
            return new MlcResult
            {
                Success = Bool(r, "success"),
                Result = Str(r, "result"),
                Reason = StrOrNull(r, "reason"),
                Response = TryProp(r, "response"),
                ResponseJson = StrOrNull(r, "response_json"),
                Version = Int(r, "version"),
            };
        }

        private void ApplyPlayer(JsonElement r)
        {
            if (r.TryGetProperty("player", out JsonElement p))
            {
                PlayerToken = StrOrNull(p, "token");
                PlayerId = Int(p, "id");
            }
            if (PlayerToken == null && r.TryGetProperty("player_token", out JsonElement t))
            {
                PlayerToken = t.GetString();
            }
        }

        private async Task CallVoidAsync(string path, HttpMethod method,
            object? body = null)
        {
            await CallAsync(path, method, body);
        }

        private async Task<JsonElement> CallAsync(string path, HttpMethod method,
            object? body = null, bool needPlayer = true)
        {
            using var req = new HttpRequestMessage(method, BaseUrl + path);
            req.Headers.TryAddWithoutValidation("X-Api-Token", ApiKey);
            if (PlayerToken != null)
            {
                req.Headers.TryAddWithoutValidation("X-Player-Token", PlayerToken);
            }
            else if (needPlayer)
            {
                throw new MlcException("RegisterPlayerAsync/LoginAsync first.");
            }
            if (body != null)
            {
                req.Content = new StringContent(JsonSerializer.Serialize(body),
                    Encoding.UTF8, "application/json");
            }

            HttpResponseMessage res;
            try { res = await _http.SendAsync(req).ConfigureAwait(false); }
            catch (HttpRequestException ex)
            {
                throw new MlcException("Network error: " + ex.Message);
            }

            string text = await res.Content.ReadAsStringAsync().ConfigureAwait(false);
            JsonDocument doc;
            try { doc = JsonDocument.Parse(text); }
            catch (JsonException)
            {
                throw new MlcException("Bad server response (HTTP "
                    + (int)res.StatusCode + ")", (int)res.StatusCode);
            }

            using (doc)
            {
                JsonElement root = doc.RootElement;
                if (!res.IsSuccessStatusCode ||
                    (root.TryGetProperty("success", out JsonElement s) &&
                     s.ValueKind == JsonValueKind.False))
                {
                    string msg = StrOrNull(root, "error") ?? ("HTTP " + (int)res.StatusCode);
                    throw new MlcException(msg, (int)res.StatusCode);
                }
                return root.Clone();
            }
        }

        private static string Str(JsonElement e, string name)
            => StrOrNull(e, name) ?? "";

        private static string? StrOrNull(JsonElement e, string name)
            => e.ValueKind == JsonValueKind.Object
                && e.TryGetProperty(name, out JsonElement v)
                && v.ValueKind == JsonValueKind.String
                ? v.GetString() : null;

        private static JsonElement? TryProp(JsonElement e, string name)
            => e.ValueKind == JsonValueKind.Object
                && e.TryGetProperty(name, out JsonElement v)
                && v.ValueKind != JsonValueKind.Null && v.ValueKind != JsonValueKind.Undefined
                ? v.Clone() : (JsonElement?)null;

        private static int Int(JsonElement e, string name)
            => e.ValueKind == JsonValueKind.Object
                && e.TryGetProperty(name, out JsonElement v)
                && v.ValueKind == JsonValueKind.Number && v.TryGetInt32(out int i)
                ? i : 0;

        private static bool Bool(JsonElement e, string name)
            => e.ValueKind == JsonValueKind.Object
                && e.TryGetProperty(name, out JsonElement v)
                && v.ValueKind == JsonValueKind.True;

        public void Dispose()
        {
            if (_ownsHttp) _http.Dispose();
        }
    }
}
