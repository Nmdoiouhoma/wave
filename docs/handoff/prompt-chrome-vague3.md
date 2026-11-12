Tu testes l'application web Wave en local : http://localhost:8090. Ouvre un nouvel onglet, ne touche pas aux autres. Tous les comptes ont le mot de passe `WaveDemo2026!`. Pour changer de compte : Profil → Modifier le profil → tout en bas « Se déconnecter », puis reconnecte-toi.

But : tester le nouveau fil vertical de posts courts (« Pour toi » et « Abonnements ») et la publication d'un post. Ne modifie rien d'autre que ce que les étapes demandent. Commentaires et signalement ne sont pas encore livrés (un message « arrive bientôt » est normal). La cloche de notifications n'existe pas encore.

Posts restreints de référence (ne doivent apparaître qu'à leur audience) :
- « Lettre à Maman » (Amina, amis proches : Lucas et Yasmine seulement)
- « Café noir » (Amina, abonnés d'Amina)
- « Brouillon » (Amina, elle seule)
- « Rooftop Sunrise » (Lucas, amis proches : Amina et Chloé)
- « Ma sœur » (Mamadou, amis proches : Karim et Yasmine)

Étapes :
1. Connecte-toi en lucas@wave.test. La page d'accueil est « Pour toi » : un fil vertical plein écran. Vérifie : un post occupe tout l'écran ; le défilement passe d'un post à l'autre (molette, flèches haut/bas ou touches j/k) ; l'extrait audio démarre seul (si un badge « Touche pour écouter » apparaît, clique une fois, c'est normal) ; le visuel s'affiche (image, vidéo muette en boucle, ou pochette du morceau) ; la légende et l'auteur sont visibles. Note la liste des titres de morceaux des posts vus jusqu'à la fin du fil.
2. Toujours en Lucas : va au bout du fil. Attendu : une carte « Tu as tout vu » avec « Recommencer » ; cliquer « Recommencer » repart du début.
3. Double-clique (double tap) sur le visuel d'un post qui n'est pas aimé : le cœur s'active et le compteur augmente de 1. Recharge la page : le like est conservé. Clique sur le cœur pour retirer le like : le compteur redescend.
4. Clique sur le nom de l'auteur d'un post : tu arrives sur son profil. Reviens, clique sur le titre du morceau : tu arrives sur la page du morceau.
5. Lance un morceau dans le lecteur du bas (depuis un profil), puis reviens sur « Pour toi » : quand un extrait démarre, le lecteur du bas se met en pause et ne reprend pas tout seul.
6. Passe sur l'onglet « Abonnements » (en haut du fil) : attendu, seulement des posts de comptes que Lucas suit (Amina, Yasmine, Chloé, Théo, Sofia).
7. Cloisonnement : refais l'étape 1 (liste des titres) avec ines@wave.test (elle ne suit pas Amina et n'est l'amie proche de personne). Attendu : AUCUN des 5 posts restreints de référence. Puis avec hugo@wave.test (il suit Amina) : « Café noir » peut apparaître, mais ni « Lettre à Maman », ni « Brouillon », ni « Rooftop Sunrise », ni « Ma sœur ».
8. Accès direct : en ines@wave.test, ouvre l'URL d'un post restreint que tu as vu à l'étape 1 chez Lucas (copie son URL /posts/… depuis la session Lucas, ou clique sur un post pour l'obtenir). Attendu : « Ce post n'est plus disponible ».
9. Publication : connecte-toi en hugo@wave.test, clique sur le « + » (Créer) puis publie un post : choisis un de ses morceaux, règle le début et une durée entre 15 et 60 s, écoute l'aperçu, écris une légende avec un #hashtag, sans visuel. Clique « Publier ». Attendu : une progression, puis l'ouverture du post publié (/posts/…) qui se lit. Relève le temps d'attente.
10. Le bouton « Utiliser ce son » : en hugo@wave.test, sur un post public d'un autre compte, s'il propose « Utiliser ce son », clique : le composeur s'ouvre avec ce morceau présélectionné. Ne publie pas.
11. Fenêtre étroite : ouvre les outils de développement (Cmd+Option+I), mode appareil (Cmd+Shift+M), iPhone (390 px). Le fil reste plein écran, la barre d'onglets du bas reste visible, le défilement d'un post à l'autre fonctionne.

Règles : si une page affiche une erreur, relève le texte exact et l'URL, puis continue. N'entre aucune donnée réelle. Arrête-toi et demande-moi si un écran ne correspond pas du tout à ce qui est décrit.

Compte rendu final : un tableau étape par étape (Attendu / Constaté / OK ou KO), la liste des posts vus par Lucas, Inès et Hugo à l'étape 7, puis les anomalies avec URL, compte utilisé et texte exact de l'erreur. Distingue ce que tu as vu à l'écran de ce que tu déduis du code.
