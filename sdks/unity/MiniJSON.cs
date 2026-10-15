// MiniJSON — compact JSON decoder/encoder for Unity (public domain style,
// ~200 lines, no dependencies). Unity's JsonUtility can't handle free-form
// JSON; this decodes to Dictionary<string,object> / List<object> so game
// code can read the *_json fields without typed classes.
//
//   var d = (Dictionary<string,object>)MiniJSON.Json.Decode(u.DataJson);
//   string j = MiniJSON.Json.Encode(new Dictionary<string,object>{{"x",1}});

#nullable enable

using System;
using System.Collections;
using System.Collections.Generic;
using System.Globalization;
using System.Text;

namespace MiniJSON
{
    public static class Json
    {
        public static object? Decode(string? json)
        {
            if (json == null) return null;
            int pos = 0;
            return ParseValue(json, ref pos);
        }

        public static string Encode(object? obj)
        {
            var sb = new StringBuilder();
            WriteValue(obj, sb);
            return sb.ToString();
        }

        // ---------------- decode ----------------

        static object? ParseValue(string s, ref int p)
        {
            SkipWs(s, ref p);
            if (p >= s.Length) return null;
            switch (s[p])
            {
                case '{': return ParseObject(s, ref p);
                case '[': return ParseArray(s, ref p);
                case '"': return ParseString(s, ref p);
                case 't': p += 4; return true;          // true
                case 'f': p += 5; return false;         // false
                case 'n': p += 4; return null;          // null
                default:  return ParseNumber(s, ref p);
            }
        }

        static Dictionary<string, object?> ParseObject(string s, ref int p)
        {
            var d = new Dictionary<string, object?>();
            p++; // {
            SkipWs(s, ref p);
            if (p < s.Length && s[p] == '}') { p++; return d; }
            while (p < s.Length)
            {
                string key = ParseString(s, ref p);
                SkipWs(s, ref p);
                if (p < s.Length && s[p] == ':') p++;
                d[key] = ParseValue(s, ref p);
                SkipWs(s, ref p);
                if (p < s.Length && s[p] == ',') { p++; continue; }
                if (p < s.Length && s[p] == '}') { p++; break; }
                break;
            }
            return d;
        }

        static List<object?> ParseArray(string s, ref int p)
        {
            var l = new List<object?>();
            p++; // [
            SkipWs(s, ref p);
            if (p < s.Length && s[p] == ']') { p++; return l; }
            while (p < s.Length)
            {
                l.Add(ParseValue(s, ref p));
                SkipWs(s, ref p);
                if (p < s.Length && s[p] == ',') { p++; continue; }
                if (p < s.Length && s[p] == ']') { p++; break; }
                break;
            }
            return l;
        }

        static string ParseString(string s, ref int p)
        {
            var sb = new StringBuilder();
            if (p < s.Length && s[p] == '"') p++;
            while (p < s.Length)
            {
                char c = s[p++];
                if (c == '"') break;
                if (c == '\\' && p < s.Length)
                {
                    char e = s[p++];
                    switch (e)
                    {
                        case '"':  sb.Append('"');  break;
                        case '\\': sb.Append('\\'); break;
                        case '/':  sb.Append('/');  break;
                        case 'b':  sb.Append('\b'); break;
                        case 'f':  sb.Append('\f'); break;
                        case 'n':  sb.Append('\n'); break;
                        case 'r':  sb.Append('\r'); break;
                        case 't':  sb.Append('\t'); break;
                        case 'u':
                            if (p + 4 <= s.Length)
                            {
                                sb.Append((char)Convert.ToInt32(s.Substring(p, 4), 16));
                                p += 4;
                            }
                            break;
                    }
                }
                else sb.Append(c);
            }
            return sb.ToString();
        }

        static object ParseNumber(string s, ref int p)
        {
            int start = p;
            if (p < s.Length && (s[p] == '-' || s[p] == '+')) p++;
            while (p < s.Length && (char.IsDigit(s[p]) || s[p] == '.' ||
                   s[p] == 'e' || s[p] == 'E' || s[p] == '+' || s[p] == '-'))
                p++;
            string num = s.Substring(start, p - start);
            if (num.IndexOf('.') >= 0 || num.IndexOf('e') >= 0 || num.IndexOf('E') >= 0)
                return double.Parse(num, CultureInfo.InvariantCulture);
            if (long.TryParse(num, NumberStyles.Integer, CultureInfo.InvariantCulture, out long l))
                return l;
            return double.Parse(num, CultureInfo.InvariantCulture);
        }

        static void SkipWs(string s, ref int p)
        {
            while (p < s.Length && char.IsWhiteSpace(s[p])) p++;
        }

        // ---------------- encode ----------------

        static void WriteValue(object? v, StringBuilder sb)
        {
            switch (v)
            {
                case null:        sb.Append("null"); return;
                case bool b:      sb.Append(b ? "true" : "false"); return;
                case string s:    WriteString(s, sb); return;
                case IDictionary d:
                    sb.Append('{');
                    bool first = true;
                    foreach (DictionaryEntry kv in d)
                    {
                        if (!first) sb.Append(',');
                        first = false;
                        WriteString(Convert.ToString(kv.Key, CultureInfo.InvariantCulture) ?? "", sb);
                        sb.Append(':');
                        WriteValue(kv.Value, sb);
                    }
                    sb.Append('}');
                    return;
                case IEnumerable e:
                    sb.Append('[');
                    bool f2 = true;
                    foreach (object item in e)
                    {
                        if (!f2) sb.Append(',');
                        f2 = false;
                        WriteValue(item, sb);
                    }
                    sb.Append(']');
                    return;
                default:
                    if (v is double || v is float || v is decimal)
                        sb.Append(Convert.ToDouble(v, CultureInfo.InvariantCulture)
                            .ToString("R", CultureInfo.InvariantCulture));
                    else
                        sb.Append(Convert.ToString(v, CultureInfo.InvariantCulture));
                    return;
            }
        }

        static void WriteString(string s, StringBuilder sb)
        {
            sb.Append('"');
            foreach (char c in s)
            {
                switch (c)
                {
                    case '"':  sb.Append("\\\""); break;
                    case '\\': sb.Append("\\\\"); break;
                    case '\b': sb.Append("\\b");  break;
                    case '\f': sb.Append("\\f");  break;
                    case '\n': sb.Append("\\n");  break;
                    case '\r': sb.Append("\\r");  break;
                    case '\t': sb.Append("\\t");  break;
                    default:
                        if (c < ' ')
                            sb.Append("\\u").Append(((int)c).ToString("x4"));
                        else
                            sb.Append(c);
                        break;
                }
            }
            sb.Append('"');
        }
    }
}
