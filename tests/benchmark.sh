#!/bin/sh
# Quick latency check against a running server.
# Usage: sh tests/benchmark.sh http://127.0.0.1:8083 txtgrep
BASE=${1:?usage: benchmark.sh BASE_URL LABEL}
LABEL=${2:-bench}
OUT=$(mktemp)
trap 'rm -f "$OUT"' EXIT

# Edit these to match your corpus. POSIX ARE regex is used by the server.
for keyword in '宋江' '孫悟空 金箍棒' '賈寶玉' '完全不會出現的字串zzzz'; do
    timing=$(curl -fsS -o "$OUT" -w '%{http_code} %{time_total}' \
        -H 'Content-Type: application/json' \
        -d "{\"source\":\"*\",\"keyword\":\"$keyword\"}" \
        "$BASE/grep.php") || exit 1
    case "${timing%% *}" in
        2??) elapsed=${timing#* } ;;
        *) exit 1 ;;
    esac
    rows=$(php -r '$d = json_decode(file_get_contents($argv[1])); if (!is_array($d)) exit(1); echo count($d);' "$OUT") || exit 1
    printf '%-10s %-24s %8ss rows=%s\n' "$LABEL" "$keyword" "$elapsed" "$rows"
done
