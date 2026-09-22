#!/usr/bin/env bash
# =============================================================
# Phase 1 — run ALL automated checks (the Phase 1 gate).
# Exit 0 only when everything is green.
#
#   1) PHP syntax check
#   2) Route test (unit + integration + security suites)
#   3) HTTP test (real server via php -S + curl)
#   4) Include/Require check
#   5) Link check
#   6) Structure check
#
# Usage: bash tests/run_all.sh
# =============================================================
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
status=0

step() {
    echo
    echo "============================================================"
    echo "  $1"
    echo "============================================================"
}

step "1) PHP syntax check"
bash tests/audit/check_syntax.sh || status=1

step "2) Route test (unit + integration + security)"
php tests/run.php || status=1

step "3) HTTP test"
bash tests/audit/http_test.sh || status=1

step "4) Include/Require check"
php tests/audit/check_includes.php || status=1

step "5) Link check"
php tests/audit/check_links.php || status=1

step "6) Structure check"
php tests/audit/check_structure.php || status=1

step "7) Apache .htaccess test (optional — skipped when Apache is unavailable)"
bash tests/audit/apache_test.sh || status=1

echo
echo "============================================================"
if [ "$status" -eq 0 ]; then
    echo "  RESULT: ALL GREEN — Phase 1 is tested and approved."
else
    echo "  RESULT: FAILURES PRESENT — fix before Phase 2."
fi
echo "============================================================"
exit "$status"
