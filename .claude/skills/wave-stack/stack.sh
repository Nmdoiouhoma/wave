#!/usr/bin/env bash
# Start or repair the local Wave stack, print a 5-line status.
set -u
cd "$(git rev-parse --show-toplevel)" || exit 2
if ! docker info >/dev/null 2>&1; then
  open -a Docker
  for _ in $(seq 1 45); do docker info >/dev/null 2>&1 && break; sleep 2; done
  docker info >/dev/null 2>&1 || { echo "FAIL Docker Desktop ne démarre pas"; exit 1; }
fi
make -s up >/tmp/wave-up.log 2>&1 || { echo "FAIL make up"; tail -20 /tmp/wave-up.log; exit 1; }
make -s init >/tmp/wave-init.log 2>&1 || { echo "FAIL make init"; tail -20 /tmp/wave-init.log; exit 1; }
bad=$(docker compose ps --format '{{.Service}} {{.State}} {{.Status}}' | grep -v ' running ' || true)
nginx=$(curl -s -m 5 http://localhost:8090/healthz || echo down)
api=$(curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST http://localhost:8090/api/v1/auth/login -H 'Content-Type: application/json' -d '{}')
echo "nginx: ${nginx}  api(login vide, 400 attendu): ${api}"
[ -n "$bad" ] && { echo "Services en défaut :"; echo "$bad"; }
docker compose ps --format '{{.Service}} {{.Status}}' | grep -i restarting && echo "→ worker en boucle : relancer make init"
echo "App http://localhost:8090 · Mailpit http://localhost:8027"
