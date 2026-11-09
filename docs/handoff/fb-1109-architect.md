## Fait
- FB-15 : `GET /search?type=tracks` avancé en phase 2 (description de `/search` dans `openapi.yaml`), tickets W-29a (backend) et W-29b (frontend, bouton « Ajouter des morceaux » de la page playlist) dans TICKETS phase 2 ; W-41 (phase 4) ne garde que les 3 autres types.
- FB-16 : notifications in-app en phase 3. DATA_MODEL §8b (tables `notification` + `notification_actor`, événements, agrégation, nettoyage, filtres de lecture), enum `NotificationType`, §8 et §13b reliés. 3 routes `/me/notifications*` + 5 schémas dans `openapi.yaml`. Tickets W-45 (backend, lot 3) et W-46 (cloche, lot 4) ; W-30 crée les 2 tables dans sa migration unique ; W-37 relit les notifications. CONVENTIONS §3 : dossier `features/notifications`.

## Décisions prises
- Recherche de morceaux : **pas de paramètre**, le classement backend met d'abord les morceaux du demandeur, puis ses likes (le demandeur les connaît déjà, donc pas d'oracle) ; le front ne fusionne rien. Sous 2 caractères, la feuille affiche « Tes morceaux » et « Tes titres likés » avec les routes existantes.
- Morceaux renvoyés : prêts, non masqués, publics ou à soi, propriétaire non suspendu. C'est plus strict que `GET /tracks/{id}` pour les suspendus (F18 inchangé ailleurs). Le curseur est lié à `q` et `type`.
- Notifications : une ligne agrégée par (destinataire, type, cible) tant qu'elle n'est pas lue (index unique partiel `NULLS NOT DISTINCT`, upsert sans verrou) ; une fois lue, l'événement suivant ouvre une nouvelle ligne. Une boucle like/unlike donne donc au plus 1 notification par lecture.
- Pas de notification à soi-même. Créée seulement si l'action insère vraiment une ligne, dans la même transaction. Un unlike ou un unfollow retire l'acteur, et la ligne est supprimée si plus personne n'y figure. Supprimer ou masquer un commentaire ne touche pas aux notifications (aucun texte de commentaire n'est stocké).
- Nettoyage : de vraies FK en CASCADE vers track, playlist et post (pas de cible polymorphe). Une ligne sans acteur est omise à la lecture et supprimée par `DELETE /me`.
- À la lecture : acteurs suspendus exclus ; cible invisible pour le destinataire omise (règle de visibilité de son type). Le compteur de non-lus utilise la même requête, plafonnée à 100.
- `POST /me/notifications/read {upTo}` : aucun identifiant dans les routes, donc rien d'un autre utilisateur à sonder (T7/T17). Un événement arrivé entre-temps reste non lu.

## Contrat d'API modifié ? oui
- `/search` : `type=tracks` accepté dès la phase 2 (règles, classement, curseur lié à `type`). Aucun schéma modifié (`TrackPage` existait déjà).
- Nouveau : `GET /me/notifications`, `GET /me/notifications/unread-count` (120/min, bucket `notifications`), `POST /me/notifications/read` ; schémas `NotificationType`, `NotificationTarget`, `Notification`, `NotificationPage`, `UnreadNotificationCount`, `MarkNotificationsReadRequest`. Régénérer `frontend/src/api/schema.d.ts`.

## Reste à faire / points ouverts
- Ordre phase 3 : lot 3 = W-33 ∥ W-34 ∥ W-35 ∥ W-45 ; lot 4 = W-36 ∥ W-46, puis W-37. W-29a et W-29b peuvent passer avant la phase 3 (lot de retours du 09/11).
- La purge des notifications lues de plus de 90 jours est renvoyée à la phase 5. Pas de notification en temps réel (sondage toutes les 60 s).
- `docs/FEEDBACK.md` (statuts FB-15 et FB-16) et `etat-projet.md` sont à mettre à jour par le lead.

## Commande pour vérifier
`ruby -ryaml -e 'YAML.load_file("docs/openapi.yaml")'` · `cd frontend && npm run check:api` (doit échouer jusqu'à la régénération) · `sed -n '/^## 8b/,/^## 9/p' docs/DATA_MODEL.md` · `grep -n "W-29\|W-4[56]" docs/TICKETS.md`
