# W-26 correctifs — backend (F10-F16, contrat 741829c)

## Fait
- F10 `PostgresSearchProvider` : échappement LIKE fait en SQL après `wave_unaccent` (`chr(92)`, pas de `\` dans le SQL pour le parseur DBAL).
- F11 `PlaylistRepository::detachTrackEverywhere` : `SELECT … ORDER BY p.id FOR UPDATE` des playlists touchées avant le décalage.
- F12 `Http/RequestEncodingListener` (kernel.request, prio 256) : NUL ou UTF-8 invalide dans chemin/query → 400 `malformed_request` ; `JsonInputDecoder::hydrate` : chaîne avec NUL → 422 `validation_failed` (code `Charset::BAD_ENCODING_ERROR`) ; `SessionIssuer` : jeton de renouvellement avec NUL/UTF-8 invalide = absent (401).
- F13 (ADR 010) : entité `ListenGrant` + migration `Version20261107000100` (non appliquée hors base de test), `ListenGrantRepository` (upsert dans `TrackStreamUrlProvider`, `consume` = `DELETE … RETURNING`), `ListenRules` (fenêtre = durée entière, `grantFresh`, `elapsedOk`, `DAILY_CAP` 20), `ListenRecorder`, commande `wave:listen-grants:purge` planifiée 03:50 UTC via `RunCommandMessage` (pas de nouveau message Messenger).
- F15 : `ProblemCode::PlaylistLimitReached`, 200 playlists/utilisateur sous `FOR UPDATE` de app_user ; limiteur `playlist_write_user` 600/h sur createPlaylist + addPlaylistItem ; `itemsPage` filtre en SQL les items invisibles pour un non-propriétaire ; `ReorderPlaylistInput` `Type('list')` → 422.
- F16 : `UserLookup::byUsername` → 404 si suspendu, `includingSuspended()` pour l'unfollow ; `FollowRepository::page` joint `app_user.suspended_at IS NULL`.
- F14 : unlike et unfollow sans contrôle de visibilité (voir écart ci-dessous).
- Audit : Symfony 7.4.22 → 7.4.23 (35 paquets, `composer update "symfony/*" -W` ; entraîne aussi php-cs-fixer 3.95.29 et ergebnis/agent-detector 2.0.0), corrige CVE-2026-118513 à 118516 ; `conflict` et `ComposerConstraintsTest` passent de `<7.4.13` à `<7.4.23` ; `make audit` → exit=0. Le seuil 7.4.13 reste dans `infra/scripts/check-symfony-security.sh` et dans le Makefile (hors périmètre, à remonter). Le tmpfs /tmp du conteneur php (64 Mo) est plein à 98 % de `wave-test-*` laissés par les tests : PHPStan n'y écrit plus, d'où l'usage de `TMPDIR=/app/var/tmp`.
- Fuite `/tmp/wave-test-*` : les helpers d'upload `tempnam` de `TrackTest`, `MeTest` (5 Mo pour le 413) et `ArtistTest` ne supprimaient rien → `WaveApiTestCase::uploadTempFile()`, supprimé en tearDown (nom distinct du `tempFile()` de `SecurityTestCase`) ; 0 dossier après une suite complète.
- F17 : verrou du titre avant ceux des playlists des deux côtés. La suppression fait `FOR UPDATE` sur `track` dans `detachTrackEverywhere`, l'ajout `FOR KEY SHARE` via `PlaylistRepository::shareTrack()` avant `lock()` (titre disparu → 404). La violation de clé étrangère résiduelle devient un 404 dans `PlaylistItemProcessor`. Les 2 tests qa passent sans modification.
- Tests : nouveau `tests/Functional/Social/SecurityFixesTest.php` (F14, F15 objet/limite/items, F16) ; `ListenTest` (sans grant, écoulé, grant périmé, plafond 20/24 h) ; `ListenRulesTest` ; adaptés au contrat : `PlaylistTest` (item omis), `SocialSecurityTest::testUnlikeStillWorks…` (assertait l'ancien 404), `ConcurrencySecurityTest` listen (grant planté).

## Décisions prises
- **Écart de contrat à trancher (F14 likes)** : le contrat dit « 404 seulement si la cible n'existe pas ». Appliqué tel quel, `DELETE …/like` devient un oracle d'existence des playlists et titres privés, et casse T17 (`testInvisiblePlaylistAnswersLikeAMissingOneEverywhere`, 404 identique sur 9 routes). Implémenté : mon like est supprimé quelle que soit la visibilité (200, compteur décrémenté) ; sans like de ma part, une cible invisible → 404 comme une inconnue. L'unfollow suit le contrat à la lettre (un suspendu n'était pas secret).
- `listen_grant` garde les index Doctrine (user_id, track_id), alors que DATA_MODEL dit « aucun index hors PK » ; celui sur track_id sert la cascade à la suppression d'un titre.

## Contrat d'API modifié ? (oui/non + détail)
- Non au-delà de 741829c, sauf l'écart F14 ci-dessus (DELETE like sans like existant sur cible invisible → 404).

## Reste à faire / points ouverts
- Lead : relire et appliquer la migration en dev (`make init`). Tant qu'elle n'est pas appliquée, `stream-url` renvoie 500 en dev (table absente).
- Après un changement d'injection de dépendances, supprimer `var/cache/test` (`rm -rf`) : les workers de `ConcurrencySecurityTest` démarrent un kernel sans debug et gardent l'ancien conteneur (`cache:clear` ne suffit pas).
- qa : mettre à jour SECURITY.md (T17-T21, F10-F16). Front : `playlist_limit_reached`.

## Commande pour vérifier
- `make test c=--no-progress` → 333 tests, OK (62 skipped), dont les 130 de `tests/Security` (F17 compris). PHPStan 0 erreur, CS 0 fichier.

## Annexe — SQL de Version20261107000100 (up)
```sql
CREATE TABLE listen_grant (issued_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, track_id UUID NOT NULL, PRIMARY KEY (user_id, track_id));
CREATE INDEX IDX_7C58510DA76ED395 ON listen_grant (user_id);
CREATE INDEX IDX_7C58510D5ED23C43 ON listen_grant (track_id);
ALTER TABLE listen_grant ADD CONSTRAINT FK_7C58510DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE listen_grant ADD CONSTRAINT FK_7C58510D5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE;
-- down: DROP TABLE listen_grant;
```
