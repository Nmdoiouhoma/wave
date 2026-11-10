# Phase 3 vague 2 — qa-security (W-31, W-39, W-32 ; 2ba14c8..b346595)

## Fait
- Revue du diff + voisins (TrackAudience, PostLookup, LikeRepository, RateLimitListener, CursorCodec, LockedFind, security.yaml). Bilan : **0 critique, 1 élevé, 1 moyen, 5 bas.**
- « Jouable » : les 5 écritures (isPostPlayable, postPlayableSql, postPlayableDql du fil Abonnements, DQL de `findPageByAuthor`, `candidateSql` de W-39) sont équivalentes aujourd'hui. Verrou ajouté : `tests/Security/PostPlayableParityTest.php` (15 cas, chaque clause cassée une fois, spectateur abonné/ami proche et étranger) ; il échoue dès que l'une d'elles diverge.
- Vérifié sans problème : 404 identique à l'inconnu (like, unlike, commentaires, suppression de commentaire, testé), vues ignorées (204) hors audience, aucun compteur exposé hors audience ; limites 10/min + 200/jour et 60/min ; curseurs signés (MAC, encodage canonique, forme stricte du curseur Pour toi) ; FFmpeg : liste blanche mov/mp4, `file:`, `-t 61` + durée décodée, 50 Mpx, 16 flux, ffconcat/PNG renommé/faux ftyp testés ; `ready` sous `LockedFind` (refresh FOR UPDATE), deux ordres testés ; purge clip/visuel+poster si post supprimé ou échoué.

## Findings
- **Élevé 1 — clé publique du visuel calculable depuis l'id du post.** `src/Media/MediaKeys.php:44` : `Uuid::v5(postId, 'post-visual')`. DATA_MODEL §14 exige des clés publiques non devinables (c'est la seule protection acceptée des visuels masqués). Scénario : O publie une vidéo sur un morceau `close_friends` ; un lien `/posts/{id}` (route front `router.tsx:30`) circule ; S, hors audience, a 404 sur l'API mais lit `/media/videos/posts/{id}/{v5}.mp4` et le poster. Idem post masqué par modération, ex-abonné, morceau repassé en privé. Correctif : dériver la partie versionnée d'un HMAC(kernel.secret, postId) (reste déterministe, donc idempotent), sans migration.
- **Moyen 1 — `/feed/for-you` sans limite de débit** (`ApiResource/PostView.php:39`, absent de `RateLimitListener::ROUTES`). Chaque page = parcours complet des posts jouables + CTE (ADR 011) ; boucle de requêtes = charge linéaire au catalogue sur une t4g.small. Ajouter une limite par utilisateur (ex. 60/min) avant la prod ; W-40 réduira le coût.
- **Bas 1** — prédicat « jouable » recopié à la main dans `SimpleFeedRanker.php:127` et `PostRepository.php:69` au lieu de `postPlayableSql()/Dql()` (le ticket W-39 demandait le fragment partagé). Équivalent et maintenant gardé par le test de parité ; à factoriser.
- **Bas 2** — un curseur Pour toi de phase `s` (`k = [timestamp µs, id]`, `v:1`) est accepté par `CursorCodec::decode` (`CursorCodec.php:59`) sur `/feed/following` et `/posts/{id}/comments`. Pas de fuite (position seule, filtres par spectateur) ; ajouter un champ de type au payload si on veut l'étanchéité.
- **Bas 3** — W-31 : liste blanche de codecs vérifiée sur le 1er flux vidéo seulement (`PostVisualProcessor.php:74`) ; ffprobe/find_stream_info décodent aussi les autres flux. Même niveau que les morceaux ; `-codec_whitelist` reste l'idée de durcissement T12.
- **Bas 4** — course création de commentaire / like contre suppression du post : FK violée → 500 au lieu de 404 (`CreateCommentProcessor.php:56`, `LikeProcessor.php:77`). Rare, sans fuite.
- **Bas 5** — `tryMarkReady` (`PostRepository.php:33`) n'est plus appelée que par les tests ; la quarantaine est supprimée aussi dans le cas « settled » (post échoué par l'autre handler, `ProcessPostVisualHandler.php:136`), contre « gardée 7 jours après échec » du §14. Sans impact sécurité.

## Décisions W-32 relues
- Supprimer un commentaire supprime ses signalements : acceptable (cohérent avec la suppression d'un post, §9 ; le contenu nuisible disparaît). Perte de traces de récidive pour la modération phase 5 : à journaliser alors si besoin.
- Like et commentaire exigent « jouable » même pour un modérateur : aucun problème (plus restrictif, le modérateur garde lecture et suppression).

## Contrat d'API modifié ?
- Non. Aucun code source modifié ; un test ajouté.

## Commande pour vérifier
`make test c="--no-progress tests/Security/PostPlayableParityTest.php"` ; `bash .claude/skills/wave-verif/verif.sh back` (OK : 444 tests, PHPStan, media 77, audit).
