# syntax=docker/dockerfile:1.7
# Wave edge (nginx unprivileged). `nginx` = prod: configs + built SPA baked in.
# `nginx-dev` = configs only (dev bind-mounts them and proxies to Vite).

FROM node:24.22.0-alpine3.24@sha256:44fce696f38ba8c987c4db89954051000bbd05ffd8d8659ba15ed4a8602e937f AS front
ARG VITE_API_BASE_URL=/api/v1
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL} NPM_CONFIG_UPDATE_NOTIFIER=false
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY docs/openapi.yaml /src/docs/openapi.yaml
COPY frontend/ ./
# Dev/test-only assets must never ship: MSW worker and generated mock audio.
RUN npm run build \
    && rm -rf dist/mock-audio dist/mockServiceWorker.js \
    && ! find dist -name 'mockServiceWorker*' -o -name 'mock-audio' | grep -q .

FROM nginxinc/nginx-unprivileged:1.30.6-alpine@sha256:f5116ed4606f3eb015dd4a8e28cb9b351476dc0ad4bc6e9f93b19cec130cdf03 AS nginx-dev
# The base image ships a default server (server_name localhost) that would
# shadow ours for Host: localhost.
USER root
RUN rm -f /etc/nginx/conf.d/default.conf
USER 101
COPY infra/nginx/nginx.conf /etc/nginx/nginx.conf
COPY infra/nginx/conf.d/ /etc/nginx/conf.d/
COPY infra/nginx/snippets/ /etc/nginx/snippets/
COPY infra/nginx/snippets/front-static.conf /etc/nginx/wave/front.conf

FROM nginx-dev AS nginx
COPY --from=front /src/frontend/dist /srv/front
