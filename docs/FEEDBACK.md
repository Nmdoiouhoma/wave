# User test feedback (backlog)

Items raised by the product owner while testing. The lead routes each one into a phase.

| # | Date | Feedback | Owner | Target |
|---|---|---|---|---|
| FB-01 | 2026-10-26 | **Real email delivery**: emails must reach real inboxes, not only Mailpit. Plug a transactional email provider (e.g. Brevo, Mailgun, Postmark or SMTP relay) through `MAILER_DSN`, with SPF/DKIM/DMARC on the sending domain. Mailpit stays for local dev. | media-infra + backend | Before production (phase 5 / prod checklist) |
| FB-02 | 2026-10-26 | In dev, the "verify your email" banner should point to Mailpit (http://localhost:8027) so local testers are not stuck. | frontend | Phase 2 |
| FB-03 | 2026-10-26 | Transactional emails are in English while the UI is French: translate subjects and bodies. | backend | Phase 2 |
| FB-04 | 2026-10-26 | **MP4 upload**: users must be able to import MP4 as well as MP3. Today the form rejects `.mp4` (`video/mp4`) client-side and the worker rejects files with a video stream. Decision: accept MP4 (audio-only or with video), keep only the audio (video stream discarded), **limit 200 MB for MP4** (MP3/AAC stay at 30 MB). Large files go **directly to object storage via a presigned upload**, not through PHP. | architect → backend, media-infra, frontend | Feedback batch (before phase 2) |
| FB-05 | 2026-10-26 | **Artist creation is empty**: one must be able to add tracks while creating an artist (step after the artist form, multi-file) and afterwards (an "Ajouter des morceaux" action on the artist page, preselecting that artist). | frontend (+ architect if contract needs it) | Feedback batch (before phase 2) |
