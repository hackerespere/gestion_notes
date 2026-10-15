# Changements apportés au projet

Ce document explique les modifications faites sur l'application « Gestion des Notes » : **ce qui a changé** et **pourquoi**.
Le point de départ est l'analyse du code (`analyse.md`), qui avait relevé des failles de sécurité, des pages manquantes et des incohérences.

Les changements sont présentés dans l'ordre où ils ont été faits, en suivant les commits.

---

## Sommaire

1. [Sécurité : bulletins, API et durée de session](#1-sécurité--bulletins-api-et-durée-de-session)
2. [Pages manquantes : suppression, fiches, modification](#2-pages-manquantes--suppression-fiches-modification)
3. [Authentification, rôles, espace élève et comptes](#3-authentification-rôles-espace-élève-et-comptes)
4. [Enseignants, espace parent et profil](#4-enseignants-espace-parent-et-profil)
5. [Données de démonstration](#5-données-de-démonstration)
6. [Installation et mise à jour](#6-installation-et-mise-à-jour)
7. [Ce qui reste à faire](#7-ce-qui-reste-à-faire)

---

## 1. Sécurité : bulletins, API et durée de session

Commit : *Secure report card and API access, fix session timeout*

| Changement | Pourquoi |
|---|---|
| `modules/bulletins/imprimer.php` exige une connexion | N'importe qui connaissant l'adresse `imprimer.php?eleve_id=X&trimestre_id=Y` pouvait lire le bulletin d'un élève **sans être connecté**. |
| Les champs du bulletin sont échappés (`htmlspecialchars`) et les identifiants convertis en nombres | Un nom d'élève contenant du code HTML ou JavaScript était exécuté dans la page (faille XSS). Le numéro de trimestre de l'URL était aussi affiché tel quel. |
| `api/get_eleves_by_classe.php` renvoie une erreur 401 sans session | L'API donnait la liste des élèves d'une classe (noms, prénoms) à n'importe qui. |
| `includes/header.php` ne remet plus le compteur d'inactivité à zéro **avant** de le vérifier | Le compteur était rafraîchi juste avant la vérification : la session n'expirait donc **jamais** côté serveur. Seul le minuteur JavaScript déconnectait l'utilisateur, ce qui se contourne facilement. |
| `checkAuth()` redirige vers une adresse absolue (`BASE_URL . 'login.php'`) | La redirection était relative : depuis `modules/eleves/`, elle menait à `modules/eleves/login.php`, une page qui n'existe pas (erreur 404). |
| Ajout de la table `users` dans `sql/database.sql` | `login.php` utilisait cette table, mais elle n'était créée nulle part : impossible d'installer l'application à partir du dépôt. |

---

## 2. Pages manquantes : suppression, fiches, modification

Commit : *Add delete, view and edit pages for students, classes, subjects and grades*

### Suppressions sécurisées
- **Création des pages `supprimer.php`** pour les notes, élèves, classes et matières.
  **Pourquoi :** tous les boutons « Supprimer » menaient à une page inexistante (404).
- **Les suppressions passent par un formulaire POST avec un jeton CSRF**, au lieu d'un simple lien.
  **Pourquoi :** avec un lien, un site malveillant pouvait déclencher une suppression à l'insu d'un utilisateur connecté, par exemple avec une simple image. Le jeton prouve que la demande vient bien de l'application.
- **Règles de suppression choisies :**
  - une **note** est supprimée avec ses 4 liaisons, dans une transaction (tout ou rien) ;
  - un **élève** est supprimé avec ses notes, qui n'ont plus de sens sans lui ;
  - une **classe** qui contient encore des élèves est refusée, pour ne pas laisser d'élèves sans classe ;
  - une **matière** qui a des notes est refusée, car les bulletins en dépendent.
- **Messages « flash »** : un message de confirmation ou d'erreur s'affiche une fois après la redirection.

### Nouvelles pages
- **`eleves/voir.php`** : fiche d'un élève, avec ses notes par trimestre, ses moyennes calculées comme dans le bulletin et l'impression par trimestre.
- **`classes/voir.php`** : fiche d'une classe, avec ses statistiques et la liste de ses élèves (moyenne et rang, filtrables par trimestre).
- **`eleves/modifier.php` et `classes/modifier.php`** : ils redirigent vers le formulaire d'ajout, qui gérait déjà la modification.
  **Pourquoi :** éviter de dupliquer deux grands formulaires.

### Bugs corrigés
- **Ajouter ou modifier une classe échouait toujours** : le formulaire enregistrait une colonne `description` absente de la table `classe`. La colonne a été ajoutée.
- **En modification, une erreur de saisie effaçait ce que l'utilisateur venait de taper** et réaffichait les anciennes valeurs.
- **Le bouton « Bulletin » de la liste des élèves** appelait l'impression sans trimestre et affichait « Paramètres manquants ». Il ouvre désormais la fiche de l'élève.
- **Champs retirés du formulaire élève** : téléphone, email et parents n'étaient enregistrés nulle part, car la table `eleve` n'a pas ces colonnes.

---

## 3. Authentification, rôles, espace élève et comptes

Commit : *Add roles, student portal and user account management*

### Un seul système d'authentification
**Avant**, trois systèmes coexistaient, avec trois durées de session différentes (300 s, 1800 s et 1800 s mesurées différemment) :
- `includes/auth_functions.php`, complet mais **jamais inclus** ;
- `config/session.php` ;
- une vérification codée en dur au début de `index.php`.

**Après :**
- `config/session.php` est le seul fichier d'authentification, et `auth_functions.php` a été supprimé.
- **Cookies de session `HttpOnly` et `SameSite=Lax`**, pour que le JavaScript ne puisse pas lire le cookie et que les autres sites ne puissent pas l'envoyer.
- **`checkAuth()` relit le compte en base à chaque page.** Un compte désactivé est donc déconnecté immédiatement, et un changement de rôle s'applique sans reconnexion.
- **`extend_session.php` refuse de prolonger une session déjà expirée.** Avant, ce fichier permettait de relancer une session morte, ce qui annulait le délai d'inactivité.
- **Le cookie « Se souvenir de moi » est supprimé.** Il stockait l'identifiant, l'email et le nom **en clair**, et n'était même pas utilisé pour reconnecter.
- **`login.php` utilise la connexion commune** (`config/database.php`) au lieu de sa propre copie des identifiants.

### Protection CSRF sur tous les formulaires
La connexion et tous les formulaires d'ajout et de modification ont maintenant un jeton CSRF. Un envoi sans jeton valide est refusé et rien n'est enregistré.

### Rôles
| Rôle | Peut faire |
|---|---|
| **admin** | Tout, y compris la gestion des comptes |
| **directeur** | Gérer élèves, classes, matières et enseignants ; notes et bulletins |
| **enseignant** | Consulter ; saisir des notes dans **ses** matières ; supprimer **ses** notes ; bulletins |
| **élève** | Ses propres notes et bulletins uniquement |
| **parent** | Les notes et bulletins de ses enfants uniquement |
| **user** | Aucun accès aux données |

**Pourquoi :** le README annonçait une gestion des rôles, mais tout utilisateur connecté avait accès à tout, y compris aux suppressions.

Chaque page vérifie le rôle **côté serveur**, avant tout affichage. Le menu et les boutons n'affichent que ce que le rôle permet. Les listes de rôles se modifient en haut de `config/session.php` (`ROLES_GESTION`, `ROLES_PEDAGOGIE`…).

### Espace élève (`modules/espace_eleve/`)
- Un compte élève est relié à **sa** fiche (`users.id_eleve`). Après la connexion, l'élève arrive sur « Mes notes ».
- **L'identifiant de l'élève vient toujours de la session, jamais de l'URL.**
  **Pourquoi :** sinon, un élève pourrait changer `?id=5` en `?id=6` et lire les notes d'un camarade.
- Le code qui lit et affiche les notes est partagé avec la fiche élève (`includes/eleve_notes.php`), pour ne pas le dupliquer.

### Gestion des comptes (`modules/utilisateurs/`, admin uniquement)
- Créer, modifier, désactiver ou réactiver un compte, et le relier à une fiche élève.
- **Désactiver plutôt que supprimer**, pour garder l'historique.
- **Un admin ne peut pas désactiver son propre compte ni changer son propre rôle**, pour éviter de se bloquer.
- **Pourquoi :** les comptes ne pouvaient être créés que directement dans la base de données.

### Configuration (`.env`)
- Les identifiants de la base et la durée de session sont lus dans un fichier `.env` (`config/env.php`). Le fichier `.env.example` sert de modèle.
- `.gitignore` exclut `.env`.
  **Pourquoi :** les identifiants ne doivent pas être versionnés, et la future clé de l'API de l'assistant IA non plus.
- En cas d'échec de connexion à la base, le détail de l'erreur n'est plus affiché aux visiteurs.

---

## 4. Enseignants, espace parent et profil

Commit : *Add teacher management, parent portal and profile page*

### Enseignants (`modules/enseignants/`)
- **Une page de gestion des enseignants**, avec les matières qu'ils enseignent (table `enseigner`), alors qu'il n'en existait aucune.
- **Un compte enseignant est relié à sa fiche** (`users.id_enseignant`).
- **À la saisie d'une note par un enseignant :**
  - il est enregistré comme auteur **d'après sa session**, et une valeur envoyée par le formulaire est ignorée ;
  - seules ses matières sont proposées, et une autre matière est refusée côté serveur.

  **Pourquoi :** avant, l'enseignant choisissait lui-même « l'enseignant » dans une liste. Il pouvait donc saisir une note au nom d'un collègue, ou dans une matière qu'il n'enseigne pas.
- **Un enseignant ne peut supprimer que les notes qu'il a saisies.** La direction peut toutes les supprimer.
- **Supprimer un enseignant qui a saisi des notes est refusé**, pour que ces notes gardent leur auteur. Il vaut mieux désactiver son compte.

### Espace parent (`modules/espace_parent/`)
- **Un compte parent est relié à un ou plusieurs enfants** (table `parent_eleve`).
- Le parent voit les notes, moyennes et bulletins de **ses enfants uniquement**.
  - Demander un autre élève dans l'URL est refusé.
  - Imprimer le bulletin d'un autre élève répond par une erreur 403.

### Finitions
- **Les boutons interdits sont masqués selon le rôle** : un enseignant ne voit plus « Modifier » ni « Supprimer » sur les élèves, classes et matières.
- **Nouvelle page « Mon profil »** (`profil.php`) pour changer son mot de passe, en saisissant d'abord l'actuel.
  **Pourquoi :** seul un admin pouvait changer un mot de passe ; et exiger l'actuel empêche qu'une session laissée ouverte suffise à le changer.
- **Liens morts retirés** : inscription, mot de passe oublié, paramètres, import d'élèves, `liste.php`.
- **Bug corrigé : la note 0/20 était refusée.** Le test `empty($valeur)` considère `0` comme vide.

---

## 5. Données de démonstration

Commit : *Add demo data script*

Le script `scripts/demo_data.php` remplit la base avec des données réalistes.
**Pourquoi :** une base vide ne permet ni de tester ni de présenter l'application (moyennes, rangs, espaces élève et parent). Elle ne permettra pas non plus de tester le futur assistant IA.

- 24 élèves, environ 1 100 notes sur 3 trimestres, 5 enseignants affectés à leurs matières.
- Un compte de démonstration par rôle, avec le mot de passe `Demo1234` :
  - `directeur@demo.local` ;
  - `martin@`, `bernard@`, `leroy@`, `moreau@demo.local` pour les enseignants ;
  - deux élèves : le meilleur et le plus en difficulté ;
  - `parent@demo.local`, avec deux enfants.
- **Toujours les mêmes données** d'une exécution à l'autre, pour préparer une présentation reproductible.
- **Sécurités :**
  - le script refuse de s'exécuter si des élèves existent déjà, sauf avec `--reset` ;
  - il fonctionne en tout ou rien ;
  - il est inaccessible depuis le navigateur.

```bash
php scripts/demo_data.php           # base vide
php scripts/demo_data.php --reset   # efface élèves, notes, affectations et comptes @demo.local, puis recrée
```

---

## 6. Installation et mise à jour

### Nouvelle installation
1. Importer `sql/database.sql` dans MySQL ou MariaDB.
2. Copier `.env.example` en `.env`, puis adapter les identifiants de la base.
3. Facultatif : lancer `php scripts/demo_data.php`.
4. Créer un premier compte administrateur directement dans la table `users`. Le mot de passe se chiffre avec `php -r "echo password_hash('motdepasse', PASSWORD_DEFAULT);"`.

### Mise à jour d'une base existante
Si la base a été créée avec une ancienne version de `database.sql`, appliquer dans l'ordre :

```sql
-- Commit 2 : colonne manquante des classes
ALTER TABLE classe ADD COLUMN description TEXT NULL AFTER anne_scolaire;

-- Commit 3 : rôle élève et lien compte -> fiche élève
ALTER TABLE users
  MODIFY role ENUM('admin','directeur','enseignant','parent','eleve','user') NOT NULL DEFAULT 'user',
  ADD COLUMN id_eleve INT NULL UNIQUE AFTER role,
  ADD FOREIGN KEY (id_eleve) REFERENCES eleve(id_eleve) ON DELETE CASCADE;

-- Commit 4 : lien compte -> fiche enseignant, et enfants des parents
ALTER TABLE users ADD COLUMN id_enseignant INT NULL UNIQUE AFTER id_eleve,
  ADD FOREIGN KEY (id_enseignant) REFERENCES enseignant(id_enseignant) ON DELETE CASCADE;
CREATE TABLE parent_eleve (id_user INT, id_eleve INT, PRIMARY KEY (id_user, id_eleve),
  FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (id_eleve) REFERENCES eleve(id_eleve) ON DELETE CASCADE);
```

Ensuite, relier chaque compte enseignant existant à sa fiche, dans le menu « Comptes ». Sans ce lien, sa saisie de notes reste bloquée.

---

## 7. Ce qui reste à faire

- **Assistant IA** (plan détaillé dans `analyse.md`) : un assistant de discussion qui répond à chacun selon son rôle, sans jamais donner accès aux données d'un autre. Tous les prérequis de sécurité sont en place. Il faut une clé API Anthropic et Composer.
- **Modifier une note** : aujourd'hui, il faut la supprimer puis la ressaisir.
- **Gérer les trimestres** : ils sont fixés dans `database.sql`, sans page de gestion.
- **Nettoyage technique :**
  - `generateBulletin()` est dupliquée entre `bulletins/index.php` et `imprimer.php` ;
  - le CSS est copié dans chaque page ;
  - le chemin de `js/script.js` est incorrect depuis la racine ;
  - il y a une faute dans le pied de page (« Developper par »).
