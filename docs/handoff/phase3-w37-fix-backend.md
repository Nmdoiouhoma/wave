## Fait
- F19 : `CreatePostProcessor::echoableName()` — nom de champ inconnu renvoyé seulement s'il est du texte propre, sinon `(invalid field name)` ; `ProblemResponseFactory` encode avec `JSON_INVALID_UTF8_SUBSTITUTE` (aucun 422 ne devient 500 par l'encodage). Test qa renommé `testInvalidUtf8MultipartFieldNameIsA422NotA500`, inchangé sinon, vert.
- F22 : nouveau `src/Post/VisibleText.php` (trim espaces Unicode + U+200B-U+200D, U+2060, U+FEFF aux deux bouts) ; utilisé par le corps de commentaire (422 si vide) et la légende de post (même trou : seuls invisibles → légende `null`, comme une légende blanche). Tests : `PostInteractionTest::testInvisibleOnlyCommentBodyIsEmpty`, cas ajouté dans `PostTest` (202, `caption: null`).
- F23 : `ModerationDecisionProcessor` relit la cible par id après la transaction (`ReportTargetResolver::exists()`, requête en base, pas l'identity map) → 404 `not_found` si supprimée ; `refresh()` gardé, `EntityNotFoundException` → 404. Constat : en ORM 3.7, `refresh()` sur une ligne supprimée ne lève pas, il renvoyait un 200 périmé (pas un 500). Test fonctionnel sans concurrence : `ReportTest::testTargetDeletedBeforeTheReloadIsA404` (écouteur `postFlush` qui supprime le commentaire dans la transaction de la décision ; rouge avant le correctif).
- PHPStan OK, cs-fixer 0 fichier ; tests ciblés 41/41 verts.

## Décisions prises
- Légende : invisibles seuls = pas de légende (pas de 422 : la légende est optionnelle). Nom de champ non propre remplacé par un libellé fixe plutôt que `mb_scrub`.

## Contrat d'API modifié ?
Non (codes d'erreur inchangés : 422 `validation_failed`, 404 `not_found`).

## Reste à faire / points ouverts
- F20, F21 (arbitrage architecte), F24 (frontend).

## Commande pour vérifier
`make test c="--no-progress --filter '(PhaseThreeReviewSecurityTest|ReportTest|PostInteractionTest|PostTest)'" 2>&1 | tail -40`
