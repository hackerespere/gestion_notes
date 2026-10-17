# Architecture de l'assistant IA

Les diagrammes sont en **Mermaid** : ils s'affichent directement sur GitHub, ainsi que dans VS Code avec l'extension « Markdown Preview Mermaid Support ».
Des versions **PlantUML** (UML strict) se trouvent dans [`diagrammes/`](diagrammes/). Elles s'ouvrent dans VS Code avec l'extension PlantUML, ou sur plantuml.com.

## 1. Où est l'IA ? Modèle, service d'inférence, application

| Niveau | Dans le projet | Rôle |
|---|---|---|
| **Modèle** | `gemini-3.8-flash` (Google), `qwen/qwen3.8-27b` (Groq), `qwen2.5:7b` ou `qwen2.5:3b` (Ollama) | Fichier de poids entraîné par un tiers. Il prédit du texte. Il ne connaît ni la base, ni les droits, ni l'application. |
| **Service d'inférence** | API Gemini et API Groq (cloud), serveur Ollama (`localhost:11434`) | Fait tourner le modèle et expose une API HTTP (`generateContent` chez Google, `/chat/completions` ailleurs). Il est remplaçable. |
| **Application intelligente** | `api/assistant.php`, `includes/ai/*`, `js/assistant.js` | Authentification, droits, outils métier, validation, secours, cache, journal, interface. **C'est ici que se trouve la logique.** |

Le modèle ne lit jamais la base de données. Il **demande** l'exécution d'un outil. L'application vérifie la demande, exécute la requête SQL avec les droits de la session, puis renvoie le résultat au modèle.

## 2. Diagramme de composants

```mermaid
flowchart LR
    subgraph Navigateur
        W["Widget de discussion<br/>js/assistant.js<br/>(textContent, jeton CSRF)"]
        P["Pages existantes<br/>notes/saisir.php (formulaire)"]
    end

    subgraph Serveur PHP
        API["API de l'assistant<br/>api/assistant.php<br/>validation, contrat v1.0"]
        AUTH["Authentification et rôles<br/>config/session.php<br/>refreshAccount, verifyCsrfToken"]
        ORCH["Orchestrateur<br/>includes/ai/Assistant.php<br/>prompt, boucle d'outils,<br/>nouvelles tentatives, bascule"]
        TOOLS["Outils métier<br/>includes/ai/tools.php<br/>filtrés par la session"]
        CALC["Calculs partagés<br/>includes/bulletin.php<br/>includes/eleve_notes.php"]
        JOUR["Journal, quota, cache, coût<br/>includes/ai/journal.php"]
        PROV["Adaptateurs fournisseur<br/>GeminiProvider, OpenAICompatibleProvider<br/>(interface AIProvider)"]
        SUP["Supervision<br/>modules/supervision_ia"]
    end

    subgraph Données
        DB[("MariaDB<br/>notes, élèves, users,<br/>ai_logs, ai_cache")]
    end

    subgraph "Services d'inférence"
        GEM["Google Gemini (externe)<br/>gemini-3.8-flash"]
        GROQ["Groq (externe)<br/>qwen3.8-27b"]
        OLL["Ollama (local)<br/>qwen2.5"]
    end

    W -- "POST JSON + X-CSRF-Token" --> API
    API --> AUTH
    API --> JOUR
    API --> ORCH
    ORCH --> TOOLS
    TOOLS --> CALC
    CALC --> DB
    TOOLS --> DB
    JOUR --> DB
    ORCH --> PROV
    PROV -- "HTTPS generateContent" --> GEM
    PROV -. "secours 1 : HTTPS /chat/completions" .-> GROQ
    PROV -. "secours 2 : HTTP localhost" .-> OLL
    W -- "action proposée :<br/>lien pré-rempli" --> P
    P --> DB
    SUP --> JOUR
```

Il s'agit d'une **architecture hybride** :
- **services externes** en premier (Gemini, puis Groq : modèles plus gros, rapides, offre gratuite, aucune charge sur la machine, mais les données quittent l'établissement) ;
- **modèle local** en dernier secours (Ollama : aucune donnée ne sort, aucun coût par question, mais il faut une machine équipée).

L'ordre se règle dans `.env` (`AI_PROVIDERS=gemini,groq,ollama`). On peut l'inverser, ou passer en tout-local, sans toucher au code.

## 3. Diagramme de séquence d'une requête

Exemple : un élève demande « Quelle est ma moyenne au 1er trimestre ? ». Groq est indisponible.

```mermaid
sequenceDiagram
    autonumber
    actor E as Élève
    participant W as Widget (JS)
    participant A as api/assistant.php
    participant S as session.php
    participant J as journal.php / MariaDB
    participant O as Assistant (orchestrateur)
    participant G as Groq
    participant L as Ollama (local)
    participant T as Outil mes_notes

    E->>W: saisit la question
    W->>A: POST {message} + X-CSRF-Token + cookie
    A->>S: session valide ? compte actif ? jeton CSRF ?
    alt session expirée ou CSRF faux
        A-->>W: 401 / 403 (contrat)
    end
    A->>A: valider l'entrée (1 à 1 000 caractères)
    A->>J: quota horaire atteint ?
    alt quota dépassé
        A-->>W: 429 limite
    end
    A->>J: même question récente en cache ?
    alt trouvée (moins de 10 min)
        A-->>W: 200 source = cache (aucun appel au modèle)
    end
    A->>O: repondre(historique)
    O->>G: chat(messages, outils)
    G--xO: délai dépassé (timeout)
    O->>G: nouvelle tentative après 0,5 s
    G--xO: HTTP 503
    Note over O: erreur passagère deux fois → fournisseur suivant
    O->>L: chat(messages, outils)
    L-->>O: tool_call mes_notes {id_trimestre: 1}
    O->>T: exécuter (id_eleve lu dans la SESSION)
    T->>J: requêtes SQL préparées, moyennes calculées en PHP
    T-->>O: données minimisées (sans date de naissance)
    O->>L: résultat de l'outil
    L-->>O: « Ta moyenne est de 14,27/20 »
    O->>O: valider la réponse (non vide, ≤ 4 000 car.)
    O-->>A: réponse + fournisseur = ollama + échecs = [groq:timeout, groq:http_503]
    A->>J: journaliser (statut ok, latence, tokens, coût), mettre en cache
    A-->>W: 200 {reponse, source: modele, fournisseur: ollama, id_echange}
    W->>E: affichage (textContent) + « via ollama » + 👍 👎 🚩
    opt tous les fournisseurs en échec
        A->>J: réponse donnée à cette question il y a moins de 24 h ?
        A-->>W: 200 source = secours + avertissement, sinon 503 avec message de repli
    end
```

## 4. Flux d'une action sensible (saisie de note)

```mermaid
sequenceDiagram
    actor P as Enseignant
    participant W as Widget
    participant O as Assistant + outil proposer_saisie_note
    participant F as notes/saisir.php
    participant DB as MariaDB

    P->>W: « Prépare une note de 15 en maths pour Léa au T1 »
    W->>O: question
    O->>O: vérifie élève, matière de l'enseignant, 0 ≤ note ≤ 20
    O-->>W: réponse + action {libellé, url pré-remplie}
    Note over O,DB: aucune écriture en base
    W->>P: bouton « Vérifier et enregistrer »
    P->>F: ouvre le formulaire pré-rempli, relit, corrige si besoin
    P->>F: valide (POST + jeton CSRF)
    F->>F: contrôles habituels (matière affectée, 0–20, CSRF)
    F->>DB: INSERT (transaction)
```

Le modèle ne peut rien écrire : aucun outil n'a de droit en écriture. La seule écriture possible passe par le formulaire existant, après validation humaine.

## 5. Composants d'une application intelligente

| Composant | Fichiers | Responsabilité |
|---|---|---|
| Interface | `js/assistant.js`, `css/style.css` | Saisie, affichage sûr (`textContent`), indication de la source, avis, actions à confirmer |
| Logique métier | `includes/ai/tools.php`, `includes/bulletin.php`, `includes/eleve_notes.php` | Droits, calculs (moyennes, rangs), validations. **Rien de tout cela n'est dans le prompt** |
| Service d'IA | `includes/ai/AIProvider.php`, `OpenAICompatibleProvider.php`, `Assistant.php` | Contrat avec le modèle, boucle d'outils, nouvelles tentatives, bascule |
| Données | Tables existantes + `ai_logs`, `ai_cache` (`sql/database.sql`) | Source de vérité ; journal sans texte des questions ; cache limité à 24 h |
| Authentification | `config/session.php` | Session, rôles, CSRF, compte relu à chaque appel |
| Supervision | `modules/supervision_ia/index.php`, `scripts/eval_assistant.php` | Disponibilité, latence, coûts, avis, signalements, évaluation hors ligne |
| Configuration | `.env` | Fournisseurs, modèles, délais, quotas, tarifs : modifiables sans changer le code |
