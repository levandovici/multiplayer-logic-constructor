#!/usr/bin/env bash
# MLC post-deploy smoke suite (< 2 min).
# Usage: ./tests/Smoke/smoke.sh https://mlc.example.com [api_token] [player_token]
set -u
BASE="${1:?usage: smoke.sh https://host [api_token] [player_token]}"
API_TOKEN="${2:-}"
PLAYER_TOKEN="${3:-}"
FAIL=0

check() { # name expected_status url [extra curl args...]
    local name="$1" want="$2" url="$3"
    shift 3
    local got
    got=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" "$url")
    if [ "$got" = "$want" ]; then echo "PASS $name ($got)"; else echo "FAIL $name (want $want, got $got) $url"; FAIL=1; fi
}

# --- API alive + auth surface ---
check "landing page" 200 "$BASE/"
check "api index" 200 "$BASE/api/"
check "logic meta needs api token" 401 "$BASE/api/logic.php?action=meta"
check "logic do needs tokens" 401 "$BASE/api/logic.php?action=do" -X POST -H 'Content-Type: application/json' -d '{"action":"x"}'
check "state needs tokens" 401 "$BASE/api/state.php"
check "room list needs api token" 401 "$BASE/api/room.php?action=list"
check "blocks needs api token" 401 "$BASE/api/blocks.php"
check "simulate rejects anonymous" 401 "$BASE/api/simulate.php" -X POST -H 'Content-Type: application/json' -d '{}'

# --- nothing outside the docroot leaks ---
for p in /.env /schema.sql /composer.json /database/schema.sql \
         /src/bootstrap.php /src/Engine/Executor.php /cron/tick.php \
         /bin/migrate.php /bin/seed-demo.php /.git/config /logs/php_errors.log; do
    out=$(curl -s -o /dev/null -w '%{http_code} %{content_type}' --max-time 10 "$BASE$p")
    code="${out%% *}"
    ctype="${out#* }"
    if [ "$code" = "404" ] || [ "$code" = "403" ]; then
        echo "PASS hidden $p ($code)"
    elif [ "$code" = "200" ] && [[ "$ctype" == text/html* ]]; then
        echo "PASS hidden $p (200 soft-404 fallback)"
    else
        echo "FAIL exposed $p -> $code $ctype"; FAIL=1
    fi
done

# --- response hygiene ---
HDRS=$(curl -sI --max-time 10 "$BASE/")
echo "--- / response headers ---"; echo "$HDRS"; echo "---------------------------"
echo "$HDRS" | grep -qi "x-powered-by" && { echo "FAIL X-Powered-By present"; FAIL=1; } || echo "PASS no X-Powered-By"
echo "$HDRS" | grep -qi "x-content-type-options: nosniff" && echo "PASS nosniff" || { echo "FAIL nosniff missing"; FAIL=1; }
echo "$HDRS" | grep -qi "strict-transport-security" && echo "PASS HSTS" || { echo "FAIL HSTS missing"; FAIL=1; }
echo "$HDRS" | grep -qi "x-frame-options" && echo "PASS X-Frame-Options" || { echo "FAIL X-Frame-Options missing"; FAIL=1; }
echo "$HDRS" | grep -qi "content-security-policy" && echo "PASS CSP" || { echo "FAIL CSP missing"; FAIL=1; }

# --- session cookie flags ---
COOKIE=$(curl -sI --max-time 10 "$BASE/login.php" | tr -d '\r' | grep -i '^set-cookie:')
if [ -n "$COOKIE" ]; then
    echo "$COOKIE" | grep -qi "httponly" && echo "PASS cookie HttpOnly" || { echo "FAIL cookie missing HttpOnly"; FAIL=1; }
    echo "$COOKIE" | grep -qi "samesite" && echo "PASS cookie SameSite" || { echo "FAIL cookie missing SameSite"; FAIL=1; }
    if [ "${BASE#https}" != "$BASE" ]; then
        echo "$COOKIE" | grep -qi "secure" && echo "PASS cookie Secure" || { echo "FAIL cookie missing Secure"; FAIL=1; }
    fi
else
    echo "SKIP cookie flags (no Set-Cookie on login page)"
fi

# --- cabinet endpoints reject anonymous ---
check "programs.php rejects anonymous" 401 "$BASE/php/programs.php" -X POST -d 'action=save'
check "games.php rejects anonymous" 401 "$BASE/php/games.php" -X POST -d 'action=create'
check "cabinet redirects to login" 302 "$BASE/cabinet.php"

# --- authed paths (only when tokens provided) ---
if [ -n "$API_TOKEN" ]; then
    check "blocks catalogue (authed)" 200 "$BASE/api/blocks.php" -H "X-Api-Token: $API_TOKEN"
    BODY=$(curl -s --max-time 10 -H "X-Api-Token: $API_TOKEN" "$BASE/api/blocks.php")
    echo "$BODY" | grep -q '"statements"' && echo "PASS blocks body" || { echo "FAIL blocks body"; FAIL=1; }
    echo "$BODY" | grep -qiE "warning|fatal error|stack trace" && { echo "FAIL blocks leaks PHP errors"; FAIL=1; } || echo "PASS no error leakage"
else
    echo "SKIP authed API checks (no api_token given)"
fi

if [ -n "$API_TOKEN" ] && [ -n "$PLAYER_TOKEN" ]; then
    check "player me (authed)" 200 "$BASE/api/player.php?action=me" -H "X-Api-Token: $API_TOKEN" -H "X-Player-Token: $PLAYER_TOKEN"
    check "logic do without room 409" 409 "$BASE/api/logic.php?action=do" \
        -X POST -H "X-Api-Token: $API_TOKEN" -H "X-Player-Token: $PLAYER_TOKEN" \
        -H 'Content-Type: application/json' -d '{"action":"noop_test","payload":{}}'
else
    echo "SKIP player-authed checks (no player_token given)"
fi

if [ "$FAIL" = 0 ]; then echo "ALL SMOKE CHECKS PASSED"; else echo "SMOKE FAILURES"; exit 1; fi
