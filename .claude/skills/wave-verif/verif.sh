#!/usr/bin/env bash
# Wave quality gate: one line per step, log tail only on failure.
# Usage: verif.sh [front|back|all]   (default: detect from git status)
set -u
cd "$(git rev-parse --show-toplevel)" || exit 2
export NO_COLOR=1
LOG=$(mktemp -d "${TMPDIR:-/tmp}/wave-verif.XXXX")
scope=${1:-auto}
changed=$(git status --porcelain)
has() { [ "$scope" = all ] || [ "$scope" = "$1" ] || { [ "$scope" = auto ] && grep -q " $2" <<<"$changed"; }; }
fail=0
step() { # name, command...
  local name=$1; shift
  if "$@" >"$LOG/$name.log" 2>&1; then
    printf 'OK    %-10s %s\n' "$name" "$(grep -Eo 'OK \([0-9]+ tests?[^)]*\)|Tests: [0-9].*|Tests +[0-9]+ passed[^\n]*|No errors|make audit: OK|[0-9]+ passed' "$LOG/$name.log" | tail -1 | sed 's/\x1b\[[0-9;]*m//g')"
  else
    fail=1; printf 'FAIL  %-10s (log: %s)\n' "$name" "$LOG/$name.log"
    if [ "$name" = audit ]; then
      grep -E '^OK:|^FAIL|\| (Package|Severity|CVE|Affected versions) |Error 1|vulnerabilit' "$LOG/$name.log" | grep -v 'Affected versions' | sed 's/^/      /'
    else
      tail -25 "$LOG/$name.log" | sed 's/^/      /'
    fi
  fi
}
docker info >/dev/null 2>&1 || { echo "FAIL  docker     Docker Desktop arrêté : open -a Docker, puis make up && make init"; exit 1; }
if has back backend/ || has back infra/; then
  step phpstan  docker compose exec -T php php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
  step cs-fixer docker compose exec -T php php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
  step phpunit  make -s test c=--no-progress
  step media    make -s test-media c=--no-progress
  ls backend/migrations/ | grep -q . && git status --porcelain backend/migrations | grep -q '^??' && \
    echo "INFO  migrations nouvelles : relire le SQL → make console c=\"doctrine:migrations:migrate --dry-run -n\""
fi
if has front frontend/; then
  step typecheck sh -c 'cd frontend && npm run -s typecheck'
  step lint      sh -c 'cd frontend && npm run -s lint'
  step check-api sh -c 'cd frontend && npm run -s check:api'
  step vitest    sh -c 'cd frontend && npm test -s -- --reporter=dot'
  step build     sh -c 'cd frontend && npm run -s build'
fi
git status --porcelain docs/openapi.yaml | grep -q . && step openapi npx --yes @redocly/cli lint docs/openapi.yaml
step audit make -s audit
[ $fail -eq 0 ] && echo "VERDICT OK" || echo "VERDICT FAIL"
exit $fail
