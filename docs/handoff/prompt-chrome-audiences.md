Tu testes l'application web Wave en local : http://localhost:8090 (e-mails dans Mailpit : http://localhost:8027). Ouvre un nouvel onglet, ne touche pas aux autres. Tous les comptes ont le mot de passe `WaveDemo2026!`. Pour changer de compte : menu Profil → Se déconnecter, puis se reconnecter.

But : vérifier qui peut voir et écouter quel morceau selon son audience (Public, Abonnés, Amis proches, Moi seul). Ne crée, ne supprime et ne modifie rien, sauf aux étapes 5 et 6, qui le demandent.

Morceaux de référence, tous appartenant à Amina Benali (amina@wave.test) :
- « Minuit sur le périph » : Public
- « Café noir » : Abonnés
- « Lettre à Maman » : Amis proches (lucas et yasmine seulement)
- « Brouillon » : Moi seul

Étapes :
1. Connecte-toi en amina@wave.test. Ouvre son profil : les 4 morceaux sont visibles, chacun avec son audience affichée. Lance « Lettre à Maman » : le son démarre.
2. Connecte-toi en lucas@wave.test (abonné ET ami proche d'Amina). Sur le profil d'Amina, attendu : « Minuit sur le périph », « Café noir » et « Lettre à Maman » visibles et jouables ; « Brouillon » absent.
3. Connecte-toi en karim@wave.test (abonné, PAS ami proche). Attendu : « Minuit sur le périph » et « Café noir » visibles ; « Lettre à Maman » et « Brouillon » absents. Cherche « Lettre à Maman » dans la recherche : aucun résultat.
4. Connecte-toi en ines@wave.test (ne suit PAS Amina). Attendu : seulement « Minuit sur le périph ». Cherche « Café noir » : aucun résultat.
5. Toujours en Inès, abonne-toi à Amina (bouton Suivre) : « Café noir » doit apparaître (recharge la page si besoin), « Lettre à Maman » toujours absent. Puis désabonne-toi : « Café noir » disparaît à nouveau.
6. Reconnecte-toi en amina@wave.test, passe « Café noir » en « Moi seul ». Reconnecte-toi en karim@wave.test : « Café noir » ne doit plus être visible. Remets ensuite « Café noir » en « Abonnés » avec le compte d'Amina.
7. Pour les posts : en lucas@wave.test, ouvre l'onglet « Pour toi » et note les posts d'Amina visibles ; refais la même chose en hugo@wave.test (ne voit que le public). Signale tout post sur « Lettre à Maman » ou « Brouillon » visible chez Hugo.
8. Navigation : la barre d'onglets du bas (Pour toi · Découvrir · + · Bibliothèque · Profil) reste visible quand un morceau joue ; vérifie aussi en fenêtre étroite (largeur de téléphone).

Règles : si une page affiche une erreur, relève le texte exact et l'URL, puis continue. N'entre aucune donnée réelle. Arrête-toi et demande-moi si un écran ne correspond pas du tout à ce qui est décrit.

Compte rendu final : un tableau étape par étape (Attendu / Constaté / OK ou KO), puis la liste des anomalies avec URL, compte utilisé et texte exact de l'erreur.
