#!/bin/sh
# Wave worker entrypoint: messenger:consume on the given transports.
# Usage: worker-entrypoint <transport> [<transport>...]
# Waits for the Symfony app (bin/console + vendor) so the stack can start
# before the backend skeleton exists, instead of crash-looping.
set -eu

cd /app
while [ ! -f bin/console ] || [ ! -f vendor/autoload_runtime.php ]; do
    echo "worker: waiting for /app/bin/console and vendor/ (run 'make composer c=install')" >&2
    sleep 15
done

# Leftovers of a killed job (OOM, SIGKILL): no job lasts 6 h.
if [ -n "${MEDIA_TMP_DIR:-}" ] && [ -d "$MEDIA_TMP_DIR" ]; then
    find "$MEDIA_TMP_DIR" -maxdepth 1 -type f -name 'wave-*' -mmin +360 -delete 2>/dev/null || true
fi

exec php bin/console messenger:consume "$@" \
    --time-limit="${WORKER_TIME_LIMIT:-3600}" \
    --memory-limit="${WORKER_MEMORY_LIMIT:-384M}" \
    -vv
