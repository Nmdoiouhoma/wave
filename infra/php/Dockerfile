# syntax=docker/dockerfile:1.7
#
# Wave PHP images (ADR 006). php (FPM) and worker (CLI + ffmpeg) share the same
# base, pinned by exact version AND digest. Bumping PHP = explicit commit that
# changes the line below (digest of the multi-arch index, from
# `docker buildx imagetools inspect php:<version>-fpm-bookworm`).

FROM composer:2.10.3 AS composer

FROM php:8.4.27-fpm-bookworm@sha256:df5a5113e1179ddf4560cd15727eb3812f6457829756d2a96c6c11bb4c250089 AS base

# Build the extensions, then keep only their runtime libraries.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql zip; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get install -y --no-install-recommends unzip; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qi intl; php -m | grep -qi pdo_pgsql; php -m | grep -qi 'zend opcache'

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY infra/php/conf.d/zz-wave.ini "$PHP_INI_DIR/conf.d/zz-wave.ini"

# Prod default; dev compose sets 1.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app
RUN chown www-data:www-data /app \
    && mkdir -p /tmp/composer /tmp/composer-cache \
    && chown www-data:www-data /tmp/composer /tmp/composer-cache

# ---------------------------------------------------------------------------
FROM base AS php

COPY infra/php/fpm/zz-wave.conf /usr/local/etc/php-fpm.d/zz-wave.conf
RUN php-fpm -t

# FPM master runs unprivileged: no setuid needed, compatible with cap_drop ALL.
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

# ---------------------------------------------------------------------------
FROM base AS worker

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ffmpeg; \
    rm -rf /var/lib/apt/lists/*; \
    ffmpeg -hide_banner -filters | grep -q loudnorm; \
    ffmpeg -hide_banner -encoders | grep -q libwebp

COPY infra/php/conf.d/zz-wave-worker.ini "$PHP_INI_DIR/conf.d/zz-wave-worker.ini"
COPY --chmod=0755 infra/php/worker-entrypoint.sh /usr/local/bin/worker-entrypoint

ENV FFMPEG_BIN=/usr/bin/ffmpeg \
    FFPROBE_BIN=/usr/bin/ffprobe

USER www-data
STOPSIGNAL SIGTERM
ENTRYPOINT ["worker-entrypoint"]
CMD ["media"]
