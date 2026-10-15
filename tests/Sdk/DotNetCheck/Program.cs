// Compile + smoke check for the shipped C# SDK sources.
// Asserts MiniJSON roundtrips and that MlcClient wires up correctly —
// real HTTP paths are covered by the PHP contract/integration suites.

using System;
using System.Collections.Generic;
using MiniJSON;

namespace MlcSdkCheck
{
    internal static class Program
    {
        static int Main()
        {
            int failed = 0;

            // MiniJSON decode → typed access
            var d = (Dictionary<string, object>)Json.Decode(
                "{\"x\":1,\"name\":\"nova\",\"ok\":true,\"items\":[1,2,3]," +
                "\"nested\":{\"a\":\"b\"}}");
            failed += Check("decode int",    (long)d["x"] == 1);
            failed += Check("decode string", (string)d["name"] == "nova");
            failed += Check("decode bool",   (bool)d["ok"]);
            failed += Check("decode array",  ((List<object>)d["items"]).Count == 3);
            failed += Check("decode nested",
                (string)((Dictionary<string, object>)d["nested"])["a"] == "b");

            // MiniJSON encode → redecode roundtrip
            var src = new Dictionary<string, object> {
                ["x"] = 1, ["y"] = 2, ["msg"] = "hi \"there\"\nline2"
            };
            var round = (Dictionary<string, object>)Json.Decode(Json.Encode(src));
            failed += Check("roundtrip int", (long)round["x"] == 1);
            failed += Check("roundtrip escapes", (string)round["msg"] == "hi \"there\"\nline2");

            // MlcClient constructs and exposes the right API surface
            var c = new Mlc.MlcClient("https://example.test", "key");
            failed += Check("client ctor", c.ApiKey == "key" && c.PlayerToken == null);
            failed += Check("has DoAsync",     typeof(Mlc.MlcClient).GetMethod("DoAsync") != null);
            failed += Check("has DoJsonAsync", typeof(Mlc.MlcClient).GetMethod("DoJsonAsync") != null);
            failed += Check("has SendJsonAsync", typeof(Mlc.MlcClient).GetMethod("SendJsonAsync") != null);
            failed += Check("has StartPollingAsync", typeof(Mlc.MlcClient).GetMethod("StartPollingAsync") != null);
            failed += Check("has OnUpdate",    typeof(Mlc.MlcClient).GetEvent("OnUpdate") != null);

            // DoAsync without a registered player must fail fast, not call out
            try
            {
                c.DoAsync("move", new { x = 1 }).GetAwaiter().GetResult();
                failed += Check("needPlayer guard", false);
            }
            catch (Mlc.MlcException)
            {
                failed += Check("needPlayer guard", true);
            }

            Console.WriteLine(failed == 0 ? "ALL SDK CHECKS PASSED"
                                          : $"{failed} CHECK(S) FAILED");
            return failed == 0 ? 0 : 1;
        }

        static int Check(string name, bool ok)
        {
            Console.WriteLine((ok ? "PASS" : "FAIL") + "  " + name);
            return ok ? 0 : 1;
        }
    }
}
