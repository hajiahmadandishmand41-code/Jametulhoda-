#!/usr/bin/env bash
# =============================================================
# Phase 1 — Check 1: PHP syntax check for every PHP file.
# Usage: bash tests/audit/check_syntax.sh
# =============================================================
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
status=0
count=0

while IFS= read -r -d '' f; do
    count=$((count + 1))
    if ! out="$(php -l "$f" 2>&1)"; then
        echo "[FAIL] $f"
        echo "$out"
        status=1
    else
        echo "[OK]   ${f#"$ROOT"/}"
    fi
done < <(find "$ROOT" -type f -name '*.php' -not -path '*/vendor/*' -print0 | sort -z)

echo
if [ "$status" -eq 0 ]; then
    echo "Syntax check: ALL OK (${count} files)"
else
    echo "Syntax check: FAILURES FOUND"
fi
exit "$status"
