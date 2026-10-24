#!/bin/sh
# Fails unless symfony/security-http and symfony/http-kernel are installed at
# >= 7.4.13 (CVE-2026-48489, ADR 006). Runs inside the php container in /app.
set -eu
cd /app
status=0
for pkg in symfony/security-http symfony/http-kernel; do
    v=$(composer show --locked --format=json "$pkg" 2>/dev/null | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo ltrim($j["versions"][0] ?? "", "v");')
    if [ -z "$v" ]; then
        echo "FAIL: $pkg not found in composer.lock" >&2; status=1; continue
    fi
    if php -r 'exit(version_compare($argv[1], "7.4.13", ">=") ? 0 : 1);' "$v"; then
        echo "OK: $pkg $v"
    else
        echo "FAIL: $pkg $v < 7.4.13 (ADR 006)" >&2; status=1
    fi
done
exit $status
