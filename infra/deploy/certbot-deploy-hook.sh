#!/bin/sh
# Certbot deploy hook (installed in /etc/letsencrypt/renewal-hooks/deploy/):
# Let's Encrypt keys are root-only, nginx runs as uid 101 -> copy the current
# cert/key to /etc/wave/tls (root:101, 0640) and reload nginx if it runs.
set -eu
LIVE="${RENEWED_LINEAGE:-$(ls -d /etc/letsencrypt/live/*/ 2>/dev/null | grep -v README | head -1)}"
LIVE="${LIVE%/}"
[ -n "$LIVE" ] && [ -f "$LIVE/fullchain.pem" ] || { echo "no certificate found" >&2; exit 1; }
install -m 0644 -o root -g 101 "$LIVE/fullchain.pem" /etc/wave/tls/fullchain.pem
install -m 0640 -o root -g 101 "$LIVE/privkey.pem" /etc/wave/tls/privkey.pem
NGINX="$(docker ps -q --filter label=com.docker.compose.service=nginx --filter label=com.docker.compose.project=wave)"
[ -z "$NGINX" ] || docker exec "$NGINX" nginx -s reload
