# ADR 007 — Account lifecycle: verification, reset, deletion

Status: accepted · 2026-10-24 (user decision: all three in v1)

## Decision
- **Tokens**: one `account_token` table for verification (24 h) and reset (1 h); 32 random bytes, only the SHA-256 is stored; single use; newer token voids older ones. One error code for every failure (`account_token_invalid`).
- **Anti-enumeration**: `POST /auth/password-reset` always 202 and only dispatches `SendPasswordResetEmail{email}`; lookup and token issuance happen in the worker, so response content and timing are independent of existence. Raw tokens never sit in the message table. (Register still answers 409 `email_taken`: unavoidable with immediate login; mitigated by 5/h/IP.)
- **Unverified accounts** can log in and consume; producing content (uploads, artists, posts, comments, reports) needs a verified email. This blocks throwaway accounts from uploading and from feeding the 5-report auto-hide.
- **Deletion = hard delete**, not anonymisation (DATA_MODEL §13b). Reasons: GDPR erasure is satisfied without judging what is personal (a voice in audio, a comment, a bio all are); the FK graph is already CASCADE; anonymised tracks/posts would remain under nobody's control (no owner to moderate, edit or withdraw them, D5 permissions undefined). Cost accepted: comment threads lose messages and other users lose posts made from the deleted user's tracks (consistent with track deletion). Aggregate `play_count` is kept.
- **Mail**: Symfony Mailer called from Messenger handlers (`async` transport); Mailpit in dev (media-infra), SMTP via `MAILER_DSN` in prod.

## Consequences
+ No personal data survives deletion except in backups (rotation documented).
− Deletion is synchronous and heavy for prolific users; escape hatch = async `DeleteAccount` message.
