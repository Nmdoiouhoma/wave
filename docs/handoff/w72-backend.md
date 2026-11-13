# W-72 backend : journal admin, alertes de sécurité, annonces

## Fait
- `GET /admin/users/{id}/activity` (sujet OU acteur, filtre `category`, `admin.journal_viewed` à la 1re page seulement), `GET /admin/audit-log` (`actorId`), `GET /admin/alerts` (open par défaut, tri gravité puis `lastSeenAt`, filtres status/severity/rule), `POST /admin/alerts/{id}/acknowledgement` (409 `already_acknowledged`, ligne verrouillée, journalisé avec rule + note), CRUD `/admin/announcements`, `GET /announcements` (≤ 3 actives, `warning` d'abord).
- Message `App\Message\EvaluateSecurityRules{eventId}` (transport `async`) dispatché par `ActivityJournal` pour les 8 types surveillés ; `EvaluateSecurityRulesHandler` applique les 8 règles §16, seuils en constantes dans `App\Activity\SecurityRules`, upsert `ON CONFLICT (subject_key) WHERE status='open'` → `hit_count + 1`.
- Tests : `tests/Functional/Admin/{JournalApi,SecurityRules,AlertApi,AnnouncementApi}Test.php` (un cas par règle : seuil−1 / seuil / hits suivants / après acquittement), `tests/Security/AlertConcurrencySecurityTest.php` (2 workers, 10 événements → 1 ligne ouverte, hit_count 10).
- Migration `Version20261113000400` **appliquée en dev** (pré-approuvée) :
  `CREATE INDEX idx_activity_event_admin ON activity_event (id DESC) WHERE category = 'admin';`
  `CREATE INDEX idx_activity_event_actor_other ON activity_event (actor_id, id DESC) WHERE category <> 'admin' AND actor_id IS DISTINCT FROM subject_user_id;`
- EXPLAIN sur 100 k événements (wave_test, transaction annulée) : page utilisateur = 3 index (subject, admin_actor, actor_other) arrêtés à 51 lignes ; audit-log = Index Only Scan `idx_activity_event_admin` ; par acteur = `idx_activity_event_admin_actor` ; règles par IP = `idx_activity_event_ip_hash` (3 colonnes en Index Cond) ; report_spree = `idx_activity_event_type_created` ; règles par sujet = BitmapAnd subject + created (164 lignes max lues).

## Décisions prises
- Fenêtre d'une règle = [created_at de l'événement − durée, created_at] : un worker en retard donne le même résultat.
- report_spree compté sur l'acteur (le signaleur) ; rate_limit_hammering sur l'utilisateur, ou l'IP avec `subject_user_id IS NULL` si anonyme (même clé que la dédup 1/min) ; token_reuse / privilege_granted sur l'IP si pas d'utilisateur.
- Ordre des erreurs : modérateur → 403 avant toute recherche (vérif explicite dans chaque provider/processor), puis corps 422, puis 404, puis 409. Dates d'annonce : RFC 3339 strict, sinon 422 ; `endsAt > startsAt` vérifié sur les valeurs fusionnées en PATCH.
- Métadonnées journal : `level` pour les annonces (clé `fields` hors liste blanche, abandonnée).

## Contrat d'API modifié ?
Non. `ProblemCode::AlreadyAcknowledged` ajouté côté code (déjà dans openapi).

## Reste à faire / points ouverts
- Lead : ajouter `idx_activity_event_admin` et `idx_activity_event_actor_other` à la liste « jamais DROP » de CLAUDE.md ; ligne `EvaluateSecurityRules` dans le tableau CONVENTIONS §8.
- **Redémarrer `worker-async`** pour qu'il charge le nouveau handler.

## Commande pour vérifier
`make test c="--no-progress --filter '(JournalApiTest|SecurityRulesTest|AlertApiTest|AnnouncementApiTest|AlertConcurrencySecurityTest)'" 2>&1 | tail -20`
