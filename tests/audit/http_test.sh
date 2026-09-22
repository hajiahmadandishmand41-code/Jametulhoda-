#!/usr/bin/env bash
# =============================================================
# Phase 1 — Check 3: HTTP tests against the real front controller
# using PHP's built-in server (same code path as Apache + .htaccess,
# minus Apache-specific directives — see docs/ROUTES.md).
#
# Usage: bash tests/audit/http_test.sh   (option: PORT=9090 ...)
# =============================================================
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${PORT:-8123}"
LOG="$(mktemp)"
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -f "$LOG"
}
trap cleanup EXIT

php -S "127.0.0.1:${PORT}" -t "$ROOT" "$ROOT/index.php" >"$LOG" 2>&1 &
SERVER_PID=$!

# Wait until the server answers (max ~10s)
up=0
for _ in $(seq 1 50); do
    if curl -s -o /dev/null "http://127.0.0.1:${PORT}/"; then
        up=1
        break
    fi
    sleep 0.2
done
if [ "$up" -ne 1 ]; then
    echo "FATAL: built-in server did not start"
    cat "$LOG"
    exit 2
fi

status=0
B="http://127.0.0.1:${PORT}"

check() {
    # check <label> <expected_code> <url> [body_needle]
    local label="$1" want="$2" url="$3" needle="${4:-}"
    local raw code body
    raw="$(curl -s -w $'\n%{http_code}' "$url")"
    code="$(printf '%s' "$raw" | tail -n1)"
    body="$(printf '%s' "$raw" | sed '$d')"
    if [ "$code" = "$want" ] && { [ -z "$needle" ] || printf '%s' "$body" | grep -qF -- "$needle"; }; then
        echo "[OK]   ${label} (HTTP ${code})"
    else
        echo "[FAIL] ${label} — expected ${want}, got ${code}"
        [ -n "$needle" ] && printf '%s' "$body" | head -c 300 && echo
        status=1
    fi
}

echo "== HTTP tests (php -S, port ${PORT}) =="

check "GET /"                       200 "$B/" "dir=\"rtl\""
check "GET / (home link target)"    200 "$B/index.php" "dir=\"rtl\""
check "GET // (double slash)"       200 "$B//" "dir=\"rtl\""
check "GET /assets/css/main.css"    200 "$B/assets/css/main.css" ":root"
check "GET /assets/js/main.js"      200 "$B/assets/js/main.js" "main.js"
check "GET unknown path"            404 "$B/definitely-missing" "صفحه پیدا نشد"
check "GET /config/config.php"      404 "$B/config/config.php"
check "GET /app/Router.php"         404 "$B/app/Router.php"
check "GET /pages/home.php"         404 "$B/pages/home.php"
check "GET /tests/run.php"          404 "$B/tests/run.php"
check "GET /logs/error.log"         404 "$B/logs/error.log"
check "GET /database/schema.sql"    404 "$B/database/schema.sql"
check "GET /admin/"                 404 "$B/admin/"
check "GET /%2e%2e/config"          404 "$B/%2e%2e/config"
check "GET /../../etc/passwd"       404 "$B/%2e%2e/%2e%2e/etc/passwd"

# POST needs a flag for curl — re-run the two POST cases explicitly
raw="$(curl -s -X POST -w $'\n%{http_code}' "$B/")"
code="$(printf '%s' "$raw" | tail -n1)"
if [ "$code" = "405" ]; then
    echo "[OK]   POST / (real) (HTTP 405)"
else
    echo "[FAIL] POST / (real) — expected 405, got $code"
    status=1
fi

echo
if [ "$status" -eq 0 ]; then
    echo "HTTP test: ALL OK"
else
    echo "HTTP test: FAILURES FOUND"
fi
exit "$status"
