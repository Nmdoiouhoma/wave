# Handoff repost-ranking — backend

Reste de W-80 : les poids de repost de l'ADR 015 §6 sont branchés dans « Pour toi ». Aucune migration (table `repost` déjà créée).

## Changements
- `Feed/SqlFeedRanker.php` : F = 0,75 si un utilisateur suivi par le spectateur a reposté le post dans les 7 derniers jours (entre « auteur suivi » = 1 et « like d'un suivi » = 0,5). Conditions : reposteur non suspendu, post à audience publique (piste publique ET (allowClips OU auteur = propriétaire)), reposteur différent de l'auteur. Le sous-select est dans la requête de phase u : aucune requête de plus.
- `Recommendation/TrendingRecomputer.php` : le score de tendance du post ajoute 4 × décroissance 24 h par repost (fenêtre 3 jours), mêmes filtres (reposteur non suspendu, post visible, audience publique).
- `Recommendation/AffinityRecomputer.php` : les reposts du spectateur comptent 3 (genre et artiste, demi-vie 30 j) ; `activeUserIds` inclut les reposteurs récents.
- `Feed/ForYouWeights.php` : commentaire seul (constantes REPOST_* déjà présentes, désormais utilisées).

## Décisions
- Les filtres de visibilité du repost sont aussi appliqués dans le score de tendance (global) : un repost invalide n'a aucun effet nulle part. Les candidats restent filtrés comme avant (post masqué ou auteur suspendu = exclu).
- Le cas « repost d'un post à audience restreinte » est couvert côté tendance (test) ; côté F il est inatteignable par un test de classement (l'auteur de la piste est forcément suivi, donc F = 1).

## Tests (ForYouFeedTest, 4 ajoutés, 17 verts)
- Repost d'un suivi > like d'un suivi ; reposts sans effet (plus de 7 jours, reposteur suspendu, reposteur non suivi, post masqué absent) ; tendance/affinité (8,0 pour 2 reposts, suspendu et restreint = 0, affinité genre) ; nombre de requêtes identique avec 0 et 8 reposts.

## Vérif
`verif.sh back` : voir verdict dans le retour du lead.
