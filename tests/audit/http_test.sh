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
AUTH_COOKIE=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    [ -n "$AUTH_COOKIE" ] && rm -f "$AUTH_COOKIE"
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
check "GET /admin/"                 302 "$B/admin/"
check "GET /login"                  200 "$B/login" "ورود به حساب کاربری"
check "GET /logout (must be POST)"   405 "$B/logout"
check "GET /%2e%2e/config"          404 "$B/%2e%2e/config"
check "GET /../../etc/passwd"       404 "$B/%2e%2e/%2e%2e/etc/passwd"

# Phase 6 — knowledge & multimedia routes. The listings degrade to their
# empty states when the database is unreachable (same contract as /);
# the 404 behaviour of detail slugs needs a database and is covered by
# the integration suites (KnowledgeRoutesTest) against the sandbox schema.
check "GET /books"                  200 "$B/books" "کتاب‌ها"
check "GET /lessons"                200 "$B/lessons" "درس‌ها"
check "GET /research"               200 "$B/research" "پژوهش‌ها"
check "GET /media"                  200 "$B/media" "مرکز رسانه"
check "GET /media?type=video"       200 "$B/media?type=video"
check "GET /media?type=script"      200 "$B/media?type=script"
check "GET /sitemap.xml (knowledge)" 200 "$B/sitemap.xml" "/news"

# Authentication state-changing requests must carry a CSRF token. A request
# without one is rejected before any database lookup.
raw="$(curl -s -X POST -w $'\n%{http_code}' "$B/logout")"
code="$(printf '%s' "$raw" | tail -n1)"
if [ "$code" = "403" ]; then
    echo "[OK]   POST /logout without CSRF (HTTP ${code})"
else
    echo "[FAIL] POST /logout without CSRF — expected 403, got $code"
    status=1
fi

# POST needs a flag for curl — re-run the two POST cases explicitly
raw="$(curl -s -X POST -w $'\n%{http_code}' "$B/")"
code="$(printf '%s' "$raw" | tail -n1)"
if [ "$code" = "405" ]; then
    echo "[OK]   POST / (real) (HTTP 405)"
else
    echo "[FAIL] POST / (real) — expected 405, got $code"
    status=1
fi

# An end-to-end valid login requires a deliberately supplied development
# fixture. Nothing is seeded or hard-coded in Git. Set both variables when a
# local MySQL/MariaDB user is available to exercise login persistence/logout.
if [ -n "${AUTH_TEST_EMAIL:-}" ] && [ -n "${AUTH_TEST_PASSWORD:-}" ]; then
    AUTH_COOKIE="$(mktemp)"
    login_body="$(curl -sS -c "$AUTH_COOKIE" "$B/login")"
    csrf="$(printf '%s' "$login_body" | sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' | head -n1)"
    raw="$(curl -sS -b "$AUTH_COOKIE" -c "$AUTH_COOKIE" -X POST \
        --data-urlencode "_csrf_token=$csrf" \
        --data-urlencode "email=$AUTH_TEST_EMAIL" \
        --data-urlencode "password=$AUTH_TEST_PASSWORD" \
        --data-urlencode "redirect=/" \
        -w $'\n%{http_code}' "$B/login")"
    code="$(printf '%s' "$raw" | tail -n1)"
    if [ "$code" = "303" ]; then
        echo "[OK]   valid login (HTTP ${code})"
    else
        echo "[FAIL] valid login — expected 303, got $code"
        status=1
    fi

    home="$(curl -sS -b "$AUTH_COOKIE" "$B/")"
    logout_csrf="$(printf '%s' "$home" | sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' | head -n1)"
    raw="$(curl -sS -b "$AUTH_COOKIE" -c "$AUTH_COOKIE" -X POST \
        --data-urlencode "_csrf_token=$logout_csrf" \
        -w $'\n%{http_code}' "$B/logout")"
    code="$(printf '%s' "$raw" | tail -n1)"
    if [ "$code" = "303" ]; then
        echo "[OK]   authenticated logout (HTTP ${code})"
    else
        echo "[FAIL] authenticated logout — expected 303, got $code"
        status=1
    fi
else
    echo "[SKIP] valid login/logout HTTP flow (set AUTH_TEST_EMAIL and AUTH_TEST_PASSWORD for a local fixture)"
fi

echo
if [ "$status" -eq 0 ]; then
    echo "HTTP test: ALL OK"
else
    echo "HTTP test: FAILURES FOUND"
fi
exit "$status"
