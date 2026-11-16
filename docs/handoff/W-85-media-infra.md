# W-85 · media-infra · câblage catalogue (ADR 014 §9)

## Fait
- CSP `front-static.conf` et `front-dev.conf` : `https://*.dzcdn.net` ajouté à `img-src` et `media-src` uniquement (`connect-src` inchangé, CSP de `fpm.conf` inchangée).
- Pas de COEP sur le front (`grep Cross-Origin infra` : seulement CORP `same-origin` sur nos propres réponses, sans effet sur les extraits Deezer).
- `CATALOG_PROVIDER` : `compose.yaml` (x-app-env, défaut `none`, donc prod et compose.small) ; `compose.override.yaml` service `php` (défaut `deezer`). Documenté, commenté, dans `.env` et `.env.example`.
- `infra/deploy/README-deploy.md` : bloc « Catalogue externe Deezer » (non commercial, flag off sans accord, IP exposée au CDN Deezer, à mettre en page de confidentialité).

## Décisions prises
- Pas de `README.md` à la racine : la checklist prod est dans `infra/deploy/README-deploy.md`.
- La variable n'est PAS définie dans `.env` (commentée) : sinon `.env` écraserait le défaut `deezer` du dev. Pour couper en dev : `CATALOG_PROVIDER=none` dans `.env`.
- Seul `php` reçoit `deezer` en dev (les workers restent `none`, ils n'appellent pas le catalogue).

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Docker éteint : curl non exécuté, `nginx -t` non lancé (syntaxe : simple ajout de sources dans une chaîne). Le relancer au prochain `make up`.
- Backend (W-84) doit lire `CATALOG_PROVIDER` (`none|deezer`).

## Commande pour vérifier
`curl -sI http://localhost:8090/ | grep -i content-security-policy | grep -o "img-src[^;]*;\|media-src[^;]*;"` (attendu : `https://*.dzcdn.net` dans les deux) ; `curl -sI http://localhost:8090/ | grep -ic cross-origin-embedder` (attendu 0) ; `docker compose config | grep CATALOG`.
