#!/bin/sh
# Fails unless the PHP binary given as $1 (default: php) is >= 8.4.27
# (PHP2Shell CVE-2026-109618/109619, ADR 006).
set -eu
PHP_BIN="${1:-php}"
"$PHP_BIN" -r 'if (PHP_VERSION_ID < 80427) { fwrite(STDERR, "FAIL: PHP ".PHP_VERSION." < 8.4.27 (ADR 006)\n"); exit(1); } echo "OK: PHP ".PHP_VERSION."\n";'
