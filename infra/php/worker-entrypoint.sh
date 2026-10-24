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

exec php bin/console messenger:consume "$@" \
    --time-limit="${WORKER_TIME_LIMIT:-3600}" \
    --memory-limit="${WORKER_MEMORY_LIMIT:-384M}" \
    -vv
