# W-41 · backend · Recherche phase 4 et hashtags

## Fait
- `GET /search` : artistes, playlists, hashtags (`PostgresSearchProvider`, FTS `simple` + unaccent, chaque jeton en préfixe, + trigram ≥ 0,3) ; vue d'ensemble sans `type` = 5 sections ≤ 5 éléments, curseur réutilisable avec `type=` ; avec `type`, pagination jusqu'à 200. Pas de section catalogue (ADR 014 §3).
- `GET /hashtags/{name}` et `/hashtags/{name}/posts` (`HashtagProvider`, `HashtagPostsProvider`, `HashtagRepository`) : seulement les posts jouables par l'appelant, plus récents d'abord ; sinon 404.
- Reprise du 20/11 (lot interrompu le 16/11) : les 3 échecs de `SearchPhaseFourTest` venaient du test, pas du code. Le code respecte le contrat ; le test a été aligné :
  1. `marcoz` trouve aussi `Marco` (similarité 0,63 ≥ 0,3, devant `Marcos` 0,56) : attendu `[Marco, Marcos]`.
  2. `lofisecret` renvoie `lofi` (voisin trigram 0,33, légitime) : on vérifie que le tag restreint ne fuit pas, pas une liste vide.
  3. `q='` et `q=\` font 1 caractère → 422 (`minLength: 2` du contrat) : déplacés vers les cas refusés.
  Corrigés au passage, révélés une fois ces cas débloqués : le limiteur `search` (60/min) est remis à zéro à chaque type dans ce test (~27 recherches par type) ; `zzzz | ac` remplacé par `qwxyzk | ac` (`zzzz | ac` est à 0,30 de `AC DC`, donc trouvé par trigram).

## Décisions prises
- Aucune nouvelle. Classement artistes : nom exact, correspondance plein texte, similarité, nombre de morceaux, id. Hashtags : exact, préfixe, similarité (forme minuscule et forme sans accents), nombre de posts, id. Un `#` en tête est ignoré.

## Contrat d'API modifié ?
Non.

## Migration
`Version20261116150000` (déjà appliquée en dev et test le 16/11, non modifiée) :
`CREATE INDEX idx_playlist_name_trgm ON playlist USING GIN (wave_unaccent(lower(name)) gin_trgm_ops)` ; down = `DROP INDEX idx_playlist_name_trgm`. À ne jamais laisser supprimer par un `doctrine:migrations:diff`.

## Reste à faire / points ouverts
- Le seuil trigram 0,3 rend les requêtes courtes (4-5 lettres) assez larges : à surveiller au test utilisateur et au seed W-42 (p95 à mesurer).
- `hashtag.post_count` n'est pas recompté au masquage d'un post (DATA_MODEL §9) : `postCount` (recherche et `GET /hashtags/{name}`) est ce compteur stocké, il peut donc inclure des posts masqués ou non jouables par l'appelant (léger oracle de nombre, à trancher en W-44).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh back` → VERDICT OK (577 tests, 84 ignorés, 20/11)
