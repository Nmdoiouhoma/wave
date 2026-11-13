# ADR 013 — Admin back-office in the app, activity journal in Postgres, log server later (FB-28)

Status: proposed (3 points to confirm by the user, see end) · 2026-11-13 · Lot `admin-contract`, before phase 4, after FB-27. Absorbs W-50.

## Context
FB-28 asks for an admin account and back-office: users (search, suspend, roles), content and reports moderation, broadcast announcements, suspicious-activity alerts, a per-user activity journal, and monitoring. Today only the W-33 moderation API exists (`/admin/report-targets*`, ROLE_MODERATOR), with three known gaps: no unsuspend, a moderator can suspend an admin (W-37 open point), no way to create the first admin. Production is one EC2 x86_64 with **1 GB of memory** running nginx, PHP-FPM, two workers, Postgres and storage.

## Decision
1. **Two layers, built in this order.** (a) *Business back-office inside the app*: React pages under `/admin` on the existing API, data in Postgres (users, content, reports, alerts, announcements, activity journal). (b) *Technical log server, later and separate*: container logs, nginx access logs, PHP errors, host metrics. (a) answers "who did what in Wave"; (b) answers "is the machine healthy". They never share storage; (b) never holds business data and (a) never holds raw request logs.
2. **Roles** (DATA_MODEL §1 `Role`, hierarchy ADMIN ⊃ MODERATOR ⊃ USER, roles read from the database on every request, so a change applies at the next request):

   | Capability | Moderator | Admin |
   |---|---|---|
   | Dashboard counters | moderation counters only (`alerts` null) | all |
   | Reports queue + decisions (W-33), content list, hide / restore | yes | yes |
   | Users list / detail | yes, `email` null | yes, with email |
   | Suspend / unsuspend | plain users only | users and moderators, never an admin |
   | Change role (user ↔ moderator) | no | yes, never self, never an admin |
   | Delete content (irreversible) | no | yes |
   | Activity journal, alerts, announcements | no | yes |

   A moderator acting on a moderator or an admin, or an admin acting on an admin → **403 `forbidden`** (the target is visible, not mutable). This also applies to the `suspend` action of the W-33 decision endpoint (closes the W-37 point).
3. **ROLE_ADMIN is never granted through the API.** First and later admins: console `bin/console app:admin:grant <email>` / `app:admin:revoke <email>` run on the server (SSH). The API can only move a user between `ROLE_USER` and `ROLE_MODERATOR`. Every grant/revoke is journaled (actor null = console) and raises a `privilege_granted` alert, so a silent admin creation is visible. Rationale: a stolen admin session cannot mint more admins, and there is no bootstrap endpoint to protect.
4. **Activity journal = table `activity_event` in Postgres** (DATA_MODEL §15), written by the backend only: security-relevant events (logins, failures, token reuse, password and email changes, registrations), content creation/deletion, reports, upload rejections, rate-limit hits, and **every admin action** (including opening a user's journal). Not journaled: likes, follows, listens, views (already in their own tables, too voluminous). Writes are synchronous in the transaction of the action for admin actions (no action without its audit row), and through a fire-and-forget insert outside the business transaction for the rest (a failed journal write never fails a login).
5. **Privacy (minimal data).** No raw IP, no email, no request body in the journal. IP stored twice: `ip_prefix` (IPv4 /24, IPv6 /48, for display) and `ip_hash` = first 16 bytes of HMAC-SHA256(full IP, `ACTIVITY_IP_PEPPER`) to correlate accounts sharing an address; user agent truncated to 200 chars. `metadata` holds ids and enum values only, never free text written by users. **Retention**: ordinary events 90 days, admin events (`admin.*`) 1 year, alerts 180 days after acknowledgement; purged daily by the scheduler. Account deletion (§13b) deletes the user's own events and alerts about them; admin events about the user keep their row with the target set to null.
6. **Alerts = table `security_alert`** (DATA_MODEL §16), raised by an async detector (`EvaluateSecurityRules` message dispatched after each journaled event of a watched type) that counts recent events with indexed queries. Fixed rules in one PHP class with thresholds as constants (no rule editor in v1): `login_failures`, `token_reuse`, `mass_registration`, `report_burst`, `report_spree`, `upload_abuse`, `rate_limit_hammering`, `privilege_granted`. One open alert per (rule, subject): a new hit bumps `hit_count` / `last_seen_at` instead of creating a row. No email/push in v1: the dashboard shows the open count.
7. **Announcements = table `announcement`** (DATA_MODEL §17): an in-app banner shown to every signed-in user while `starts_at ≤ now < ends_at`. No per-user notification rows (fan-out over all users is too costly on 1 GB); dismissal is client-side, keyed by id + `updatedAt`.
8. **Every `/admin` route** keeps the existing guard (non-moderator → 403 before any lookup) and uses cursor pagination (ADR 002). Admin-only routes answer 403 to a moderator, also before any lookup. Admin routes address users by **id**, not username (suspended users stay reachable there, unlike `/users/{username}`).
9. **Log server (later, ticket W-78).** Sizing: Loki (monolithic, filesystem storage) ~150-250 MB, Grafana ~150-200 MB, a log agent (Grafana Alloy or Promtail) ~60-100 MB. That does not fit beside the app on 1 GB (Postgres + FPM + workers already need most of it). Recommendation: a **second small instance** (1 GB x86_64 is enough for Loki + Grafana with 14-day retention, swap 1 GB, ~10 GB disk) reachable only from the app instance and over SSH tunnel for Grafana; on the app box only the agent, capped at 96 MB (`mem_limit`). Lighter fallback if a second instance is refused: Dozzle (live Docker logs, ~20 MB, no retention) + logrotate. Independently of the log server, the app instance itself needs 2 GB or 2 GB of swap before the first deploy (état-projet, step 2).

## Consequences
+ One stack, one permission model, no extra service before phase 4; the journal and alerts are queryable with SQL and covered by PHPUnit.
+ The W-37 hole (moderator suspends an admin) and the missing unsuspend are closed by contract.
+ No admin bootstrap endpoint; promoting to admin requires server access.
− Journal writes add one insert to login/refresh/report/upload paths (small; batched purge keeps the table at ~90 days).
− Thresholds are code constants: changing one needs a deploy.
− The keyed IP hash is pseudonymous, still personal data: covered by the 90-day retention and listed in the privacy notice (README prod checklist).
− Without the log server, PHP 500s and container crashes are only visible in `docker compose logs` until W-78.

## Points to confirm by the user
1. Admin role only through the console command, never through the API (recommended).
2. Log server on a second small instance with Loki + Grafana (recommended) vs Dozzle only on the app box.
3. Retention 90 days (events) / 1 year (admin actions) / 180 days (acknowledged alerts) (recommended).
