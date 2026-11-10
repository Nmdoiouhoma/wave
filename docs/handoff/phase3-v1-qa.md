# Phase 3 vague 1 — revue qa-security (W-30 `3849acd`, W-38 `ca66be5`)

## Fait
- Relu : création/lecture/liste/stream-url/suppression des posts, `/stream/{token}` k=c, VisualSniffer, PostCleanup, TrackProcessor, limiteur, nginx/PHP upload, AppLayout/FullPlayer/guards. Critique : 0 · élevé : 0.
- Test ajouté `PostSecurityTest::testClipTokenOfAnInvisiblePostAnswersLikeADeletedPost` : jeton k=c émis puis post supprimé / masqué / morceau rendu privé / morceau masqué / extraits retirés / auteur suspendu → 404 identique octet pour octet (GET et HEAD). Passe.
- Vérifié OK : 404 identique sur GET/DELETE/stream-url ; reniflage par octets magiques (nom et MIME client ignorés), 413/415 avant toute ligne, visuel brut en zone privée `quarantine/` ; transport Messenger doctrine (dispatch transactionnel) ; compteurs hashtags, signalements, PurgeObjects et cascade depuis la suppression d'un morceau testés.

## Décisions prises (avis demandé, point 1 : décision produit pour l'utilisateur)
- Post de l'auteur sur son morceau privé : pas une fuite au sens de T7/T17 (c'est l'auteur qui publie, la route du morceau reste en 404). Acceptable comme choix explicite, à 3 conditions :
  (a) l'écran de création (W-39) prévient « cet extrait sera visible par tous » ;
  (b) le passage d'un morceau public → privé ne retire PAS les posts de son propriétaire : à dire dans l'interface des réglages du morceau, ou à exclure si l'utilisateur veut que « privé » gagne toujours ;
  (c) la charge utile expose tout le `TrackView` (id, `visibility: private`, playCount, likeCount) via `PostMapper.php:66` : à réduire pour un non-propriétaire si on accepte. À consigner dans SECURITY T7/T17 comme accepté.

## Findings
- MOYEN 1 — Saturation de `/tmp` à l'upload : `/api/v1/posts` accepte 32 Mo (`infra/nginx/snippets/wave-locations.conf:14`, `infra/php/conf.d/zz-wave.ini:10`) alors que le visuel plafonne à 15 Mo ; PHP écrit le corps dans `/tmp` tmpfs 64 Mo (`compose.yaml:92`) avant authentification et limiteur → 2 requêtes simultanées, même anonymes, font échouer tous les uploads. Correctif : 16m sur cette location et `upload_tmp_dir` sur un volume disque.
- MOYEN 2 — Lecteur non modal sans `inert` : `main`, la barre du haut et le mini-lecteur restent dans l'ordre de tabulation sous la feuille (`frontend/src/routes/AppLayout.tsx:224`, `FullPlayer.tsx:63`) → Tab/Maj+Tab mettent le focus sur des éléments cachés (WCAG 2.4.11). Correctif : `inert` sur ces zones quand `fullOpen`, barre d'onglets et rail laissés actifs.
- MOYEN 3 — À exiger en W-32 : une écoute avec `postId` devra vérifier que le post est jouable, pas la visibilité du morceau (sinon cas du point 1 incohérent, `ListenProcessor.php:51` fait `TrackLookup::visible`).
- BAS 1 — Course suppression de morceau / création de post : un post inséré entre le `SELECT … FOR UPDATE` de `PostCleanup.php:50` et le DELETE du morceau part par cascade sans nettoyage (compteur hashtag faux, objet en quarantaine orphelin). Correctif : verrouiller la ligne du morceau en tête de `TrackProcessor::delete`.
- BAS 2 — Interblocage possible : la décrémentation `PostCleanup.php:61` verrouille les hashtags sans ordre, la création les trie (`CreatePostProcessor.php:239`) → 500 rare. Ordonner (`ORDER BY id FOR UPDATE`) avant l'UPDATE.
- BAS 3 — W-31 : un handler qui écrit sa clé après un DELETE du post laisse un objet orphelin ; vérifier la ligne après écriture et purger sinon.
- BAS 4 — `safeNext` (`frontend/src/features/auth/safeNext.ts:3`) laisse passer `/\t/evil.com` (le navigateur retire la tabulation → `//evil.com`) ; `replaceState` lève alors une SecurityError (plantage, pas de redirection ; non vérifié en navigateur). Correctif : `new URL(next, location.origin).origin === location.origin`.
- BAS 5 — Toucher l'onglet de la page courante ne referme pas le lecteur (`AppLayout.tsx:204`, chemin inchangé).

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Point 1 à trancher par l'utilisateur ; MOYEN 1-2 à corriger avant la fin de phase 3.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh back` (VERDICT OK au 10/11 : 382 tests, 62 ignorés, audit OK).
