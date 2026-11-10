## Fait
- Shell de navigation (FB-11) : `routes/AppLayout.tsx` réécrit. Mobile (< 768 px) : barre d'onglets fixe en bas, 56 px + zone sûre, 5 entrées Pour toi · Découvrir · + · Bibliothèque · Profil. Desktop : mêmes entrées dans le rail. La disposition est choisie par `lib/useMediaQuery.ts` (une seule nav dans le DOM).
- Table chemin → onglet et écrans du fil : `routes/navigation.ts` (`activeTab`, `isFeedPath`), `aria-current="page"` posé à la main (Profil = `/u/{me}*`, `/settings`, `/me/artists*`).
- + : `routes/CreateMenu.tsx`, en feuille (`Sheet`) sur mobile et en popover à côté du bouton sur desktop ; liens `/posts/new` et `/upload` ; Escape/fond referme, le focus revient sur +.
- Écrans d'attente : `features/feed/FeedStubPage.tsx` (`/`, `/following`), `features/posts/PostStubPages.tsx` (`/posts/:id`, `/posts/new`). `HomePage` supprimée.
- `/search?q=` → `/discover?q=` (`routes/SearchRedirect.tsx`) ; SearchPage montée sur `/discover`, titre « Découvrir ».
- Lecteur complet : feuille non modale, bornée au-dessus de la barre (mobile) et à droite du rail (desktop). Plus de piège à focus, sinon la barre ne serait plus utilisable. Toute navigation le referme. Mini-lecteur caché sur `/`, `/following`, `/posts/:id` ; marge basse du contenu avec zone sûre.
- Profil : « Mes artistes » ajouté dans l'en-tête de son propre profil, à côté de « Modifier le profil » (→ `/settings`). Le rail n'a plus de pied Paramètres. La barre du haut mobile reste en dehors des écrans du fil.
- `GuestOnly` : quand on est connecté, `/login?next=` renvoie vers `safeNext(next)` (sinon `/`).
- Tests : `routes/navigation.test.tsx`, 30 cas (table des 19 chemins, ordre et libellés, feuille + et focus, popover desktop, redirection recherche, lecteur ouvert avec barre cliquable, mini-lecteur absent sur `/`, `/login` connecté avec `next` sûr ou hostile).

## Décisions prises
- Le lecteur complet passe de modal (`aria-modal`, piège à focus) à non modal. Une conséquence du ticket, à valider en revue.
- Le chemin d'ouverture de chaque calque (lecteur, menu +) est mémorisé, ce qui permet de les refermer au changement de route sans effet React (règle de lint `set-state-in-effect`).
- happy-dom répond aux media queries comme une fenêtre de 1024 px : les tests de l'interface forcent la largeur avec `vi.spyOn(window, 'matchMedia')`.

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- W-34 : remplacer les écrans d'attente du fil, mettre le lecteur global en pause sur le fil. `main` fait déjà 100dvh moins la barre sur ces routes ; la bannière de vérification d'e-mail peut ajouter un défilement.
- Le popover desktop est placé en position fixe à la hauteur du bouton + (pas de repositionnement au redimensionnement).
- Test manuel attendu : zone sûre iPhone, lecteur complet puis appui sur un onglet, menu + au clavier.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` (VERDICT OK, 113 tests).
