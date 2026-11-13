# Handoff fb-1113b — frontend
FB-29 et FB-30 faits. Aucun changement de contrat API.
- FB-29 : `FeedViewer` déplace `header` dans la colonne du post ; `FeedTabs inline` (FeedPage.tsx) = ligne propre au-dessus
  de la carte dès md (static, centrée sur la colonne, plus de dégradé), overlay inchangé sur téléphone. États
  chargement/vide/erreur gardent l'overlay. Carte : flex-1 + largeur max `(100dvh-5.5rem)*9/16`.
- FB-30 : bouton commentaires de `FeedItem` visible partout (plus de `lg:hidden`), label « Commentaires, N ».
  lg+ (WIDE_QUERY) : `focusSideComment()` (nouveau `feed/focusSideComment.ts`) scrolle et focalise la textarea du
  panneau ; sinon (ou panneau absent) ouvre `CommentsSheet`.
- Tests : 2 cas ajoutés dans `feed.test.tsx` (focus lg, feuille téléphone) ; `comments.test.tsx` : nouveau nom du
  bouton + matchMedia forcé à false (le test W-36 voyait un layout large dans jsdom et n'ouvrait plus la feuille).
- Verif front : VERDICT OK. Pas de vérification navigateur (aucune capture), rien commité.
- Reste : contrôle visuel desktop des onglets par le lead (largeur de carte approchée, 5.5rem estimé).
