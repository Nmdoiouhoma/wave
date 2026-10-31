#!/bin/sh
# Builds a prod .env from .env.example: public URLs on https://<domain>,
# prod flags, fresh random secrets. Refuses to touch an already configured
# .env (keeps the WAVE_IMAGE_TAG line written by ship.sh).
# Usage (in /srv/wave):  sh infra/deploy/gen-env.sh wave.example.org
set -eu
DOMAIN="${1:?usage: gen-env.sh <domain>}"
if [ -e .env ] && grep -q '^APP_SECRET=' .env; then
    echo ".env already configured, not touching it" >&2
    exit 1
fi
TAGLINE=""
if [ -e .env ]; then
    TAGLINE="$(grep '^WAVE_IMAGE_TAG=' .env || true)"
fi
rnd() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'; }
DBPASS="$(rnd)"
DOMAIN_RE="$(printf '%s' "$DOMAIN" | sed 's/\./\\\\./g')"

sed \
  -e "s|^APP_ENV=.*|APP_ENV=prod|" \
  -e "s|^APP_SECRET=.*|APP_SECRET=$(rnd)|" \
  -e "s|^STREAM_SIGNING_KEY=.*|STREAM_SIGNING_KEY=$(rnd)|" \
  -e "s|^JWT_PASSPHRASE=.*|JWT_PASSPHRASE=$(rnd)|" \
  -e "s|^MINIO_ROOT_PASSWORD=.*|MINIO_ROOT_PASSWORD=$(rnd)|" \
  -e "s|^S3_SECRET_KEY=.*|S3_SECRET_KEY=$(rnd)|" \
  -e "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$DBPASS|" \
  -e "s|wave:wave@postgres|wave:$DBPASS@postgres|g" \
  -e "s|^REFRESH_COOKIE_SECURE=.*|REFRESH_COOKIE_SECURE=1|" \
  -e "s|http://localhost:8090|https://$DOMAIN|g" \
  -e "s|^CORS_ALLOW_ORIGIN=.*|CORS_ALLOW_ORIGIN='^https://$DOMAIN_RE\$'|" \
  -e "s|^VITE_MAILPIT_URL=.*|# VITE_MAILPIT_URL= (dev only)|" \
  -e "s|^MAILER_DSN=.*|MAILER_DSN=smtp://CHANGE-ME-SMTP-RELAY|" \
  -e "s|^MAILER_FROM=.*|MAILER_FROM=\"Wave <no-reply@$DOMAIN>\"|" \
  .env.example > .env.new
[ -z "$TAGLINE" ] || echo "$TAGLINE" >> .env.new
mv .env.new .env
chmod 600 .env
if grep -q '^[^#]*change-me' .env; then
    echo "a change-me value is left in .env" >&2
    exit 1
fi
echo ".env written for https://$DOMAIN — set MAILER_DSN (FB-01) before inviting users"
