# Assistant IA — documentation d'ensemble

Ce document est le point d'entrée de l'assistant IA de « Gestion des Notes ». Il réunit :
- l'installation ;
- l'organisation du code ;
- la correspondance avec le **Sujet 7 : conception de logiciels intégrant l'IA** (`but.md`) ;
- les réponses aux questions du sujet.

Les détails se trouvent dans les autres documents de `docs/` :

| Document | Contenu |
|---|---|
| [architecture.md](architecture.md) | Diagramme de composants, diagramme de séquence, flux d'une action sensible |
| [contrat_api.md](contrat_api.md) | Contrat d'entrée et de sortie : versions, types, limites, erreurs |
| [securite_donnees.md](securite_donnees.md) | Contrôle d'accès, injection de prompt, minimisation, conservation |
| [evaluation.md](evaluation.md) | Protocole d'évaluation et réponses attendues |
| [couts.md](couts.md) | Estimation comparative des coûts : API externe ou modèle local |
| [diagrammes/](diagrammes/) | Versions PlantUML des diagrammes |

---

## 1. Ce que fait l'assistant

Un bouton en bas à droite de chaque page ouvre une discussion. L'assistant répond selon le rôle de l'utilisateur connecté :

| Rôle | Exemples de questions | Données accessibles |
|---|---|---|
| Élève | « Quelle est ma moyenne au 1er trimestre ? », « Comment imprimer mon bulletin ? » | Ses propres notes |
| Parent | « Comment va mon fils en mathématiques ? » | Les notes de ses enfants |
| Enseignant | « Quels élèves de 6e A sont sous 10 ? », « Prépare une note de 15 pour… » | Les classes, et la saisie dans ses matières seulement |
| Direction | « Quelle classe a la meilleure moyenne au T2 ? » | Tout l'établissement |

**L'assistant ne modifie jamais la base de données.** Quand on lui demande une saisie, il renvoie un lien vers le formulaire `notes/saisir.php` déjà rempli. L'enseignant vérifie le formulaire, puis le valide lui-même.

## 2. Installation

1. **Base de données**
   - Nouvelle installation : `sql/database.sql` crée aussi les tables `ai_logs` et `ai_cache`.
   - Base existante : importer `sql/migration_assistant_ia.sql`.
2. **Configuration** : copier `.env.example` en `.env`, puis renseigner au moins une clé :
   - `GEMINI_API_KEY`, gratuite sur <https://aistudio.google.com/apikey> ;
   - `GROQ_API_KEY`, gratuite sur <https://console.groq.com/keys>.

   Un fournisseur sans clé est simplement sauté. Gemini et Groq tournent chez leur fournisseur : ils ne chargent pas la machine.
3. **Secours local (facultatif, conseillé)** :
   - installer Ollama (<https://ollama.com>) ;
   - lancer `ollama pull qwen2.5:7b` sur un GPU de 8 Go, ou `qwen2.5:3b` sur une machine modeste. Sur un portable avec un GPU de 4 Go (RTX 3050 par exemple), seul le 3b tient en mémoire vidéo. Le 7b déborde sur la RAM et devient très lent.
4. **Données de démonstration** : `php scripts/demo_data.php` crée des élèves, des notes et un compte de chaque rôle.
5. **Vérifications** :
   - `php scripts/test_outils.php` vérifie les droits et les chiffres des outils, **sans modèle** ;
   - `php scripts/eval_assistant.php` lance le protocole d'évaluation complet (voir [evaluation.md](evaluation.md)).

Pour changer de fournisseur ou de modèle, il suffit de modifier `.env` (`AI_PROVIDERS`, `*_MODEL`, `*_BASE_URL`).

## 3. Organisation du code

| Fichier | Rôle |
|---|---|
| `js/assistant.js` | Interface : fenêtre de discussion, affichage en `textContent` (pas de HTML injecté), boutons 👍 👎 🚩 |
| `api/assistant.php` | Point d'entrée HTTP : session, jeton CSRF, validation de l'entrée, quota, cache, réponse de secours, journal |
| `includes/ai/Assistant.php` | Orchestration : prompt système, boucle d'appels d'outils, validation de la réponse, nouvelles tentatives, bascule de fournisseur |
| `includes/ai/AIProvider.php` | Interface du fournisseur de modèle (contrat interne) |
| `includes/ai/OpenAICompatibleProvider.php` | Implémentation unique pour Groq, Ollama et tout service compatible `/chat/completions` |
| `includes/ai/GeminiProvider.php` | Adaptateur pour l'API native de Google Gemini (format différent, traduit dans les deux sens) |
| `includes/ai/http.php` | Appel HTTP commun aux fournisseurs : délai, certificats, codes d'erreur du contrat |
| `includes/ai/tools.php` | Outils métier : requêtes SQL filtrées selon le rôle de la session, minimisation des données |
| `includes/ai/journal.php` | Journal `ai_logs`, quota, cache `ai_cache`, calcul du coût |
| `includes/bulletin.php` | Calcul des moyennes et des rangs, partagé par les pages et l'assistant |
| `modules/supervision_ia/index.php` | Supervision (direction) : disponibilité, latence, bascules, coûts, avis, signalements |

## 4. Correspondance avec le sujet

### C. Travail pratique : livrables

| Livrable | Où |
|---|---|
| Diagramme de composants | [architecture.md §2](architecture.md#2-diagramme-de-composants), `diagrammes/composants.puml` |
| Diagramme de séquence d'une requête | [architecture.md §3](architecture.md#3-diagramme-de-séquence-dune-requête), `diagrammes/sequence.puml` |
| Contrat d'entrée et de sortie | [contrat_api.md](contrat_api.md) |
| Exemple d'API ou prototype | `api/assistant.php` et le widget, utilisables dans l'application |
| Erreurs et indisponibilités | [contrat_api.md §1](contrat_api.md#réponse-en-cas-derreur), §5 ci-dessous |
| Protection des données | [securite_donnees.md](securite_donnees.md) |
| Protocole d'évaluation avec réponses attendues | [evaluation.md](evaluation.md), `scripts/eval_assistant.php` |
| Comparaison des coûts local / API | [couts.md](couts.md) |

### A. Contenu obligatoire

| # | Point | Où |
|---|---|---|
| 1 | Règles, ML, DL, modèles génératifs | §6.1 ci-dessous |
| 2 | Architectures d'intégration | §6.2 ci-dessous, [architecture.md §2](architecture.md#2-diagramme-de-composants) |
| 3 | Composants d'une application intelligente | [architecture.md §5](architecture.md#5-composants-dune-application-intelligente) |
| 4 | Entraînement, validation, inférence, post-traitement | §6.3 ci-dessous |
| 5 | Formats, validation, schémas, résultats invalides | [contrat_api.md](contrat_api.md), `Assistant::validerReponse()` |
| 6 | Contrat application ↔ modèle | [contrat_api.md §2 et §3](contrat_api.md#2-service--fournisseur-de-modèle--interface-aiprovider) |
| 7 | Latence, quotas, coûts, concurrence, disponibilité | [couts.md](couts.md), `.env` (`AI_TIMEOUT`, `AI_RATE_LIMIT`), supervision |
| 8 | API externe ou modèle local | [couts.md §5](couts.md#5-conclusion-pour-ce-projet), [securite_donnees.md §5](securite_donnees.md) |
| 9 | Mécanismes de secours | §5 ci-dessous |
| 10 | Sécurité, accès, confidentialité, entrées malveillantes | [securite_donnees.md](securite_donnees.md) |
| 11 | Évaluation fonctionnelle et technique | [evaluation.md](evaluation.md) |
| 12 | Supervision en production | `modules/supervision_ia`, [evaluation.md §4](evaluation.md#4-indicateurs-mesurés) |
| 13 | Une sortie probabiliste n'est pas une vérité | [securite_donnees.md §6](securite_donnees.md#6-ce-que-la-sortie-du-modèle-nest-pas), §7 ci-dessous |
| 14 | Remplacer un fournisseur ou un modèle | [contrat_api.md §2](contrat_api.md#versions-et-compatibilité), §7 ci-dessous |

## 5. Erreurs et indisponibilités

Les mécanismes de secours s'enchaînent du plus léger au plus dégradé :

1. **Nouvelle tentative contrôlée** sur le même fournisseur, mais seulement pour une erreur passagère : délai dépassé, 429 ou 5xx. Il y a `AI_RETRIES` tentatives au plus, avec une attente croissante (0,5 s, puis 1 s). Une erreur 401 ou 400 n'est pas retentée.
2. **Bascule** vers le fournisseur suivant de `AI_PROVIDERS` (Gemini, puis Groq, puis Ollama). La réponse indique « via ollama » et la supervision compte les bascules.
3. **Cache** :
   - une même première question reçoit la réponse en cache pendant `AI_CACHE_TTL` ;
   - si tous les fournisseurs échouent, une réponse de moins de 24 h est renvoyée comme **réponse de secours**, avec sa date et un avertissement.
4. **Erreur claire** : HTTP 503 `ia_indisponible`, avec le message « L'assistant est momentanément indisponible. Vos notes restent consultables dans l'application. » L'application continue de fonctionner sans l'IA.
5. **Intervention humaine** :
   - toute écriture est validée par une personne ;
   - les réponses signalées 🚩 sont relues dans la supervision.

## 6. Notions théoriques appliquées au projet

### 6.1 Familles d'IA

| Famille | Principe | Dans ce projet |
|---|---|---|
| **Système à règles** | Des règles écrites par un humain (si… alors…) | Les droits d'accès, le calcul des moyennes et des rangs, la validation : **tout ce qui doit être exact reste en règles** |
| **Machine Learning** | Un modèle apprend une fonction à partir d'exemples (régression, arbres…) | Non utilisé. Une piste serait de prédire le risque d'échec à partir des notes |
| **Deep Learning** | Machine Learning à base de réseaux de neurones profonds | Base technique des modèles ci-dessous |
| **Modèle génératif (LLM)** | Réseau profond entraîné à prédire le token suivant ; il produit du texte | Gemini 3.8 Flash (Google), Qwen 3.8 27B (Groq), Qwen 2.5 (Ollama) : il **comprend la question, choisit un outil et rédige la réponse**, sans calculer lui-même les notes |

### 6.2 Architectures d'intégration

| Architecture | Exemple | Dans ce projet |
|---|---|---|
| API d'IA externe | Google Gemini, Groq, OpenAI, Mistral | Fournisseurs principaux (Gemini, puis Groq) |
| Modèle sur un serveur interne | Ollama ou vLLM sur un serveur de l'établissement | Secours : même API, une autre URL dans `.env` |
| Modèle embarqué dans l'application | Petit modèle exécuté dans le navigateur ou dans le processus PHP | Non retenu : PHP ne fait pas tourner de modèle efficacement, et le poste client est inconnu |
| **Hybride** | Combinaison des précédentes | **C'est notre choix** : externe en premier, local en secours, ordre réglable |

### 6.3 Cycle de vie d'un modèle

| Étape | Définition | Qui la fait ici |
|---|---|---|
| Entraînement | Ajuster les poids du modèle sur un grand corpus | Meta et Alibaba ; **nous n'entraînons rien** |
| Validation | Mesurer le modèle sur des données qui n'ont pas servi à l'entraîner, pour choisir une version | Nous, au niveau de l'application : `scripts/eval_assistant.php` compare les modèles sur notre jeu de questions avant de changer `*_MODEL` |
| Inférence | Utiliser le modèle figé pour produire une sortie | Le service d'inférence (Gemini, Groq, Ollama), appelé à chaque question |
| Post-traitement | Contrôler et transformer la sortie avant de l'utiliser | `Assistant::validerReponse()` (réponse vide refusée, raisonnement `<think>` retiré, longueur limitée), outils inconnus refusés, arguments revalidés, actions limitées à des liens de pré-remplissage, affichage en texte brut |

## 7. Réponses aux questions du sujet

**Pourquoi ne pas placer toute la logique métier dans un prompt ?**
- Un prompt est une consigne, pas une garantie : le modèle peut l'ignorer, surtout s'il est manipulé (« ignore tes consignes »).
- Un calcul fait par le modèle peut être faux.
- Le prompt ne se teste pas comme du code.

Ici, la logique est **dans le code** : droits dans `tools.php`, calculs dans `bulletin.php`. Le prompt sert seulement à décrire le ton et à rappeler d'utiliser les outils. Une injection de prompt ne donne donc accès à rien : l'outil filtre selon la session, pas selon ce que le modèle affirme (cas 4 et 7 de l'évaluation).

**Que se passe-t-il si le fournisseur devient indisponible ?**
La chaîne du §5 s'applique : nouvelle tentative, bascule vers Groq puis Ollama, réponse en cache, puis erreur 503 claire. Le reste de l'application ne dépend pas de l'IA et continue de fonctionner.

**Comment empêcher qu'une réponse générée soit utilisée sans validation dans une opération sensible ?**
- Aucun outil n'écrit en base.
- L'outil `proposer_saisie_note` vérifie les droits (matière de l'enseignant, élève et trimestre existants, note entre 0 et 20), puis renvoie seulement un **lien de pré-remplissage**.
- L'enregistrement passe par le formulaire normal : session, jeton CSRF et validations serveur, après relecture par l'enseignant.

Voir [architecture.md §4](architecture.md#4-flux-dune-action-sensible-saisie-de-note).

**Comment changer de modèle sans modifier toute l'application ?**
- L'application dépend de l'interface `AIProvider`, pas d'un fournisseur.
- Tous les services compatibles `/chat/completions` passent par une seule classe.
- Un fournisseur au format différent demande un **adaptateur**, et rien d'autre. Gemini en est l'exemple : `GeminiProvider` (environ 200 lignes) traduit les messages, les outils et les schémas vers le format de Google, puis normalise la réponse. `Assistant`, les outils, l'API et l'interface n'ont pas changé.
- Changer de modèle ou de fournisseur revient à modifier `.env` (`AI_PROVIDERS`, `*_MODEL`, `*_FORMAT`).
- Avant l'adoption, `eval_assistant.php` vérifie que le nouveau modèle atteint la même qualité.
- Le modèle utilisé est enregistré dans chaque réponse et chaque ligne du journal.

**Quels critères pour choisir entre un modèle local et un service externe ?**
- la confidentialité des données (des notes d'élèves) ;
- le volume de questions et le seuil de rentabilité ;
- la qualité mesurée sur notre jeu de questions ;
- la latence ;
- la disponibilité exigée ;
- les compétences et le matériel disponibles.

Voir [couts.md](couts.md) et [securite_donnees.md §5](securite_donnees.md).

**Comment mesurer la qualité au-delà du simple « ça marche » ?**
Avec des indicateurs mesurés sur un **jeu de questions dont la réponse est calculée** à partir de la base :
- exactitude des chiffres ;
- refus corrects des accès interdits ;
- absence d'écriture ;
- stabilité sur plusieurs passages ;
- latence p95 ;
- coût pour 1 000 questions.

En production, s'y ajoutent les avis des utilisateurs, les signalements, les bascules et le taux d'erreur.

Voir [evaluation.md](evaluation.md).

## 8. Limites et améliorations possibles

- **Les réponses restent probabilistes.** Les chiffres viennent des outils, mais le modèle peut mal les recopier ou mal les interpréter. Une amélioration serait de vérifier automatiquement que chaque nombre de la réponse figure dans un résultat d'outil.
- **La partie fixe du prompt coûte cher** (près de 80 % des tokens, voir [couts.md](couts.md)). Il faudrait ne proposer que les outils utiles à la question, ou utiliser le cache de prompt du fournisseur.
- **Le cache est limité** à des questions identiques, et seulement en début de conversation.
- **Le petit modèle local appelle moins bien les outils.** Il faut un modèle plus gros si l'on passe en tout-local.
- **La purge du journal `ai_logs` n'est pas automatisée** (le cache `ai_cache` se vide seul après 24 h). Elle reste à planifier, par exemple avec une tâche cron.
