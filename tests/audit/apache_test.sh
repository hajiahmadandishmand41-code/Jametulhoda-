#!/usr/bin/env bash
# =============================================================
# Phase 1 — Optional: .htaccess verification on a real Apache
# (Apache 2.4 + mod_rewrite + mod_headers — the shared-hosting target).
#
# Runs only when an Apache serving this site is reachable at
# APACHE_URL (default http://127.0.0.1:8088). When Apache is not
# available the check is SKIPPED (exit 0) — the php -S HTTP test
# (http_test.sh) already covers the application behaviour.
#
# Usage: bash tests/audit/apache_test.sh   (option: APACHE_URL=...)
# =============================================================
set -u

APACHE_URL="${APACHE_URL:-http://127.0.0.1:8088}"
status=0

if ! curl -s -o /dev/null --max-time 3 "$APACHE_URL/"; then
    echo "Apache not reachable at ${APACHE_URL} — check skipped (php -S HTTP test covers app behaviour)."
    exit 0
fi

B="$APACHE_URL"

check_code() {
    # check_code <label> <expected_code> <path> [body_needle]
    local label="$1" want="$2" path="$3" needle="${4:-}"
    local raw code body
    raw="$(curl -s -w $'\n%{http_code}' "$B$path")"
    code="$(printf '%s' "$raw" | tail -n1)"
    body="$(printf '%s' "$raw" | sed '$d')"
    if [ "$code" = "$want" ] && { [ -z "$needle" ] || printf '%s' "$body" | grep -qF -- "$needle"; }; then
        echo "[OK]   ${label} (HTTP ${code})"
    else
        echo "[FAIL] ${label} — expected ${want}, got ${code}"
        status=1
    fi
}

check_header() {
    # check_header <label> <header_name> <expected_value_substring>
    local label="$1" name="$2" want="$3" actual
    actual="$(curl -sI "$B/" | tr -d '\r' | grep -i "^${name}:" | head -n1 | cut -d' ' -f2-)"
    if printf '%s' "$actual" | grep -qF -- "$want"; then
        echo "[OK]   ${label} (${name}: ${actual})"
    else
        echo "[FAIL] ${label} — header '${name}' missing or wrong (got: ${actual:-<empty>})"
        status=1
    fi
}

echo "== Apache .htaccess tests (${B}) =="

# --- Front controller + pages ---
check_code "GET /"                    200 "/" "dir=\"rtl\""
check_code "GET // (double slash)"    200 "//" "dir=\"rtl\""
check_code "GET /index.php"           200 "/index.php" "dir=\"rtl\""
check_code "GET unknown path"         404 "/no-such-page-xyz" "صفحه پیدا نشد"

# POST needs an explicit -X flag
raw="$(curl -s -X POST -w $'\n%{http_code}' "$B/")"
code="$(printf '%s' "$raw" | tail -n1)"
if [ "$code" = "405" ]; then
    echo "[OK]   POST / (real) (HTTP 405)"
else
    echo "[FAIL] POST / (real) — expected 405, got $code"
    status=1
fi

# --- Static files served directly ---
check_code "GET /assets/css/main.css" 200 "/assets/css/main.css" ":root"
check_code "GET /assets/js/main.js"   200 "/assets/js/main.js" "main.js"

# --- Internal directories: blocked by .htaccess (403) ---
check_code "GET /config/config.php"   403 "/config/config.php"
check_code "GET /config (no slash)"   403 "/config"
check_code "GET /app/Router.php"      403 "/app/Router.php"
check_code "GET /pages/home.php"      403 "/pages/home.php"
check_code "GET /views/layouts/main.php" 403 "/views/layouts/main.php"
check_code "GET /tests/run.php"       403 "/tests/run.php"
check_code "GET /database/schema.sql" 403 "/database/schema.sql"
check_code "GET /logs"                403 "/logs"
check_code "GET /.htaccess"           403 "/.htaccess"
check_code "GET /admin/"              302 "/admin/"
check_code "GET /assets/ (listing)"   403 "/assets/"

# --- Security headers ---
check_header "X-Content-Type-Options"  "X-Content-Type-Options" "nosniff"
check_header "X-Frame-Options"         "X-Frame-Options" "SAMEORIGIN"
check_header "Content-Security-Policy" "Content-Security-Policy" "default-src 'self'"
check_header "Referrer-Policy"         "Referrer-Policy" "strict-origin-when-cross-origin"

echo
if [ "$status" -eq 0 ]; then
    echo "Apache test: ALL OK"
else
    echo "Apache test: FAILURES FOUND"
fi
exit "$status"
