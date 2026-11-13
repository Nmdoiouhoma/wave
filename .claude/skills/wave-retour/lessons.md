# Leçons wave-retour (les plus récentes en haut, 15 max)
<!-- Format : AAAA-MM-JJ · symptôme → cause → règle. Une leçon intégrée au SKILL.md ou au script est retirée d'ici. -->
- 2026-11-13 · il a collé par erreur un brief d'un autre sujet (cylindre) avec ses notes Wave → paste mélangé → ne traiter que la partie Wave et lui signaler le reste, sans agir dessus.
- 2026-11-09 · 500 sur like, table `lock_keys` absente en dev seulement → migration modifiée après exécution locale → sur une 500, comparer `doctrine_migration_versions.executed_at` au commit de la migration avant de soupçonner le code.
- 2026-10-27 · « fichier pas un audio valide » sur un MP4 → vidéo sans piste son → `ffprobe` sur son fichier avant toute hypothèse de bug.
- 2026-10-27 · questions à choix refusées → options incomplètes → toujours une option « comme l'existant » et mon avis en premier.
- 2026-10-26 · incohérences d'interface (extraits sur morceau privé) → il attend un avis → répondre d'abord par mon avis, puis consigner.
- 13/11 : un agent backend a modifié une entité (last_login_at) avant d'appliquer la migration pendant que l'utilisateur testait en direct → toute l'API en 500. Brief backend : « migration appliquée en dev AVANT de toucher les entités mappées ».
