# W-43 Découvrir — maquette v2 (`w43-discover.html`, à valider avant tout code)

Ouvrir le fichier dans un navigateur ; bascule Clair / Sombre / Système en haut. Sept sections : les 6 écrans + les états. Écran 1 en deux planches : catalogue riche et catalogue pauvre. Les pochettes sont des dégradés CSS, noms inventés, aucune image. Cliquer une pochette simule la lecture (anneau, pastille « En cours », barres animées).

## Choix faits
- Découvrir est centré sur les morceaux. Ordre : morceau mis en avant (grande pochette, bouton « Écouter » bien visible, un clic lance) · genres · rangées « Pour toi », « Nouveautés », « En tendance » · comptes à suivre + hashtags en tendance · « Artistes célèbres » (Deezer, non jouable).
- Rangées horizontales de pochettes jouables : un clic sur la pochette lance, un second met en pause ; un seul morceau « en cours » à la fois. L'état n'est pas porté par la couleur seule (anneau + pastille + barres). La lecture est celle du lecteur existant (hypothèse à confirmer au code).
- « Pour toi » affiche une raison courte sous chaque pochette (« Parce que tu écoutes <artiste ou genre> »). « Nouveautés » affiche l'ancienneté ; « En tendance » le rang et le nombre d'écoutes.
- Morceau mis en avant = le 1er de « Pour toi » ; sans « Pour toi » (nouveau compte), le 1er des nouveautés puis des tendances. Il n'est pas répété dans la rangée.
- **Règle de remplissage** : les rangées de morceaux sont prioritaires ; comptes à suivre et hashtags servent à combler. Une rangée vide est masquée ; quand les rangées sont maigres, comptes (6 au lieu de 5) et hashtags passent en blocs larges juste sous elles et « Artistes célèbres » se réduit. Montré en bureau (planche catalogue pauvre) et en mobile (états).
- Genres : pastilles comme l'existant, non cliquables (le contrat n'a pas de filtre par genre) ; les 3 premiers, tes préférés, gardent la bordure d'accent.
- Mouvement discret : apparition en cascade, survol des pochettes, lueur sous la mise en avant pendant la lecture. `prefers-reduced-motion` coupe toutes les animations et transitions. Focus visible partout ; sur écran tactile la pastille de lecture reste visible (pas de survol).
- Bureau : flèches de défilement sur chaque rangée. Mobile : défilement au doigt, mise en avant sur une colonne.
- Jetons « Papier » copiés de `frontend/src/index.css` ; HTML autonome (pas de `.dc.html`, pas de Google Fonts). Écrans 2 à 5 (recherche, hashtag, abonnements, Pour toi) inchangés.
- Après « Suivre », la ligne reste avec « Suivi » (pas de saut de liste).

## Ajouts de contrat nécessaires (à porter par l'architecte)
- `DiscoverHome.forYouTracks` : liste (≈ 10) de `{ track: TrackSummary, reasonType: "artist" | "genre", reasonLabel: string }`. Le front compose « Parce que tu écoutes <reasonLabel> » (« Parce que tu aimes » possible pour un like). Vide pour un compte sans historique ; exclut ce que l'utilisateur a déjà écouté ou aimé.
- `DiscoverHome.newTracks` : `TrackSummary[]` (≈ 12), derniers morceaux publics, plus récent d'abord. L'ancienneté (« il y a 12 min ») exige une date de publication : sur `TrackSummary` ou, à défaut, on l'enlève.
- Les `TrackSummary` des trois rangées doivent permettre la lecture directe (URL d'extrait ou identifiant lisible par le lecteur) et porter genre, durée, artiste, nombre d'écoutes. À vérifier dans le schéma.
- `trendingTracks` existe déjà ; la taille de chaque liste est bornée côté serveur.

## Questions à trancher
1. **Abonnements sur bureau** : la colonne de suggestions remplace-t-elle le panneau morceau + commentaires de droite (maquette : oui), ou les deux s'empilent-ils ?
2. **Pour toi à 1280 px** : avec le rail (240) et le panneau droit (384), il reste 232 px à gauche — 4 comptes, 5 hashtags, sans nombre d'abonnés. Acceptable, ou colonne seulement à partir de 1440 px ?
3. **Recherche** : « Artistes célèbres » apparaît-il aussi sous les résultats (maquette : oui, onglet « Tout »), ou seulement sur Découvrir à vide ?
