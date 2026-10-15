// Multiplayer Logic Constructor — Unity SDK
// Wraps MlcClient (MlcClient.cs, in this zip) in a MonoBehaviour:
// polls updates on an interval and raises UnityEvents you can wire in
// the inspector — no coroutines or async needed in your scripts.
//
//   public MlcClientBehaviour client;          // drag in the inspector
//   void Start() {
//       client.OnUpdate.AddListener(u => Debug.Log(u.Type + " " + u.DataJson));
//       client.OnDoResult.AddListener(r => { if (r.Result=="rejected") Warn(r.Reason); });
//   }
//   client.Do("move", "{\"x\":1,\"y\":2}");      // raw JSON — JsonUtility-safe
//
// REMEMBER: Unity's JsonUtility can't parse free-form objects, so incoming
// payloads arrive as STRINGS (DataJson / ResponseJson). Parse them with
// JsonUtility.FromJson<YourClass>(s) or MiniJSON.Json.Decode(s).

using System;
using System.Collections.Concurrent;
using System.Threading;
using System.Threading.Tasks;
using UnityEngine;
using UnityEngine.Events;

namespace Mlc
{
    /// <summary>Fired for every new server update. Parse DataJson with
    /// JsonUtility.FromJson&lt;T&gt; or MiniJSON.</summary>
    [Serializable] public class MlcUpdateEvent : UnityEvent<MlcUpdate> { }
    /// <summary>Fired when a Do/Send call finishes — check Result for
    /// "rejected" (a normal answer) and Reason.</summary>
    [Serializable] public class MlcResultEvent : UnityEvent<MlcResult> { }
    /// <summary>Fired on transport failures (offline, bad key, HTTP 500).</summary>
    [Serializable] public class MlcErrorEvent : UnityEvent<string> { }

    [AddComponentMenu("MLC/Mlc Client Behaviour")]
    public class MlcClientBehaviour : MonoBehaviour
    {
        [Tooltip("Platform base URL")]
        public string baseUrl = "https://mlc.michitai.com";
        [Tooltip("Game API key — the PUBLIC one from your cabinet")]
        public string apiKey = "";
        [Tooltip("Seconds between update polls")]
        [Range(0.25f, 10f)] public float pollInterval = 1.5f;
        [Tooltip("Start the update poller automatically on Start()")]
        public bool autoPoll = true;

        public MlcUpdateEvent OnUpdate = new MlcUpdateEvent();
        public MlcResultEvent OnDoResult = new MlcResultEvent();
        public MlcErrorEvent OnError = new MlcErrorEvent();

        /// <summary>The underlying client — full async API if you need it.</summary>
        public MlcClient Client { get; private set; }

        private readonly ConcurrentQueue<MlcUpdate> _updates = new ConcurrentQueue<MlcUpdate>();
        private readonly ConcurrentQueue<MlcResult> _results = new ConcurrentQueue<MlcResult>();
        private readonly ConcurrentQueue<Exception> _errors = new ConcurrentQueue<Exception>();
        private CancellationTokenSource _pollCts;

        void Awake()
        {
            Client = new MlcClient(baseUrl, apiKey);
            // SDK callbacks arrive on a thread-pool thread — hop to the main
            // thread via queues drained in Update(), since Unity API calls
            // are main-thread only.
            Client.OnUpdate += u => _updates.Enqueue(u);
            Client.OnPollError += e => _errors.Enqueue(e);
        }

        void Start()
        {
            if (autoPoll) StartPolling();
        }

        void Update()
        {
            while (_updates.TryDequeue(out var u)) OnUpdate.Invoke(u);
            while (_results.TryDequeue(out var r)) OnDoResult.Invoke(r);
            while (_errors.TryDequeue(out var e)) OnError.Invoke(e.Message);
        }

        /// <summary>Start the background update poller (idempotent).</summary>
        public void StartPolling()
        {
            if (_pollCts != null) return;
            _pollCts = new CancellationTokenSource();
            _ = Client.StartPollingAsync(TimeSpan.FromSeconds(pollInterval), _pollCts.Token);
        }

        public void StopPolling() => _pollCts?.Cancel();

        // ---- fire-and-forget helpers (callable from Buttons/UI) ----

        /// <summary>THE gameplay trigger. payloadJson is a raw JSON STRING —
        /// build it with JsonUtility.ToJson(args) or MiniJSON — because
        /// JsonUtility can't nest objects inside an envelope.</summary>
        public void Do(string action, string payloadJson)
            => _ = Safe(() => Client.DoJsonAsync(action, payloadJson));

        /// <summary>Typed-args convenience — JsonUtility-serializable classes
        /// only (fields must be [Serializable]-safe, no Dictionaries).</summary>
        public void Do<T>(string action, T args)
            => Do(action, JsonUtility.ToJson(args));

        public void Send(string message, string payloadJson)
            => _ = Safe(() => Client.SendJsonAsync(message, payloadJson));

        public void RegisterPlayer(string name)
            => _ = Safe(async () => { await Client.RegisterPlayerAsync(name); return true; });

        public void Login(string name)
            => _ = Safe(async () => { await Client.LoginAsync(name); return true; });

        public void CreateRoom(string name, int maxPlayers, string password = "")
            => _ = Safe(async () => { await Client.CreateRoomAsync(name, maxPlayers, password == "" ? null : password); return true; });

        public void JoinRoom(string roomIdOrToken, string password = "")
            => _ = Safe(async () => { await Client.JoinRoomAsync(roomIdOrToken, password == "" ? null : password); return true; });

        public void LeaveRoom() => _ = Safe(() => Client.LeaveRoomAsync().ContinueWith(_ => true));
        public void Heartbeat() => _ = Safe(() => Client.HeartbeatAsync().ContinueWith(_ => true));
        public void Logout()    => _ = Safe(() => Client.LogoutAsync().ContinueWith(_ => true));

        private async Task Safe(Func<Task<object>> call)
        {
            try
            {
                object r = await call();
                if (r is MlcResult mr) _results.Enqueue(mr);
            }
            catch (Exception ex) { _errors.Enqueue(ex); }
        }

        private async Task Safe(Func<Task<MlcResult>> call)
        {
            try { _results.Enqueue(await call()); }
            catch (Exception ex) { _errors.Enqueue(ex); }
        }

        void OnDestroy()
        {
            StopPolling();
            if (Client != null && Client.PlayerToken != null)
            {
                try { Client.LogoutAsync().Wait(1000); } catch { /* shutting down */ }
                Client.Dispose();
            }
        }
    }
}
