#!/bin/sh
# Builds the arm64 images on this machine (Apple Silicon = same arch as
# Graviton) and ships them + the deployment files to the server.
# Usage (repo root):  sh infra/deploy/ship.sh deploy@<host>
# Why not build on the server: the live stack already holds ~1.8 GB of limits
# on 2 GB; the front build alone peaks at ~0.6 GB and the worker layer pulls
# ffmpeg. Shipping keeps the box free of toolchains and of OOM risks.
set -eu
HOST="${1:?usage: ship.sh deploy@host}"
TAG="$(git rev-parse --short HEAD)$(git diff --quiet && git diff --cached --quiet || echo -dirty)"
export WAVE_IMAGE_TAG="$TAG"

docker compose -f compose.yaml build php worker nginx
docker image inspect "wave/php:$TAG" --format '{{.Architecture}}' | grep -qx arm64 || { echo "images are not arm64" >&2; exit 1; }

# Only what the server needs at runtime (configs mounted by compose).
rsync -az --relative compose.yaml compose.small.yaml .env.example \
    infra/nginx/tls/ infra/php/fpm/zzz-wave-small.conf infra/minio/init.sh infra/deploy/ \
    "$HOST:/srv/wave/"

# Shared layers are sent once (php and worker share the base).
docker save "wave/php:$TAG" "wave/worker:$TAG" "wave/nginx:$TAG" | gzip -1 | ssh "$HOST" 'gunzip | docker load'
ssh "$HOST" "cd /srv/wave && touch .env && chmod 600 .env && (grep -q '^WAVE_IMAGE_TAG=' .env && sed -i 's/^WAVE_IMAGE_TAG=.*/WAVE_IMAGE_TAG=$TAG/' .env || echo 'WAVE_IMAGE_TAG=$TAG' >> .env)"
echo "shipped $TAG — on the server: docker compose -f compose.yaml -f compose.small.yaml up -d"
