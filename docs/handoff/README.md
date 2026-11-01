# Passations entre agents

Un fichier par agent et par lot : `<lot>-<role>.md`, 30 lignes max, toujours ces rubriques :

```
## Fait
## Décisions prises
## Contrat d'API modifié ? (oui/non + détail)
## Reste à faire / points ouverts
## Commande pour vérifier
```

Retour au lead : une ligne, qui signale explicitement une décision ou un changement de contrat à lire.
Le lead transmet le chemin du fichier à l'agent suivant, pas son contenu, et met à jour `docs/etat-projet.md`.
