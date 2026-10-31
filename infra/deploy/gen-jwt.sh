#!/bin/sh
# Generates the LexikJWT keypair once (RSA 4096, private key encrypted with
# JWT_PASSPHRASE from .env) into $WAVE_JWT_DIR (default /srv/wave/jwt), which
# compose.small.yaml mounts read-only into php. Idempotent (keeps existing keys).
# Usage (in /srv/wave, as root or with sudo):  sh infra/deploy/gen-jwt.sh
set -eu
DIR="${WAVE_JWT_DIR:-/srv/wave/jwt}"
PASS="$(grep '^JWT_PASSPHRASE=' .env | cut -d= -f2-)"
[ -n "$PASS" ] || { echo "JWT_PASSPHRASE missing in .env" >&2; exit 1; }
if [ -s "$DIR/private.pem" ] && [ -s "$DIR/public.pem" ]; then
    echo "keys already present in $DIR"
    exit 0
fi
mkdir -p "$DIR"
umask 077
printf '%s' "$PASS" | openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes256 -pass stdin -out "$DIR/private.pem"
printf '%s' "$PASS" | openssl pkey -in "$DIR/private.pem" -passin stdin -pubout -out "$DIR/public.pem"
chmod 0400 "$DIR/private.pem"; chmod 0444 "$DIR/public.pem"; chmod 0750 "$DIR"
# php-fpm runs as www-data (uid 33) in the image.
if [ "$(id -u)" -eq 0 ]; then chown -R 33:33 "$DIR"; fi
echo "JWT keypair written to $DIR"
