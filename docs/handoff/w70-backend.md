# W-70 · backend — journal, rôles, console

## Fait
- Migration `Version20261113000100` (écrite à la main, **appliquée en dev** à la demande du lead) : `app_user.last_login_at TIMESTAMPTZ(6)` ; `activity_event` (CHECK catégorie/type fermés, `split_part(type,'.',1)=category`, target_type, `octet_length(ip_hash)=16`, metadata objet ; index `subject (subject_user_id,id DESC)`, `admin_actor (actor_id,id DESC) WHERE category='admin'`, `(category,type,created_at)`, `ip_hash … WHERE ip_hash IS NOT NULL`, `(created_at)` ; FK actor/subject SET NULL) ; `security_alert` (`subject_key TEXT GENERATED ALWAYS AS (rule||':'||coalesce(user, hex(ip_hash), type||':'||id)) STORED`, `uniq_security_alert_open_subject … WHERE status='open'`, CHECK rule/severity/status/hit_count/sujet, FK user CASCADE, ack SET NULL) ; `announcement` (CHECK level, `ends_at > starts_at`, FK SET NULL). down() = DROP des 3 tables + colonne.
- `App\Activity\ActivityJournal::record()` + `ClientFingerprint` (IPv4 /24, IPv6 /48, HMAC-SHA256 tronqué à 16 octets avec `ACTIVITY_IP_PEPPER`, user agent nettoyé ≤ 200) + liste blanche des métadonnées (`note` seulement sur admin, ≤ 500).
- Événements branchés : inscription, connexion réussie/échouée (`reason`), déconnexion, réutilisation de jeton, mot de passe changé / réinitialisation demandée / terminée, email vérifié, créations/suppressions de piste, publication, commentaire, artiste, signalement + masquage automatique, `upload.rejected` (gestionnaires piste/publication), `abuse.rate_limited` (1/min/bucket). `last_login_at` mis à jour à la connexion.
- `app:admin:grant|revoke <email>`, `PurgeActivityEvents` (03:55 UTC, lots de 5000), garde de rôle sur `suspend` + `admin.report_decided` dans la transaction.
- `ACTIVITY_IP_PEPPER` : `.env` (dev-only), phpunit, `ProductionConfigGuard` (vide/placeholder ou = APP_SECRET → refus de démarrer).
- Tests : `tests/Functional/Admin/ActivityJournalTest.php` (dont scan de toutes les lignes : ni email, ni jeton, ni mot de passe), `tests/Unit/Activity/ClientFingerprintTest.php`, garde prod, contrat des messages.

## Décisions prises
- Écriture différée : les événements non-admin sont gardés en mémoire si une transaction est ouverte, puis écrits à `kernel/console.terminate` et à la fin de chaque message worker ; un échec est journalisé dans les logs puis ignoré. Les événements admin sont insérés tout de suite, et leur échec fait échouer l'action.
- `password_reset_requested` est écrit par le worker, une fois le compte connu, donc sans IP : pas d'oracle d'énumération. `account.email_changed` n'est pas branché, faute de flux de changement d'email dans le code.
- revoke retire seulement ROLE_ADMIN et garde ROLE_MODERATOR. Métadonnées `from`/`to` = rôle le plus élevé. Les admins sont verrouillés (`FOR UPDATE`, triés par id) avant le compte : deux revoke simultanés ne peuvent pas retirer le dernier admin.
- Ordre des contrôles de `suspend` : 404 → 422 type → 422 soi-même → 403 rôle → 422 déjà suspendu.
- Les échecs finaux d'infrastructure (MarkMediaFailedOnFinalFailure) ne produisent pas `upload.rejected`.
- L'index `actor_id` est partiel (admin seulement), comme le prévoit §15. Le SET NULL sur actor et l'effacement §13b par acteur parcourent donc toute la table.

## Contrat d'API modifié ? (oui/non + détail)
- Non pour openapi. Nouveau comportement conforme à l'ADR 013 : le décideur `suspend` renvoie 403 `forbidden` sur un admin, ou sur un modérateur quand l'appelant n'est pas admin.

## Reste à faire / points ouverts
- **media-infra (bloquant pour la prod)** : ajouter `ACTIVITY_IP_PEPPER` dans `infra/php/Dockerfile` l. 101/103 (env factice du warmup), `compose.yaml` (`:?`) et `infra/deploy/gen-env.sh`. Sinon le démarrage en prod est refusé.
- `doctrine:schema:update --dump-sql` propose désormais de supprimer les index `idx_activity_event_*`, `uniq_security_alert_open_subject` et `idx_security_alert_status`, ainsi que la colonne `subject_key`. Ces suppressions sont à ajouter à la liste « ne jamais reprendre » de CLAUDE.md.
- Le worker de dev doit être redémarré pour charger le nouveau code. EvaluateSecurityRules et les alertes relèvent de W-72.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh back` (VERDICT OK, 495 tests) ; `make test c="--no-progress --filter ActivityJournalTest"`
