# Estimation comparative des coûts : API externe ou modèle local

Ce document compare le coût de l'assistant selon deux modes d'hébergement :
- **API externe** : Groq, avec les tarifs de `llama-3.3-70b-versatile` comme référence (le projet utilise désormais `qwen/qwen3.8-27b`, llama ayant été retiré en 2026) ;
- **modèle local** : Ollama ou vLLM sur un serveur de l'établissement.

Ce sont des **estimations** (octobre 2026). Toutes les hypothèses sont écrites, pour pouvoir refaire le calcul avec d'autres chiffres. Les vrais chiffres viennent ensuite de la page *Supervision IA*, qui relève les tokens et le coût de chaque échange (`ai_logs`).

Pour simplifier, on compte 1 $ ≈ 1 €.

---

## 1. Combien de tokens coûte une question ?

### Partie fixe, envoyée à chaque appel au modèle

Valeurs mesurées sur le code (`systemPrompt()` et `assistantTools()`), pour le rôle qui a le plus d'outils :

| Élément | Directeur (12 outils) | Enseignant (10 outils) |
|---|---|---|
| Prompt système | 1 356 caractères | 1 320 caractères |
| Définitions des outils (JSON) | 4 666 caractères | 4 009 caractères |
| **Total** | **≈ 6 000 caractères ≈ 1 800 tokens** | ≈ 5 300 caractères ≈ 1 600 tokens |

On compte environ 3,3 caractères par token pour du français mêlé de JSON.

### Une question type demande deux appels

1. **Premier appel** : le modèle choisit un outil (par exemple `mes_notes`).
2. **Second appel** : il rédige la réponse à partir du résultat de l'outil.

Les tokens d'un échange se répartissent ainsi :

| | Entrée (prompt) | Sortie (completion) |
|---|---|---|
| Appel 1 : partie fixe + historique (≈ 300) + question | ≈ 2 100 | ≈ 40 (appel d'outil) |
| Appel 2 : même contexte + appel d'outil + résultat de l'outil (≈ 400) | ≈ 2 550 | ≈ 210 (réponse) |
| **Total par question** | **≈ 4 700** | **≈ 250** |

La partie fixe est renvoyée à chaque appel, donc elle représente **près de 80 % du coût**. Réduire le nombre d'outils proposés à chaque rôle est le premier levier d'économie, avant même le choix du fournisseur.

## 2. API externe (Groq)

Tarif de l'offre payante, repris de `.env.example` (`GROQ_PRICE_IN`, `GROQ_PRICE_OUT`) :
- 0,59 $ par million de tokens en entrée ;
- 0,79 $ par million de tokens en sortie.

```
coût d'une question = 4 700 × 0,59 / 10⁶ + 250 × 0,79 / 10⁶ ≈ 0,0030 $
coût de 1 000 questions ≈ 3 $
```

On suppose 22 jours d'activité par mois. Le cache est ignoré : il ne sert qu'aux premières questions identiques posées dans les 10 minutes, et le gain est donc faible.

| Scénario | Questions par jour | Questions par mois | Coût mensuel |
|---|---|---|---|
| **A. Ce projet** : un établissement d'environ 600 comptes | 300 | 6 600 | **≈ 20 $** |
| **B. Université moyenne** : environ 10 000 étudiants | 3 000 | 66 000 | **≈ 200 $** |
| **C. Grande université** : environ 30 000 étudiants | 15 000 | 330 000 | **≈ 1 000 $** |

À noter :
- **L'offre gratuite de Groq** suffit pour un prototype ou une démonstration (scénario A). Elle impose cependant des limites de requêtes et de tokens par minute et par jour, visibles sur la console Groq. Le quota `AI_RATE_LIMIT` et la bascule vers Ollama absorbent les refus (HTTP 429).
- **Le coût est entièrement variable.** Il n'y a rien à acheter, et on ne paie rien pendant les vacances.
- **Les coûts cachés** :
  - les tarifs peuvent changer ;
  - l'établissement dépend d'un tiers, ce qui oblige à garder un secours ;
  - **les données quittent l'établissement**, ce qui est une contrainte juridique (voir [securite_donnees.md](securite_donnees.md) §5).

### Variante : Google Gemini

Le projet utilise `gemini-3.8-flash` : `gemini-2.5-flash` n'est plus ouvert aux nouveaux comptes (constaté en octobre 2026 : HTTP 404). Le tarif du modèle choisi se lit sur <https://ai.google.dev/gemini-api/docs/pricing> et se reporte dans `GEMINI_PRICE_IN` / `GEMINI_PRICE_OUT`.

Pour donner un ordre de grandeur, voici le calcul avec les tarifs publiés pour la génération 2.5 :
- `gemini-2.5-flash` : 0,30 $ par million de tokens en entrée, 2,50 $ en sortie ;
- `gemini-2.5-flash-lite` : 0,10 $ en entrée, 0,40 $ en sortie.

```
gemini-2.5-flash      : 4 700 × 0,30 / 10⁶ + 250 × 2,50 / 10⁶ ≈ 0,0020 $  →  ≈ 2 $ pour 1 000 questions
gemini-2.5-flash-lite : 4 700 × 0,10 / 10⁶ + 250 × 0,40 / 10⁶ ≈ 0,0006 $  →  ≈ 0,6 $ pour 1 000 questions
```

À noter :
- Les modèles Flash « réfléchissent » avant de répondre, et cette réflexion est facturée comme de la sortie. Quelques centaines de tokens de réflexion par appel peuvent faire passer Flash à environ 3 $ pour 1 000 questions, soit le prix de Groq. Le coût réel apparaît dans la supervision, car `GeminiProvider` compte ces tokens.
- Les **limites de l'offre gratuite** sont serrées et comptées par modèle. Pour `gemini-3.8-flash`, on a constaté 5 requêtes par minute et 20 par jour. Une question demandant 2 à 3 requêtes, cela fait environ 7 à 10 questions par jour. Au-delà, Gemini répond HTTP 429 et l'assistant bascule vers le fournisseur suivant. Un second modèle Gemini avec la même clé (`gemini_lite` dans `.env.example`) double la capacité sans code.
- L'offre gratuite suffit pour la démonstration. En contrepartie, **Google peut réutiliser les requêtes** : elle est réservée aux données fictives (voir [securite_donnees.md](securite_donnees.md) §5).

## 3. Modèle local

Hypothèses retenues :
- électricité à 0,20 €/kWh ;
- matériel amorti sur 3 ans ;
- maintenance (mises à jour, supervision, pannes) comptée à 25 €/h.

| Option | Matériel | Amortissement par mois | Électricité par mois | Maintenance par mois | **Total par mois** | Capacité |
|---|---|---|---|---|---|---|
| **L1. Poste existant**, `qwen2.5:7b` sous Ollama (GPU de 8 Go) | déjà présent | 0 € | ≈ 5 € | ≈ 1 h → 25 € | **≈ 30 €** | une question à la fois, 5 à 15 s. Suffit pour A, pas au-delà |
| **L2. Serveur dédié, un GPU de 24 Go**, modèle de 7 à 14 B sous vLLM (requêtes traitées par lots) | ≈ 3 000 € | ≈ 85 € | ≈ 50 € (350 W en moyenne, 24 h/24) | ≈ 4 h → 100 € | **≈ 235 €** | plusieurs dizaines de requêtes simultanées. Couvre B |
| **L3. Serveur avec 2 GPU de 48 Go**, modèle de 70 B (qualité comparable à Groq) | ≈ 15 000 € | ≈ 420 € | ≈ 145 € (1 kW) | ≈ 8 h → 200 € | **≈ 765 €** | couvre C |
| *Variante : location d'un GPU dans le cloud, 24 h/24* | aucun | — | — | ≈ 100 € | ≈ 0,5 à 2 $/h, soit **≈ 450 à 1 550 €** | selon le GPU. Les données sortent quand même de l'établissement |

À noter :
- **Le coût est surtout fixe.** On paie aussi pendant les vacances et la nuit, et il faut dimensionner la machine pour les pics d'activité (veille d'examens, sortie des bulletins).
- **La qualité n'est pas la même.** Un modèle de 7 B appelle moins bien les outils qu'un modèle de 70 B. Le protocole [evaluation.md](evaluation.md) sert à mesurer cet écart avant de choisir.
- **Les données restent dans l'établissement.** C'est l'avantage décisif pour des notes d'élèves.

## 4. Seuil de rentabilité

Le seuil est le volume de questions à partir duquel le local coûte moins cher que l'API (3 $ pour 1 000 questions) :

| Comparaison | Calcul | Seuil |
|---|---|---|
| L2 contre Groq (qualité **inférieure** en local) | 235 / 3 × 1 000 | ≈ 78 000 questions/mois, soit **≈ 3 500 par jour** |
| L3 contre Groq (qualité **équivalente**) | 765 / 3 × 1 000 | ≈ 255 000 questions/mois, soit **≈ 11 500 par jour** |

```
Coût mensuel
  ▲
  │                                       ╱ API (≈ 3 $ / 1 000 questions)
1000 ┤                                 ╱
  │ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ╳ ─ ─ ─ ─  L3 local 70 B (≈ 765 €)
  │                         ╱
  │                   ╱
 235 ┤ ─ ─ ─ ─ ─ ─ ╳ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─  L2 local 7–14 B (≈ 235 €)
  │       ╱
  └──────┬──────────────────────┬──────────► questions / mois
       78 000                  255 000
```

## 5. Conclusion pour ce projet

| Critère | API externe | Modèle local |
|---|---|---|
| Coût à faible volume | **très faible**, gratuit en offre gratuite | fixe, payé même sans usage |
| Coût à fort volume | augmente avec l'usage | **stable** au-delà du seuil |
| Qualité | **modèle de 70 B** sans investissement | dépend du matériel |
| Confidentialité | les données sortent : minimisation obligatoire | **aucune donnée ne sort** |
| Disponibilité | dépend du fournisseur et du réseau | dépend de la machine et de l'équipe |
| Mise en route | une clé API | installation, GPU, maintenance |

**Choix retenu** (`AI_PROVIDERS=gemini,groq,ollama`) :
- **Gemini en premier, Groq en secours** : au volume d'un établissement (scénario A), chacun coûte au plus une vingtaine d'euros par mois. Deux fournisseurs externes indépendants rendent peu probable une panne simultanée. Ni l'un ni l'autre ne charge la machine de l'établissement.
- **Ollama en dernier secours sur un poste existant** (L1) : il coûte presque rien et garde l'assistant disponible même sans Internet. Sur un portable avec un GPU de 4 Go, on utilise `qwen2.5:3b`.

Ce choix est à revoir dans trois cas, et il ne demande alors qu'une modification de `.env` :
1. le volume dépasse quelques milliers de questions par jour ;
2. la politique de l'établissement interdit l'envoi de données à un tiers ;
3. le prix de l'API augmente.

Dans ces cas, on passe à `AI_PROVIDERS=ollama` (ou à un serveur vLLM compatible), sans changer le code.

## 6. Mesurer les vrais chiffres

- **En production**, la page *Supervision IA* affiche les tokens, le coût total et le coût pour 1 000 questions, calculés à partir des tarifs `{FOURNISSEUR}_PRICE_IN` et `_PRICE_OUT` du `.env`.
- **Avant de choisir**, `php scripts/eval_assistant.php --fournisseur=gemini`, puis `--fournisseur=groq` et `--fournisseur=ollama`, mesure sur le même jeu de questions la qualité, la latence et les tokens réellement consommés. On remplace alors les hypothèses du §1 par ces mesures.
