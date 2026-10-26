# ADR 006 — PHP/Symfony minimum versions and FPM hardening

Status: accepted (D1) · 2026-10-24

## Context
PHP2Shell (CVE-2026-109618/109619) is fixed in PHP 8.4.27; CVE-2026-48489 (Symfony firewall bypass) in Symfony 7.4.13. Floating tags (`php:8.4-fpm`) can silently roll back or forward.

## Decision
**Versions**
- Base image pinned by exact version **and digest**: `FROM php:8.4.27-fpm-bookworm@sha256:<digest>` (php and worker share it; worker adds ffmpeg). Bumps are explicit commits.
- `composer.json`: `"php": ">=8.4.27"`, `"config.platform.php": "8.4.27"`, `"extra.symfony.require": "7.4.*"`, and `"conflict": {"symfony/security-http": "<7.4.13", "symfony/security-bundle": "<7.4.13", "symfony/http-kernel": "<7.4.13"}`.
- `make audit` = `composer audit` + `npm audit --audit-level=high` + `docker compose exec php php -r 'exit(PHP_VERSION_ID >= 80427 ? 0 : 1);'` + `composer show symfony/security-http` ≥ 7.4.13. Non-zero exit on any failure.

**FPM / PHP ini (php container)**
- Pool: `php_admin_value[session.upload_progress.enabled] = 0`, `php_admin_value[post_max_size] = 32M`, `php_admin_value[upload_max_filesize] = 30M`, `php_admin_value[max_file_uploads] = 2`, `php_admin_flag[expose_php] = off`, `php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec`, `php_admin_flag[allow_url_include] = off`, `pm = dynamic` with `pm.max_children` sized to the memory limit (~ 64 MB/child), `request_terminate_timeout = 60s`.
- ini: `memory_limit=256M`, `max_execution_time=30`, `opcache.validate_timestamps=0` in prod, `session.use_strict_mode=1` (sessions unused by the API anyway).
- Workers (php-cli) keep `proc_open` (ffmpeg via Symfony Process), `memory_limit=512M`, no FPM, no published port. **Two consumers** (accepted amendment, 2026-10-24): `worker` = `media` transport, 2 replicas × 1G (ffmpeg bound); `worker-async` = `async` + scheduler, 1 replica × 512M (scheduler must be single-instance; emails/purges no longer queue behind a 10-min loudnorm).

**Containers / nginx**
- Non-root users, `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, read-only root FS where possible (tmpfs `/tmp`), memory limits: php 512M, worker 1G (×2), worker-async 512M, nginx 128M, postgres 1G, minio 512M, front 256M.
- Only nginx publishes a port. postgres, minio, php, worker on an internal network.
- nginx `client_max_body_size 32m` on `POST /api/v1/posts` (track audio no longer transits PHP, ADR 009; `/upload/` = 201m, POST only), `6m` on avatar/cover routes, `1m` elsewhere; `server_tokens off`; security headers (CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy`).

**Production**
- TLS termination is mandatory (at nginx or the load balancer) with HSTS (`Strict-Transport-Security: max-age=31536000; includeSubDomains`); plain HTTP only redirects.
- `REFRESH_COOKIE_SECURE=1` is enforced when `APP_ENV=prod` (container boot fails otherwise).
- Object storage in prod: see ADR 003 (no official MinIO image).

## Consequences
+ A vulnerable runtime cannot be built or pass `make audit`.
− Digest pin needs a manual bump for every PHP patch release (tracked by qa-security).
