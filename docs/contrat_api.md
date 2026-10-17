# Contrat de l'API de l'assistant — version 1.0

Ce document décrit le contrat entre l'interface (widget `js/assistant.js`) et le service d'assistant (`api/assistant.php`), puis le contrat entre ce service et le fournisseur de modèle (`includes/ai/AIProvider.php`).
Toute évolution incompatible du format change le numéro de version, renvoyé dans chaque réponse (`"version"`).

---

## 1. Interface → service : `POST api/assistant.php`

### En-têtes obligatoires

| En-tête | Valeur | Rôle |
|---|---|---|
| `Content-Type` | `application/json` | Corps JSON |
| `X-CSRF-Token` | jeton de la session (`window.APP_CONFIG.csrfToken`) | Prouve que l'appel vient de l'application (protection CSRF) |
| Cookie de session | envoyé par le navigateur (`credentials: 'same-origin'`) | Identifie l'utilisateur, son rôle et ses droits |

L'identité et les droits ne viennent **jamais** du corps de la requête : uniquement de la session côté serveur.

### Corps de la requête (une des trois formes)

| Forme | Schéma | Contraintes |
|---|---|---|
| Question | `{ "message": string }` | 1 à 1 000 caractères après suppression des espaces de début et de fin |
| Nouvelle conversation | `{ "reinitialiser": true }` | Vide l'historique de la session |
| Avis sur une réponse | `{ "avis": { "id_echange": int, "valeur": 1 \| -1, "signaler": bool } }` | Uniquement sur un échange de l'utilisateur connecté |

### Réponse en cas de succès (HTTP 200)

```json
{
  "ok": true,
  "version": "1.0",
  "id_echange": 42,
  "reponse": "Ta moyenne générale au 1er trimestre est de 14,27/20.",
  "source": "modele",
  "fournisseur": "ollama",
  "modele": "qwen2.5:3b",
  "outils": ["mes_notes"],
  "actions": [],
  "latence_ms": 2840
}
```

| Champ | Type | Description |
|---|---|---|
| `id_echange` | int | Identifiant dans `ai_logs`, utilisé pour l'avis |
| `reponse` | string | Texte à afficher **avec `textContent`** (jamais interprété comme du HTML). 4 000 caractères au plus |
| `source` | `modele` · `cache` · `secours` | `cache` : même question récente (`AI_CACHE_TTL`). `secours` : tous les fournisseurs ont échoué, réponse enregistrée dans les 24 h |
| `fournisseur`, `modele` | string | Qui a produit la réponse : rend visible la bascule Groq → Ollama |
| `outils` | string[] | Outils appelés pour construire la réponse |
| `actions` | objet[] | Actions **proposées** à l'utilisateur, jamais exécutées par l'IA (voir ci-dessous) |
| `latence_ms` | int | Durée de traitement, nouvelles tentatives comprises |
| `avertissement` | string, facultatif | Présent avec `source = secours` : la réponse peut ne plus être à jour |

**Action proposée** (seul type actuel : `saisie_note`) :

```json
{ "type": "saisie_note",
  "libelle": "Saisir 15/20 (Contrôle) en Mathématiques pour Léa Bonnet — 1er Trimestre",
  "url": "modules/notes/saisir.php?eleve_id=41&matiere_id=1&trimestre_id=1&valeur=15&type_note=Contr%C3%B4le" }
```

L'interface affiche un bouton « Vérifier et enregistrer » qui ouvre le formulaire de saisie **pré-rempli**. La note n'est enregistrée que si l'utilisateur valide ce formulaire. Le formulaire refait tous les contrôles habituels : CSRF, matière de l'enseignant, note entre 0 et 20.

### Réponse en cas d'erreur

```json
{ "ok": false, "version": "1.0", "erreur": { "code": "ia_indisponible", "message": "L'assistant est momentanément indisponible. Vos notes restent consultables dans l'application." } }
```

| HTTP | `code` | Cause | Comportement de l'interface |
|---|---|---|---|
| 400 | `entree_invalide` | JSON invalide, message vide ou trop long, avis invalide | Affiche le message |
| 401 | `non_connecte` | Pas de session, session expirée ou compte désactivé | Redirige vers la connexion |
| 403 | `csrf` | Jeton CSRF absent ou faux | Demande de recharger la page |
| 403 | `acces_refuse` | Rôle `user` (aucun accès aux données) | Affiche le message |
| 405 | `methode` | Méthode autre que POST | — |
| 429 | `limite` | Plus de `AI_RATE_LIMIT` questions dans l'heure | Affiche le message |
| 503 | `ia_indisponible` | Tous les fournisseurs ont échoué, sans réponse de secours en cache | Message de repli : les notes restent consultables dans l'application |

Les messages d'erreur ne contiennent jamais de détail technique (clé, URL, trace). Le détail part dans le journal du serveur (`error_log`) et dans `ai_logs.echecs`.

---

## 2. Service → fournisseur de modèle : interface `AIProvider`

```php
interface AIProvider {
    public function name();                            // "gemini", "groq", "ollama"
    public function model();                           // "gemini-3.8-flash", "qwen2.5:3b"
    public function chat(array $messages, array $tools); // message normalisé, ou AIProviderException
}
```

**Entrée** : messages au format OpenAI (`system`, `user`, `assistant`, `tool`). Outils au format JSON Schema (`{"type":"function","function":{name, description, parameters}}`).

**Sortie normalisée**, quelle que soit l'API d'origine :

```php
['content' => ?string,
 'tool_calls' => [['id' => string, 'name' => string, 'arguments' => string /* JSON */]],
 'usage' => ['prompt_tokens' => int, 'completion_tokens' => int]]
```

**Erreurs** (`AIProviderException::$errorCode`) :

| Code | Signification | Traitement par `Assistant` |
|---|---|---|
| `timeout` | Délai `AI_TIMEOUT` dépassé | Nouvelle tentative (`AI_RETRIES`), puis fournisseur suivant |
| `http_429` | Quota du fournisseur atteint | Nouvelle tentative, puis fournisseur suivant |
| `http_5xx` | Panne du fournisseur | Nouvelle tentative, puis fournisseur suivant |
| `reseau` | Serveur injoignable (Ollama arrêté…) | Fournisseur suivant directement |
| `http_4xx` (sauf 429) | Requête refusée (clé invalide, modèle inconnu) | Fournisseur suivant directement |
| `reponse_invalide` | Réponse sans message, appel d'outil mal formé, texte vide, trop d'appels d'outils | Fournisseur suivant directement |
| `non_configure` | URL ou modèle absent du `.env` | Fournisseur suivant directement |

### Versions et compatibilité

- **Version du contrat** : la constante `CONTRAT_VERSION` dans `api/assistant.php`. Ajouter un champ facultatif reste compatible. Renommer ou supprimer un champ, ou en changer le type, impose une nouvelle version.
- **Version du modèle** : fixée dans `.env` (`GEMINI_MODEL`, `GROQ_MODEL`, `OLLAMA_MODEL`). Elle est enregistrée dans chaque ligne de `ai_logs` et renvoyée dans chaque réponse, ce qui permet de comparer deux versions dans la supervision et d'évaluer un nouveau modèle (`scripts/eval_assistant.php`) avant de l'adopter.
- **Limites** : message de 1 000 caractères au plus ; historique de 10 messages ; réponse de 4 000 caractères au plus ; 6 tours d'appels d'outils au plus ; 1 024 tokens générés au plus par appel.
- **Compatibilité fournisseur** : tout service qui expose `/chat/completions` au format OpenAI avec appel d'outils (Groq, Ollama, Mistral, OpenRouter…) s'utilise sans code nouveau. Il suffit de lui donner un nom dans `AI_PROVIDERS` et de renseigner ses variables `NOM_BASE_URL`, `NOM_API_KEY` et `NOM_MODEL`. Un fournisseur au format différent demande seulement une nouvelle classe qui implémente `AIProvider`, choisie par `NOM_FORMAT`.

### Exemple : l'adaptateur Gemini

L'API native de Google (`generateContent`) diffère du format OpenAI sur presque tous les points. `GeminiProvider` traduit dans les deux sens, et le reste de l'application ne voit que le contrat ci-dessus.

| Format OpenAI (contrat interne) | Format Gemini |
|---|---|
| message `system` | `systemInstruction` |
| rôles `user` / `assistant` | rôles `user` / `model` ; deux tours consécutifs du même rôle sont fusionnés |
| `tool_calls[]` avec `arguments` en chaîne JSON | partie `functionCall` avec `args` en objet ; l'identifiant est fabriqué s'il manque |
| message `tool` (`tool_call_id`) | partie `functionResponse` (nom de l'outil, résultat obligatoirement objet) |
| schéma JSON (`"type": "object"`) | schéma OpenAPI (`"type": "OBJECT"`) ; un outil sans paramètre n'en déclare pas |
| `usage.prompt_tokens` / `completion_tokens` | `promptTokenCount` / `candidatesTokenCount` + `thoughtsTokenCount` (réflexion facturée en sortie) |
| `Authorization: Bearer` | en-tête `x-goog-api-key` |

Deux particularités sont gérées dans l'adaptateur, sans toucher à `Assistant` :
- les parties de **réflexion** (`thought: true`) ne sont jamais affichées ;
- la **signature de réflexion** (`thoughtSignature`) d'un appel d'outil est conservée puis renvoyée telle quelle au tour suivant, comme l'exigent les modèles récents.

Une réponse bloquée par les filtres de Google (pas de `candidates`) devient `reponse_invalide`, ce qui déclenche la bascule vers le fournisseur suivant.

---

## 3. Contrat des outils (modèle → application)

Chaque outil (`includes/ai/tools.php`) déclare un schéma JSON de ses paramètres. Le modèle peut envoyer n'importe quoi : les arguments sont donc **revalidés côté PHP**.

| Situation | Réponse renvoyée au modèle |
|---|---|
| Outil inexistant ou non autorisé pour le rôle | `{"erreur": "Outil « x » non disponible pour cet utilisateur"}` |
| Arguments qui ne sont pas un objet JSON | `{"erreur": "Arguments invalides : objet JSON attendu"}` |
| Identifiant hors des droits (élève d'un autre parent…) | `{"erreur": "Cet élève ne fait pas partie de vos enfants : accès refusé."}` |
| Valeur hors limites (note 25/20) | `{"erreur": "La note doit être comprise entre 0 et 20"}` |
| Exception PHP | `{"erreur": "L'outil a rencontré une erreur"}` (détail dans `error_log`) |

Le modèle reçoit l'erreur et peut se corriger ou l'expliquer. La requête n'échoue pas.
