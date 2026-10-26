# User test feedback (backlog)

Items raised by the product owner while testing. The lead routes each one into a phase.

| # | Date | Feedback | Owner | Target |
|---|---|---|---|---|
| FB-01 | 2026-10-26 | **Real email delivery**: emails must reach real inboxes, not only Mailpit. Plug a transactional email provider (e.g. Brevo, Mailgun, Postmark or SMTP relay) through `MAILER_DSN`, with SPF/DKIM/DMARC on the sending domain. Mailpit stays for local dev. | media-infra + backend | Before production (phase 5 / prod checklist) |
| FB-02 | 2026-10-26 | In dev, the "verify your email" banner should point to Mailpit (http://localhost:8027) so local testers are not stuck. | frontend | Phase 2 |
| FB-03 | 2026-10-26 | Transactional emails are in English while the UI is French: translate subjects and bodies. | backend | Phase 2 |
