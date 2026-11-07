# W-26 correctifs — re-revue qa-security (F10-F16, `4f92612..2be4d1c`)

## Fait
- F10-F16 fermés. Vérifié par tests (`tests/Security/PhaseTwoFixesSecurityTest.php`, 7 tests) :
  - F12 : NUL dans les clés JSON, à la racine et en profondeur → 422 ; surrogate isolé ou octets invalides → 400. Dans l'URL (clé de query invalide, encodage trop long `%C0%80`, surrogate `%ED%A0%80`) → 400. Pas de contournement par double encodage : `%2500` reste le texte littéral `%00`. Cookie de renouvellement, jeton mobile ou bearer avec NUL → 401 ; logout → 204. Les en-têtes ne vont jamais en base.
  - F14/F16 : le 404 est identique au caractère près (statut, corps, en-têtes hors date et debug) entre une cible invisible sans like, une cible inexistante et un id invalide. Même chose entre un suspendu et un inconnu sur les 7 routes `/users/{u}*`. Pas de route oubliée (`/posts` arrive en phase 3).
  - F13 : une boucle qui demande une stream-url avant chaque écoute s'arrête à 20 écoutes comptées par jour. Le grant d'un autre compte ne sert à rien. Le temps vient uniquement de l'horloge de la base (`clock_timestamp`), donc pas de dérive entre PHP et la base. Avec des `consume` concurrents, le `DELETE … RETURNING` et le verrou consultatif sérialisent les rapports : 1 seule écoute comptée (test existant). Une durée nulle ou inconnue donne 409 avant tout comptage.
  - F15 : le quota `playlist_write` est bien commun à la création et à l'ajout (429). Dans `ConcurrencySecurityTest`, 6 créations simultanées à 198 playlists donnent exactement 200 playlists et quatre 409.
- Symfony 7.4.23 installé, seuils alignés (`composer.json`, `check-symfony-security.sh`, Makefile) : rien à faire.
- `docs/SECURITY.md` : T13, T17 à T21 et le statut de F10 à F18 mis à jour.

## Findings
- **BAS F17 (backend)** `src/Repository/PlaylistRepository.php:238-243` : la suppression d'un titre ne verrouille que les playlists qui le contiennent déjà. Si un ajout du titre à une autre playlist tombe au même moment, `item_count` garde 1 de trop après la cascade ; dans l'ordre inverse, l'ajout échoue sur la clé étrangère et renvoie un 500 (`PlaylistItemProcessor`). Correctif : prendre le titre avant les playlists des deux côtés (suppression `FOR UPDATE`, ajout `FOR KEY SHARE` avant le verrou de la playlist) et convertir la violation de clé étrangère en 404. Tests en échec volontaire : `ConcurrencySecurityTest::testTrackDeletionRacingAnAppendToAnotherPlaylist…` et `testAppendRacingATrackDeletionIsNotA500`.
- **BAS F18 (architect, phase 5)** Un compte suspendu reste visible comme propriétaire ou artiste sur `/playlists/{id}`, `/tracks/{id}` et `/artists/{slug}`, et dans les listes de likes et les items. Constaté à la lecture du code : seuls `UserLookup`, la recherche et les listes de follows filtrent les suspendus. C'est conforme au contrat, qui ne couvre que `/users/*`.
- **INFO** (aucune action) : `DELETE /users/{u}/follow` distingue un suspendu (200) d'un inconnu (404), accepté par le contrat ; `removeOwn` renvoie le `likeCount` actuel d'une cible devenue privée à celui qui l'avait likée ; un titre de 1 s atteint le plafond de 20 écoutes en 10 minutes, la borne de l'ADR 010 tient ; `itemCount` et les trous de positions trahissent des items cachés aux non-propriétaires, accepté par le contrat.

## Décisions prises
- Aucune.

## Contrat d'API modifié ? (oui/non + détail)
- Non.

## Reste à faire / points ouverts
- backend : F17. architect : F18, à traiter avec la modération de la phase 5.

## Commande pour vérifier
- `make test c="--no-progress tests/Security"` → 130 tests, 2 échecs attendus (F17).
