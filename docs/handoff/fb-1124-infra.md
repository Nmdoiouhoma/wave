# fb-1124 — media-infra : sortie réseau de php (catalogue Deezer)

## Fait
- `compose.yaml` : nouveau réseau `egress` (bridge non interne) ; `php` = `[internal, egress]`.
- Seul `php` en a besoin : les appels Deezer sont dans les State providers (CatalogArtist*Provider), aucun handler worker.
- compose.small.yaml / prod : php hérite de compose.yaml, rien à changer.
- `infra/deploy/README-deploy.md` : section « Sortie réseau de php » (pare-feu 443 vers api.deezer.com recommandé).

## Isolement
- `egress` ne contient que php (vérifié `docker network inspect wave_egress`) : ni minio, postgres, nginx.
- `storage` (nginx↔minio, F5) et la chaîne d'IP client inchangés.

## Preuve
- `docker compose exec -T php php -r 'file_get_contents("https://api.deezer.com/search/artist?q=daft+punk&limit=1")'` → ok.

## Contrat
- Aucun changement de contrat. Décision : réseau dédié `egress` plutôt que réutiliser `edge`.
