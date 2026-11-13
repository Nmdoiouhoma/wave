## Fait
- F25 `ActiveAnnouncementsProvider` : `:now` lié en `Types::DATETIMETZ_IMMUTABLE` (microsecondes). Test sans `startsAt` (visible tout de suite) + fin à « maintenant » masquée tout de suite ; le test échoue sans le correctif (vérifié 3/3).
- F26 `AdminSuspensionProcessor` : rôles relus `FOR UPDATE` dans la transaction, nouvelle `StaffGuard::canActOnRoles()` → 403. La vérification avant transaction reste (ordre des erreurs inchangé). Pas de test de concurrence.
- F27 `AdminRoleChanger` + `GrantAdminCommand` : grant refusé si suspendu (« Refused: this account is suspended. ») ou non vérifié (« ...has not verified its email. »), code 1. Le dernier admin = admins non suspendus ; révoquer un admin suspendu est toujours permis. Nouveaux cas `AdminRoleOutcome::Suspended|Unverified`.
- F28 `Create/UpdateAnnouncementInput` : `Assert\Regex` → 422 `validation_failed` pour `\p{Cc}` (C0+C1) et U+202A-202E, U+2066-2069 ; `\n` permis dans `body` seulement (`\r`, tabulation refusés).
- F30 `AdminDashboardProvider` : modérateur → `users` et `content` à null (en plus d'`alerts`/`announcements`), seul `moderation` rempli.
- F29, F31 non touchés. Aucune migration.

## Décisions prises
- `body` multi-ligne via `\n` seul ; `title` sur une ligne.

## Contrat d'API modifié ? (oui/non + détail)
Oui (F30) : `docs/openapi.yaml` `AdminDashboard.users` et `.content` ne permettent PAS null aujourd'hui (l.3136, 3154) → architecte : passer en `oneOf [objet, null]` « Null for a moderator caller » comme `alerts`. Front : le tableau de bord modérateur doit gérer `users`/`content` null.
F28 : 422 nouveau sur `title`/`body` (texte de règle non décrit dans l'openapi).

## Reste à faire / points ouverts
- Le `suspend` de la décision W-33 (`/admin/report-targets/.../decision`) vérifie encore `StaffGuard` hors verrou : même course que F26, non demandé ici.

## Commande pour vérifier
`make test c="--no-progress --filter 'BackOfficeSecurityTest|AdminUsersTest|ActivityJournalTest|AnnouncementApiTest'" 2>&1 | tail -5`
