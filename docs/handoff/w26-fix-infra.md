## Fait
- Minimum Symfony vérifié passé de 7.4.13 à 7.4.23 dans infra/scripts/check-symfony-security.sh (commentaire, test, message FAIL) et Makefile (libellés audit et audit-symfony). Rien dans .github (absent). backend/ non touché.
- make audit : exit=0 ; PHP 8.4.27, security-http 7.4.23, http-kernel 7.4.23 OK ; composer : aucune advisory.

## Décisions prises
- Aucune. Référence CVE du commentaire du script laissée telle quelle (CVE-2026-48489).

## Contrat d'API modifié ? (oui/non + détail)
- Non.

## Reste à faire / points ouverts
- npm audit signale 10 vulnérabilités modérées (minimatch via eslint/typescript-eslint, GHSA-g3hv-75wg-m249), non bloquantes pour make audit ; correctif = upgrade majeur eslint, à voir côté frontend.
- Commentaire du script : citer éventuellement CVE-2026-118513 à 118516.

## Commande pour vérifier
make audit; echo "exit=$?"
