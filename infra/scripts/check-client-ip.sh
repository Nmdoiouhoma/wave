#!/bin/sh
# W-76: the journal must record the real client IP, never a forged X-Forwarded-For.
# Sends a failed login through nginx with a forged XFF, then reads the journal row.
# Expect: ip_prefix is the caller's own /24 (127.0.0.0/24 or the Docker gateway on a
# local run), NOT 203.0.113.0/24. Usage (stack up): sh infra/scripts/check-client-ip.sh [base-url]
set -eu
BASE="${1:-http://localhost:8090}"
MARK="forged-$(date +%s)@example.invalid"
curl -fsS -o /dev/null -w 'login HTTP %{http_code}\n' -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' -H 'X-Forwarded-For: 203.0.113.77' -H 'Forwarded: for=203.0.113.77' \
  -d "{\"email\":\"$MARK\",\"password\":\"x\"}" || true
sleep 1
P="$(docker compose exec -T postgres sh -c 'psql -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "select ip_prefix from activity_event where type = '"'"'auth.login_failed'"'"' order by id desc limit 1"')"
echo "journal ip_prefix: $P"
case "$P" in 203.0.113.*) echo "FAIL: forged X-Forwarded-For was trusted" >&2; exit 1;; "") echo "FAIL: no journal row" >&2; exit 1;; esac
echo "OK: forged header ignored"
