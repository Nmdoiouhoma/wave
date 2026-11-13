## Fait
Revue sécurité backend W-70/71/72 (`57f0e38`, `bacbc20`). Aucun critique ni élevé. Nouveau test : `backend/tests/Security/BackOfficeSecurityTest.php`, 7 tests, tous verts.
Ce qu'il couvre :
- 18 routes `/admin/*` : refus avant toute recherche (401, 403), sans journal écrit.
- Matrice du personnel sur la levée de suspension, et 7 corps d'escalade de rôle refusés (422).
- Cache du tableau de bord : le résultat ne dépend pas du premier appelant.
- Annonces : XSS stocké en texte brut, 15 corps invalides refusés, annonces programmées ou expirées masquées.
- Journal : en-tête X-Forwarded-For usurpé et agent utilisateur hostile ignorés ; ni secret ni texte saisi n'est stocké ; préfixe et HMAC exacts.
- Routes de suppression du propriétaire après le remaniement de ContentDeleter.
`docs/SECURITY.md` : acteur admin, menaces T26-T28, constats F25-F31.

Constats, par gravité (correctif proposé) :
- F25 faible, `src/State/Provider/ActiveAnnouncementsProvider.php:40` : `:now` est lié en `datetime_immutable` (à la seconde) alors que la colonne est en microsecondes. Une bannière créée sans `startsAt` reste cachée jusqu'à 1 s, une bannière terminée reste affichée jusqu'à 1 s. Correctif : `setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)`. Le test fixe donc un `startsAt` explicite.
- F26 faible, `src/State/Processor/AdminSuspensionProcessor.php:66` : `StaffGuard` est vérifié hors de la transaction (ligne 74), donc un grant console concurrent passe. Correctif : relire les rôles `FOR UPDATE` dans la transaction, comme `AdminRoleProcessor`.
- F27 faible, `src/Command/Admin/AdminRoleChanger.php:43-46` : le grant accepte un compte suspendu ou non vérifié, et le décompte du dernier admin inclut les admins suspendus. Correctif : refuser ces cibles et compter `suspended_at IS NULL`.
- F28 faible, `src/Dto/Input/CreateAnnouncementInput.php:16,21` (et Update) : titre et corps acceptent les surcharges bidi et les caractères de contrôle (seul NUL est rejeté). Correctif : appliquer la règle `isCleanText` des légendes et rejeter `\p{Cc}` sauf `\n`, ainsi que U+202A-202E et U+2066-2069.
- F29 faible, `src/Activity/ClientFingerprint.php:50-51` : l'agent utilisateur garde du texte de l'attaquant (`[31m<script>`). Volontaire, mais W-74 doit l'afficher en texte, jamais en HTML.
- F30 info, `src/State/Provider/AdminDashboardProvider.php:52-53` : un modérateur reçoit les compteurs utilisateurs et contenus, alors qu'ADR 013 dit « moderation counters only ». À trancher.
- F31 ouvert, `migrations/Version20261113000100.php:70-71` : les clés étrangères sont en `SET NULL`. La future suppression de compte (§13b) devra supprimer explicitement les événements non admin, sinon le hash d'IP et l'agent utilisateur restent orphelins.
- Rappel (connu W-70) : `ACTIVITY_IP_PEPPER` manque dans `compose*.yaml` et `infra/`, ce qui bloque la prod (media-infra).
- Info, sans action : un admin peut supprimer le contenu d'un autre admin et acquitter une alerte qui le concerne.

## Décisions prises
Aucune. F30 (compteurs du tableau de bord pour le modérateur) est à trancher par le lead ou l'architecte.

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
Corriger F25-F28 (backend). F29 concerne W-74 (front). F31 est pour la phase 5. Le pepper est à ajouter côté media-infra.

## Commande pour vérifier
`make test c="--no-progress --filter BackOfficeSecurityTest" 2>&1 | tail -5`
