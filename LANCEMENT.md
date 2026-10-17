# Lancer le projet « Gestion des Notes »

Ce guide explique comment démarrer l'application sur ce poste Windows.
Il faut **deux terminaux PowerShell** : un pour la base de données, un pour le site. Ces deux terminaux doivent **rester ouverts** tant qu'on utilise l'application.

---

## 1. Démarrer la base de données (MariaDB)

MariaDB 13.0 est installé dans `C:\Program Files\MariaDB 13.0`. Il n'est **pas installé comme service Windows** : il ne démarre donc pas tout seul au démarrage de l'ordinateur.

Dans un **premier terminal PowerShell**, lancer :

```powershell
& 'C:\Program Files\MariaDB 13.0\bin\mysqld.exe' '--defaults-file=C:\Program Files\MariaDB 13.0\data\my.ini' --console
```

- Quand le message `ready for connections` s'affiche, la base est prête.
- Laisser cette fenêtre ouverte.

La base `gestion_notes` existe déjà, avec :
- toutes les tables, y compris celles de l'assistant IA (`ai_logs`, `ai_cache`) ;
- les données de démonstration.

Il n'y a **rien à importer**.

> **Sur une autre machine, ou si la base a été supprimée :**
> ```powershell
> Get-Content sql\database.sql | & 'C:\Program Files\MariaDB 13.0\bin\mysql.exe' -u root
> php scripts\demo_data.php
> ```
> Si la base existe déjà, la commande `php scripts\demo_data.php --reset` recrée les données de démonstration. Attention : elle **supprime** d'abord les élèves, les notes et les comptes `@demo.local`.

---

## 2. Démarrer le site (serveur PHP)

Dans un **second terminal PowerShell**, se placer dans le dossier du projet :

```powershell
cd "D:\MASTER\PROJET\Dev Log\gestion_notes"
php -S localhost:8000
```

Laisser aussi cette fenêtre ouverte.

---

## 3. Ouvrir l'application

Dans le navigateur, aller sur : **http://localhost:8000/login.php**

---

## 4. Comptes de démonstration

Tous les comptes ont le même mot de passe : **`Demo1234`**

| Rôle | Email | Ce qu'il peut faire |
|---|---|---|
| Directeur | `directeur@demo.local` | Tout gérer : élèves, classes, matières, trimestres, comptes, supervision IA |
| Enseignant | `martin@demo.local` | Consulter les classes, saisir et modifier les notes de ses matières |
| Enseignant | `bernard@demo.local`, `leroy@demo.local`, `moreau@demo.local` | Idem, pour leurs propres matières |
| Élève | `adam.blanc@demo.local` | Voir ses notes et imprimer ses bulletins |
| Élève | `gabriel.garcia@demo.local` | Idem |
| Parent | `parent@demo.local` | Voir les notes et les bulletins de ses enfants |

Les comptes `admin@test.local` et `prof@test.local` sont d'anciens comptes de test, dont le mot de passe n'est pas documenté.

---

## 5. Activer l'assistant IA (facultatif)

Sans fournisseur d'IA configuré, l'application fonctionne normalement. Seul l'assistant (le bouton en bas à droite) répond « momentanément indisponible ».

### Option 1 : Groq (service externe, gratuit)

1. Créer une clé gratuite sur <https://console.groq.com/keys>.
2. Ouvrir le fichier `.env` à la racine du projet et coller la clé :
   ```ini
   GROQ_API_KEY=votre_clé
   ```

Aucun redémarrage n'est nécessaire.

### Option 2 : Ollama (modèle local, secours)

Ollama sert de secours quand Groq est indisponible. Avec lui, aucune donnée ne sort de la machine.

1. Installer Ollama depuis <https://ollama.com>.
2. Télécharger le modèle :
   ```powershell
   ollama pull qwen2.5:3b
   ```

Ollama tourne ensuite en arrière-plan sur `http://localhost:11434`.

L'ordre d'essai des fournisseurs se règle dans `.env` avec `AI_PROVIDERS=groq,ollama`.

---

## 6. Vérifier que tout fonctionne (facultatif)

Lancer ces commandes dans un troisième terminal, depuis le dossier du projet. MariaDB doit être démarré.

| Commande | Ce qu'elle vérifie | Besoin d'une IA ? |
|---|---|---|
| `php scripts\test_outils.php` | Les outils de l'assistant : droits de chaque rôle, chiffres identiques à ceux de l'application | Non |
| `php scripts\eval_assistant.php` | L'assistant complet sur un jeu de questions avec réponses attendues (voir `docs/evaluation.md`) | Oui |

---

## 7. Arrêter le projet

Dans chacun des deux terminaux, faire **Ctrl+C**, ou simplement fermer les fenêtres.

---

## En cas de problème

| Symptôme | Cause probable | Solution |
|---|---|---|
| « Erreur de connexion à la base de données » | MariaDB n'est pas démarré | Refaire l'étape 1 |
| `Failed to listen on localhost:8000` au lancement de `php -S` | Le port 8000 est déjà utilisé | Utiliser un autre port, par exemple `php -S localhost:8080`, puis ouvrir `http://localhost:8080/login.php` |
| MariaDB s'arrête aussitôt | Le port 3306 est déjà utilisé : une autre instance tourne déjà | Rien à faire, la base est déjà disponible |
| L'assistant répond « indisponible » | Aucune clé Groq et Ollama non installé | Voir l'étape 5 |
| Déconnexion au bout de 5 minutes | Délai d'inactivité normal | Modifier `SESSION_TIMEOUT` dans `.env` (en secondes) |
