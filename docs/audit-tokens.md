# Audit de consommation de tokens — Wave (30/10/2026)

Périmètre : projet `/Users/nakib/wave` et config Claude Code globale. Source des chiffres : les transcripts
locaux de la session lead Wave (`~/.claude/projects/-Users-nakib/6ff62884…jsonl`, du 23/10 au 30/10) et de
ses 6 sous-agents, mesurés par script (champs `usage` de chaque appel API). Ce qui est **estimé** est signalé.

## Ce que disent les mesures

| Session | Modèle | Appels | Cache read total | Contexte moyen / appel | Contexte max | Compactions |
|---|---|---|---|---|---|---|
| Lead (toi + moi) | Opus 5.5 | 205 | 34,8 M | 170 k | 312 k | 0 |
| backend | Opus 5.5 | 310 | **113,1 M** | **365 k** | **669 k** | 0 |
| media-infra | Opus 5.5 | 257 | 63,0 M | 245 k | 457 k | 0 |
| frontend | Opus 5.5 | 208 | 62,2 M | 299 k | 522 k | 0 |
| qa-security | Opus 5.5 | 146 | 31,0 M | 213 k | 347 k | 0 |
| architect (2 lancements) | Opus 5.5 | 56 | 6,6 M | 120 k | 194 k | 0 |
| **Total Wave** | | 1 182 | **≈ 311 M** | | | |

- Les sous-agents pèsent **89 %** des relectures de cache (276 M sur 311 M).
- Les 5 coéquipiers ont été lancés le 24/10 en `general-purpose`, sans `model` (donc Opus hérité), en
  arrière-plan, puis **réveillés par SendMessage pendant 5 jours** (76 messages du lead) sans jamais être
  relancés ni compactés.
- Ton chiffre `/usage` (17,1 M cache read) couvre une fenêtre plus courte que mes 311 M, mais le profil est le
  même.

**Ton hypothèse est confirmée, avec une nuance.** Ce ne sont pas des fichiers énormes lus d'un coup. Ce sont
des sessions qui ne meurent jamais : chaque appel API relit tout l'historique de l'agent. Un agent à 365 k de
contexte qui lance `docker compose ps` relit 365 k tokens pour produire 50 tokens. Le ratio de 345 lus pour 1
produit vient de là. Il est multiplié par le nombre d'agents vivants, puis par le lead qui relit ses 170 k
à chaque relais.

> Remarque : les `output_tokens` des transcripts de sous-agents semblent sous-comptés (≈ 12 k au total, c'est
> invraisemblable). Je ne m'appuie donc que sur les cache reads, qui sont cohérents.

---

## 1. Diagnostic : les 5 sources, par impact estimé

1. **Coéquipiers persistants sans remise à zéro** (≈ 75-80 % du total, estimation : 276 M de cache read des
   sous-agents, moins ce qu'un cycle sain coûterait). backend a tourné 310 appels sans compaction ; son
   contexte est passé de ~30 k à 669 k. La moitié de ses appels se sont faits au-delà de 300 k. Ce contexte,
   c'est surtout de l'historique de lots déjà commités (phase 0, phase 1, corrections).
2. **Session lead ouverte depuis 7 jours** (≈ 10 %, 34,8 M). Chaque relais entre agents (76 SendMessage) et
   chaque `git log`/`docker` relit 170 k en moyenne. Aucun `/clear` entre les phases, alors que
   `docs/etat-projet.md` existe déjà et ferait un parfait point de reprise.
3. **Docs de référence relues en entier par chaque agent** (estimation : 5 à 10 % ; ~180 k caractères ≈ 50 k
   tokens pour BRIEF + CONVENTIONS + DATA_MODEL + TICKETS + openapi.yaml, à ~3,6 car./token). On le voit
   dans les transcripts : `cat docs/BRIEF.md docs/CONVENTIONS.md docs/adr/…` (27 k car.), `Read openapi.yaml`
   (34 k car. par lecture), plus des relectures de fichiers `tool-results` du lead (43 k car. chacune, lues 2
   fois). À 5 agents, ces docs passent 5 fois en contexte, puis se font relire à chaque tour suivant.
4. **Tout passe par Opus, même le mécanique.** media-infra (Dockerfiles, compose, nginx) et les phases
   d'exploration ou de vérification (`docker compose ps`, `git status`, relectures de logs) n'ont pas besoin
   d'Opus. Le travail d'architecture, la revue sécu et le debug complexe, si. Impact modéré : le
   gaspillage porte sur la taille du contexte plus que sur le prix du modèle.
5. **Sorties d'outils et captures non bornées** (≈ 2-5 %, estimation). frontend a lu 19 PNG de
   captures E2E (≈ 1,5 k tokens chacune, selon la taille) qui restent ensuite dans son contexte. Les sorties
   `docker`/`git show` vont jusqu'à 16 k car. Rien ne borne `phpunit`/`npm test`/`docker logs` à part la
   discipline de l'agent. Hors Wave, la session Chrome/Notion du 28/10 (`2546b3f0`) a injecté 2,5 Mo de
   résultats `browser_batch` et a dû compacter 2 fois. C'est le même problème, sur un autre chantier.

Points vérifiés et **non** problématiques :
- **CLAUDE.md** : aucun (ni global, ni dans `wave/`). Ce qui charge à chaque session, c'est `MEMORY.md`
  (5,1 k car. ≈ 1,5 k tokens) plus les descriptions des 18 agents globaux, soit environ 2-3 k tokens. C'est
  négligeable. Ces 40 k car. de définitions ne sont pas chargées en entier, seulement leurs descriptions.
- **MCP** : Notion, Claude Docs, Chrome… sont tous en outils différés (chargés via ToolSearch). Leur coût est
  faible tant qu'on ne les appelle pas. Aucun `.mcp.json` dans Wave.
- **Dossiers lourds** : `backend/vendor` (232 Mo), `frontend/node_modules` (241 Mo) et `backend/var`
  (89 Mo) sont dans `.gitignore`, donc ignorés par Grep/Glob. Ils ne sont **pas** protégés contre `cat`, `find`
  ou `grep -r` en Bash. Je n'ai pas trouvé de lecture accidentelle dans les transcripts : c'est un risque, pas
  une fuite actuelle.
- **Settings** : `model: opus`, `effortLevel: medium` partout. L'effort est déjà raisonnable.
  `~/.claude/settings.local.json` contient 46 permissions Bash, dont des requêtes psql de manga-stream
  obsolètes. C'est du désordre, mais ça ne coûte aucun token (les permissions ne vont pas dans le prompt).

---

## 2. Ajustements proposés

### A. Coéquipiers éphémères : un agent = un ticket ou un lot, puis il meurt
- **Ce que ça change** : au lieu de réveiller backend par SendMessage pour le lot suivant, on lance un
  **nouvel** agent par lot avec un brief court : ticket, fichiers concernés, sections de doc à lire. L'agent
  finit en écrivant sa passation dans un fichier (voir D), puis s'arrête. SendMessage ne sert plus qu'aux
  corrections du **même** lot.
- **Gain estimé** : contexte moyen ramené de 210-365 k à ~60-100 k (estimation : brief 5 k + docs ciblées
  15 k + travail du lot ; c'est ce que mesurait architect, 120 k de moyenne sur un lot complet). Soit **−60 à
  −75 %** sur les 276 M des sous-agents.
- **Qualité : aucune perte, plutôt un gain.** Au-delà de 200-300 k, l'agent travaille avec beaucoup de
  bruit (tickets clos, anciennes erreurs). Un contexte propre limite les confusions. Ce qui doit survivre
  (décisions, conventions, état) est déjà dans `docs/`.
- **Effort** : nul côté config. C'est une règle que j'applique en tant que lead.

### B. Le lead se remet à zéro à chaque fin de phase
- **Ce que ça change** : en fin de phase, je mets `docs/etat-projet.md` à jour, puis tu fais `/clear`. La
  session suivante démarre par « lis docs/etat-projet.md et continue ». En cours de phase, tu fais
  `/compact` dès que le contexte dépasse ~150 k.
- **Gain estimé** : contexte lead de 170 k en moyenne à ~50-80 k, soit **−50 %** sur 35 M (estimation).
- **Qualité : aucune**, si `etat-projet.md` est tenu à jour : c'est déjà la source de vérité.
- **Effort** : 1 min par phase.

### C. Un `CLAUDE.md` court qui sert d'index aux docs
- **Ce que ça change** : il dit aux agents **où** chercher, pour qu'ils lisent une section par `grep -n`
  plutôt que tout `DATA_MODEL.md` ou tout `openapi.yaml`. Il remplace aussi la partie fixe des briefs (stack,
  règles PHP 8.4.27, commandes), donc des briefs plus courts.
- **Gain estimé** : −20 à −40 k tokens par démarrage d'agent (estimation : docs lues en entier ≈ 50 k →
  sections ciblées ≈ 10-15 k), et autant en moins relu à chaque tour suivant.
- **Qualité : aucune.** L'agent a toujours accès à tout, il commence juste par l'index.
- **Effort** : 5 min. Contenu à créer (`/Users/nakib/wave/CLAUDE.md`) :

```markdown
# Wave — repères pour agents

Monorepo : `backend/` Symfony 7.4 + API Platform + Postgres + Messenger · `frontend/` React/TS/Vite ·
`infra/` + `compose*.yaml` (MinIO, FFmpeg). Code en anglais, README en français.

## Docs : lire par section, jamais en entier
- État courant et suite : `docs/etat-projet.md` (à lire en premier)
- Ticket : `grep -n "^## <ID>" docs/TICKETS.md`, puis `sed -n` sur la plage
- Entité : `grep -n "^## " docs/DATA_MODEL.md` pour la table des sections, puis la section utile
- Endpoint : `grep -n "<path>" docs/openapi.yaml`, puis lire ~60 lignes autour. Ne jamais lire le fichier entier (≈ 25 k tokens).
- Conventions : `docs/CONVENTIONS.md`, seulement la section de ton périmètre · Sécurité : `docs/SECURITY.md` · Décisions : `docs/adr/`

## Règles de sortie (contexte)
- Tests : `vendor/bin/phpunit --no-progress 2>&1 | tail -40` · `npm test -- --reporter=dot 2>&1 | tail -40`
- Logs : `docker compose logs --tail=50 <service>` ; jamais `logs` sans `--tail`
- Jamais de `cat`/`grep -r`/`find` dans vendor/, node_modules/, var/, ni sur les lockfiles
- Captures E2E : vérifier par assertions DOM ; ne lire un PNG que pour un bug visuel, au plus 1-2 par lot

## Invariants
- PHP épinglé `php:8.4.27-fpm` minimum (PHP2Shell) ; CI en échec si < 8.4.27
- Passation de fin de lot : `docs/handoff/<lot>-<role>.md` (≤ 30 lignes : fait, fichiers, tests, reste)
```

### D. Passation par fichier plutôt que par relais dans le contexte du lead
- **Ce que ça change** : chaque agent termine par `docs/handoff/<lot>-<role>.md` (30 lignes au plus) et ne
  renvoie au lead qu'une ligne : « fait, voir handoff ». Le lead transmet le **chemin** du fichier à l'agent
  suivant, pas son contenu.
- **Gain estimé** : les 76 relais ont coûté ≈ 13 M de relecture côté lead (estimation : 76 × 170 k). Avec un
  lead à ~60 k et des retours d'une ligne, environ −70 % sur ce poste.
- **Qualité : aucune**, et même un gain : la passation devient traçable dans git.
- **Effort** : une ligne dans chaque brief (inclus dans E).

### E. Agents Wave définis une fois, avec un modèle par rôle
- **Ce que ça change** : des fichiers `.claude/agents/wave-*.md` remplacent les briefs de 2,5-5 k caractères
  recopiés à chaque lancement. Chaque rôle a un modèle **explicite**.
- **Gain estimé** : briefs ramenés à ~1 k car. ; le reste relève de A et C.
- **Qualité** : voir le tableau en section 4. Le seul passage à Sonnet (media-infra) est **à surveiller**.
- **Effort** : 10 min. Exemple complet (`.claude/agents/wave-backend.md`), les autres rôles sur le même modèle :

```markdown
---
name: wave-backend
description: Backend Wave (backend/ : Symfony 7.4, API Platform, Doctrine, Messenger, PHPUnit). Un lancement = un ticket ou un lot.
model: opus
---
Tu es backend sur Wave. Lis d'abord CLAUDE.md puis docs/etat-projet.md, puis UNIQUEMENT les sections
de docs citées dans ta mission. Périmètre exclusif : backend/. Ne touche ni frontend/ ni infra/.
Fin de mission : tests verts (sortie bornée), écris docs/handoff/<lot>-backend.md (≤ 30 lignes),
réponds au lead en une ligne. Ne commite pas : le lead commite.
```
Les autres : `wave-frontend` (opus), `wave-architect` (opus), `wave-qa-security` (opus),
`wave-media-infra` (**sonnet**, à surveiller), `wave-scout` (**haiku**, en lecture seule :
`tools: Read, Grep, Glob, Bash`, pour répondre à « où est X / quel fichier touche Y »).

### F. Garde-fous de lecture (settings projet)
- **Ce que ça change** : interdit l'outil Read sur les dossiers lourds (Grep/Glob les ignorent déjà via
  `.gitignore`).
- **Gain** : préventif. Aucune fuite mesurée, mais une seule lecture de lockfile ou de log de `var/` = 50-200 k
  tokens, relus ensuite à chaque tour.
- **Qualité : aucune.** **Effort** : 2 min. `/Users/nakib/wave/.claude/settings.json` :

```json
{
  "permissions": {
    "deny": [
      "Read(./backend/vendor/**)",
      "Read(./frontend/node_modules/**)",
      "Read(./backend/var/**)",
      "Read(./**/composer.lock)",
      "Read(./**/package-lock.json)"
    ]
  }
}
```

### G. Plafond de contexte avant compaction automatique (à vérifier dans ta version)
- **Ce que ça change** : les contextes ont atteint 669 k sans compaction. Tes sessions Opus tournent donc
  probablement avec la fenêtre de 1 M, et l'auto-compaction ne se déclenche qu'en fin de fenêtre. À vérifier
  avec `/model` et `/context`. Deux leviers possibles : choisir la variante Opus 200 k pour les coéquipiers,
  ou abaisser le seuil d'auto-compaction (variable `CLAUDE_AUTOCOMPACT_PCT_OVERRIDE` dans `env` des settings,
  **je ne l'ai pas vérifiée dans ta version** : à tester avant de s'y fier).
- **Gain** : filet de sécurité si A/B ne sont pas respectés.
- **Qualité : à surveiller.** Une compaction résume l'historique, donc l'agent perd des détails fins. Avec A
  et B appliqués, ce filet ne devrait presque jamais se déclencher. Je le classe en dernier.

### H. Statusline : contexte et coût visibles en permanence
- **Ce que ça change** : la taille du contexte de la session courante s'affiche en bas de l'écran, ce qui
  rend les signaux de la section 5 visibles sans y penser.
- **Qualité : aucune.** **Effort** : 3 min. Script `~/.claude/statusline.sh` (il lit la dernière `usage` du
  transcript) :

```bash
#!/bin/bash
in=$(cat)
t=$(echo "$in" | jq -r '.transcript_path')
m=$(echo "$in" | jq -r '.model.display_name')
c=$(echo "$in" | jq -r '.cost.total_cost_usd // 0')
ctx=$(tail -r "$t" 2>/dev/null | grep -m1 '"cache_read_input_tokens"' | jq -r '.message.usage | (.cache_read_input_tokens + .cache_creation_input_tokens + .input_tokens)')
printf "%s | ctx %sk | \$%.2f" "$m" "$(( ${ctx:-0} / 1000 ))" "$c"
```
et dans `~/.claude/settings.json` : `"statusLine": {"type": "command", "command": "~/.claude/statusline.sh"}`.

---

## 3. Quick wins (moins de 10 min)

1. **Ne plus réveiller les 5 agents de la phase 1** : ils ont tous entre 213 et 669 k de contexte. La phase 2
   démarre avec des agents neufs (règle A). Aucune config à toucher.
2. **`/clear` de la session lead** après une mise à jour de `docs/etat-projet.md` (règle B). Elle a 7 jours
   et 312 k de contexte.
3. Créer `CLAUDE.md` (C) et `.claude/settings.json` (F) : copier-coller depuis ce rapport.
4. Statusline (H).

À eux seuls, les points 1 et 2 visent le gros des 311 M.

---

## 4. Nouvelle organisation de l'équipe

| Rôle | Modèle | Quand | Qualité |
|---|---|---|---|
| Lead (session principale) | Opus | Toujours | Indispensable : arbitrage, relais, commits |
| architect | Opus | Début de phase, ou décision de contrat | Indispensable |
| backend | Opus | 1 lancement par lot | Gardé sur Opus : API Platform, sécurité JWT et Messenger, ce sont des pièges |
| frontend | Opus | 1 lancement par lot | Gardé sur Opus (état, streaming Range, lecteur) |
| qa-security | Opus | Revue de fin de lot, sur le diff seul (`git diff <base>..HEAD`) | Indispensable, c'est la revue finale |
| media-infra | **Sonnet 5.5** | Lots infra | **À surveiller** : compose, nginx et FFmpeg sont bien couverts par Sonnet. Risque sur les cas limites FFmpeg (rattrapés par qa-security). Gain sur ce rôle : ~40 % de coût par token (estimation tirée de l'écart de prix public Opus/Sonnet ; le multiplicateur exact sur ton forfait n'est pas publié) |
| scout | **Haiku 5.5** | Recherche ponctuelle en lecture seule | Aucun impact : il localise, il ne décide pas |

**Parallélisme** : **3 agents vivants au maximum** (typiquement backend + frontend sur le même lot, plus
media-infra si besoin). qa-security passe **après**, jamais en parallèle sur un lot non terminé.

**Règles de passation** :
1. Brief de lancement de 1 k caractères au plus : ticket(s), fichiers ou sections à lire, critère de fin. Le
   reste est dans CLAUDE.md et dans la définition de l'agent.
2. Fin de lot : `docs/handoff/<lot>-<role>.md`, une ligne au lead, puis l'agent est arrêté.
3. SendMessage seulement pour corriger le **même** lot. Un nouveau lot, c'est un nouvel agent.
4. qa-security reçoit le diff et les handoffs, pas l'historique des agents.

**Équipe, agent seul ou sous-agent ponctuel ?**
- **Agent seul (lead, sans équipe)** : tâche qui tient dans un seul périmètre et en moins d'une heure (bug,
  petite feature, doc).
- **Sous-agent ponctuel** (scout ou Explore) : question de recherche dont seule la conclusion compte.
- **Équipe** : lot qui touche au moins 2 périmètres en parallèle (backend + frontend) avec un contrat déjà
  figé. En dessous, le coût fixe de l'équipe (chaque agent recharge brief et docs) dépasse le gain.

---

## 5. Mesurer, budgéter, routine de 8 h

**Mesure** :
- `/usage` : % des limites de session et hebdomadaire. Note la valeur en début de journée, puis toutes les
  heures.
- `/context` : répartition du contexte courant. `/cost` : coût de la session (indicatif sur forfait).
- La statusline H pour le contexte en continu.

**Budget indicatif** (estimation : ta semaine du 23 au 30/10 a consommé ~91 % du quota pour ~311 M de cache
read Opus, soit ≈ 3,4 M par point de quota) :
- **Hebdomadaire** : pour 5 jours de 8 h, 20 % par jour, soit **≤ 2,5 %/h**. Ta semaine passée tournait
  autour de 13 %/jour en continu, avec 5 agents.
- **Session** : si 1 h vidait la limite de session, viser **≤ 20 % de la limite de session par heure** pour
  tenir la fenêtre. Vérifie à 30 min : au-dessus de 10 %, appliquer la routine ci-dessous.
- Avec A, B et C appliqués, j'estime la consommation à 25-35 % de l'actuelle (−65 à −75 %) à travail égal.
  C'est l'ordre de grandeur nécessaire pour passer de ~1 h à ~8 h. **À confirmer par une journée de mesure** :
  c'est une projection.

**Signaux d'action** :
- Contexte > 150 k : `/compact` avec une consigne (« garde les décisions et l'état du lot X »).
- Fin de lot ou de phase : mettre à jour `etat-projet.md`, puis `/clear`.
- Changement de sujet (autre projet, autre chantier) : nouvelle session, jamais dans la même.
- Agent de plus de 150 k ou qui a fini son lot : on l'arrête, le suivant part neuf.
- `/usage` qui monte de plus de 3 points en une heure : chercher l'agent ou la sortie qui gonfle.

**Routine de journée** :
1. *Début* : nouvelle session, « lis docs/etat-projet.md », `/usage` noté. Je propose le lot du jour et
   l'équipe minimale.
2. *Pendant* : agents lancés par lot avec briefs courts ; passation par fichiers ; un coup d'œil à la
   statusline ; `/compact` à 150 k.
3. *Fin de tâche* : qa-security sur le diff, commit, handoff, agents arrêtés.
4. *Fin de journée* : `etat-projet.md` à jour et commité, `/usage` noté (pour calibrer le budget), session
   fermée.
