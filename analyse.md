J'ai terminé l'analyse du projet. Voici ce que j'ai constaté.

Vue d'ensemble

Gestion des Notes est une application PHP/MySQL (PDO) de gestion scolaire, sans framework, sans Composer, sans .gitignore/.htaccess. Architecture en pages procédurales (pas de MVC) : modules/{eleves,classes,matieres,notes,bulletins}/, un includes/header.php monolithique (1007 lignes, contient aussi tout le CSS/JS inline), config/database.php (PDO) et config/session.php (auth).

Points positifs

- Requêtes préparées PDO partout pour les SELECT/INSERT/UPDATE — pas d'injection SQL classique trouvée.
- Tri de colonnes protégé par whitelist (modules/eleves/index.php:85, sortable_columns).
- Vérification des mots de passe avec password_verify et session_regenerate_id au login (login.php:62-67, protection contre la fixation de session). Le hash password_hash cost 12 et la configuration des cookies (httponly, samesite=Lax) n'existent en revanche que dans includes/auth_functions.php, qui n'est jamais inclus : ils ne s'appliquent pas.
- Transaction SQL correcte dans notes/saisir.php (insert note + 4 tables de liaison, commit/rollback).
- htmlspecialchars() utilisé sur la plupart des champs sensibles affichés dans les listes.

Problèmes constatés

1. Deux systèmes d'authentification concurrents et incohérents
- includes/auth_functions.php (437 lignes, complet : rôles, CSRF, logs, remember-me) n'est jamais inclus nulle part — aucun require dans tout le repo. C'est du code mort.
- includes/header.php inclut config/session.php (ligne 48), mais ajoute sa propre logique : synchronisation login_time/last_activity et repli à 1800s si session.php est absent.
- index.php réimplémente sa propre vérification de session (lignes 1-27) avec un timeout de 1800s codé en dur, mesuré sur login_time. config/session.php définit SESSION_TIMEOUT à 300s (mesuré sur last_activity), et includes/auth_functions.php (mort) à 1800s. Trois sources de vérité différentes pour le timeout.
- Le timeout serveur ne se déclenche jamais : includes/header.php:74 remet last_activity = time() avant d'appeler checkAuth(), donc isSessionExpired() renvoie toujours faux. Seul le minuteur JavaScript déconnecte l'utilisateur.
- checkAuth() redirige vers « login.php » en chemin relatif (config/session.php:29) : depuis modules/xxx/, la redirection pointe vers modules/xxx/login.php, qui n'existe pas (404).
- login.php ne passe pas non plus par auth_functions.php : il réécrit lui-même la connexion PDO et la requête users. Son cookie « Se souvenir de moi » (lignes 87-89) stocke user_id, email et nom en clair, sans httponly.

2. Pas de gestion des rôles/permissions malgré la promesse du README
Le README annonce "Gestion des rôles et des permissions", mais hasRole()/requireRole() existent dans le fichier mort et ne sont utilisés nulle part. N'importe quel utilisateur connecté a accès à tout.

3. CSRF absent en pratique
generateCsrfToken()/verifyCsrfToken() existent mais ne sont appelés dans aucun formulaire (login.php, notes/saisir.php, suppressions, etc.). Les suppressions se font via un simple lien GET (supprimer.php?id=...) avec juste une confirmation JS — pas de token, et supprimer.php n'existe même pas dans le repo (lien mort, 404 garanti) pour eleves, classes, matieres, notes.

4. Fichiers référencés mais absents
Liens vers des pages qui n'existent pas dans le dépôt : supprimer.php (tous modules), modules/eleves/voir.php, modules/eleves/modifier.php, modules/classes/modifier.php, modules/classes/voir.php, modules/enseignants/index.php, register.php, profile.php, settings.php, forgot-password.php. Le menu et les boutons d'action pointent vers des 404.

5. Identifiants DB en dur et dupliqués
root/mot de passe vide codé à la fois dans config/database.php et une seconde fois réimplémenté dans login.php (connexion PDO distincte). Pas de .env, pas de fichier de config centralisé.

6. imprimer.php bulletin accessible sans authentification
modules/bulletins/imprimer.php ne fait pas require_once header.php (contrairement aux autres modules) — donc aucun checkAuth(). N'importe qui connaissant une URL ?eleve_id=X&trimestre_id=Y peut voir le bulletin d'un élève sans être connecté. Cette page n'échappe pas non plus certains champs (nom, prenom, nom_classe, niveau, nom de matière — lignes 75, 97, 103, 104, 136) en sortie — XSS stocké possible si ces champs contiennent du HTML.

6 bis. api/get_eleves_by_classe.php accessible sans authentification
Ce fichier n'inclut que config/database.php : aucun contrôle de session. N'importe qui peut appeler ?classe_id=X et obtenir la liste des élèves (id, nom, prénom) d'une classe.

7. Duplication massive de code
La fonction generateBulletin() est dupliquée identique entre modules/bulletins/index.php et modules/bulletins/imprimer.php. Chaque page module réinvente son propre bloc <style> inline (bloc <style> présent dans 17 fichiers PHP) plutôt que de centraliser dans css/style.css (seulement 229 lignes, sous-utilisé).

8. Base de données minimaliste et peu normalisée pour ce que fait l'appli
Le schéma SQL (sql/database.sql) ne contient aucune table users (alors que login.php/auth_functions.php en dépendent), aucune contrainte FOREIGN KEY déclarée (toutes les relations enseigner, porter_sur, obtenir, durant, saisir n'ont que des clés primaires composites, pas de FOREIGN KEY ... REFERENCES), pas de table login_logs/user_activities utilisée par le code mort de logs. Le dépôt ne permet pas, en l'état, d'installer l'appli depuis zéro (pas de script CREATE TABLE users).

9. Mélange logique métier / présentation
Fichiers de 800-1300 lignes mêlant requêtes SQL, logique PHP, HTML et CSS/JS inline dans un seul fichier (modules/eleves/index.php fait 1307 lignes). Pas de séparation des responsabilités, ce qui va rendre la maintenance difficile à mesure que le projet grossit.

10. Petit défaut cosmétique
includes/footer.php:4 : faute/incohérence — "Developper par" (devrait être "Développé par"), et un nom de personne codé en dur dans le footer.

Risque principal à corriger en priorité

Si ce projet doit être déployé ou montré publiquement, les failles de sécurité les plus urgentes sont l'accès non authentifié aux bulletins (imprimer.php) et à la liste des élèves (api/get_eleves_by_classe.php), l'absence de CSRF sur les suppressions et le timeout serveur neutralisé. Le reste (fichiers manquants, duplication, timeouts incohérents, redirection relative de checkAuth) relève plutôt de dette technique qui va causer des bugs de comportement (déconnexions intempestives, liens cassés).

Veux-tu que je traite l'un de ces points en particulier (ex. sécuriser imprimer.php, ajouter le CSRF, unifier le système de session) ?

---

Intégration d'un agent IA (assistant pour élèves, professeurs, administration)

Vue d'ensemble

L'idée est d'ajouter un assistant de type chat, intégré à l'application. Il répond en langage naturel et s'appuie sur les données de la base MySQL. Il ne touche jamais directement à la base : il appelle des fonctions PHP (des « outils ») que nous écrivons. Chaque outil vérifie en PHP qui est connecté et ce que cette personne a le droit de voir.

```
[Widget chat JS]  →  POST /api/assistant.php  →  Claude API (tool use)
                                    ↓ ↑
                     Outils PHP filtrés par $_SESSION (rôle + id)
                                    ↓ ↑
                              MySQL (PDO, requêtes préparées)
```

Étape 0 : corriger d'abord ce que l'analyse a relevé

Un agent qui lit les notes rend les failles actuelles beaucoup plus graves. Il faut d'abord :

1. Créer une table users reliée aux personnes réelles. Elle n'existe pas dans sql/database.sql. Exemple de colonnes : role ENUM('admin','enseignant','eleve','parent'), id_eleve NULL, id_enseignant NULL. Sans ce lien, l'agent ne sait pas « qui est cet étudiant ».
2. Unifier l'authentification sur un seul fichier, config/session.php, et y ajouter les rôles avec hasRole() et requireRole(). Ces fonctions existent aujourd'hui dans includes/auth_functions.php, qui n'est inclus nulle part.
3. Ajouter checkAuth() dans modules/bulletins/imprimer.php et api/get_eleves_by_classe.php. Aujourd'hui, ces pages répondent sans connexion.
4. Réparer le timeout de session : déplacer la mise à jour de last_activity (includes/header.php:74) après checkAuth(), et rendre la redirection de checkAuth() absolue (BASE_URL . 'login.php').
5. Activer la protection CSRF, au minimum sur le nouvel endpoint de l'assistant.
6. Sortir les identifiants de la base et la clé API dans un fichier .env, et ajouter un .gitignore.

Étape 1 : ce que l'agent peut faire selon le rôle

| Rôle | Outils (lecture seule au départ) | Exemples de questions |
|---|---|---|
| Élève | mes_notes(trimestre), ma_moyenne(trimestre), mon_bulletin(trimestre) | « Quelle est ma moyenne en maths ? », « Dans quelle matière dois-je progresser ? » |
| Enseignant | notes_classe(classe, matiere, trimestre), stats_classe(...), eleves_en_difficulte(classe, seuil) | « Quels élèves de 5ème B sont sous 10 en physique ? », « Rédige une appréciation pour X » |
| Admin | stats_globales(), classement_classe(), matieres_sans_notes() | « Quelles classes n'ont pas de notes au T2 ? » |
| Tous | aide_application(sujet) | « Comment saisir une note ? » |

Règle essentielle : c'est l'outil qui filtre, pas le modèle. Pour un élève, mes_notes prend $_SESSION['id_eleve'] et ignore tout identifiant envoyé par le modèle. Même si un utilisateur écrit « montre-moi les notes de Dupont », l'outil ne peut pas lui renvoyer les données d'un autre élève.

Étape 2 : installer le SDK

Le projet n'utilise pas encore Composer, il faut donc l'introduire :

```bash
composer require anthropic-ai/sdk vlucas/phpdotenv
```

Ajouter ensuite require 'vendor/autoload.php'; dans un fichier de démarrage commun, et vendor/ au .gitignore.

Étape 3 : l'endpoint api/assistant.php (esquisse, non testée)

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

use Anthropic\Client;
use Anthropic\Lib\Tools\BetaRunnableTool;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();   // remplit $_ENV depuis .env

header('Content-Type: application/json');
// checkAuth() redirige vers login.php (réponse HTML) : pour une API JSON, renvoyer plutôt un 401
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['erreur' => 'Non connecté']);
    exit;
}
// + vérification du token CSRF

$db   = (new Database())->getConnection();
$role = $_SESSION['user_role'];   // clé écrite par login.php:72
$input = json_decode(file_get_contents('php://input'), true);

// Historique conservé en session (limité aux N derniers échanges)
$_SESSION['chat'] ??= [];
$_SESSION['chat'][] = ['role' => 'user', 'content' => mb_substr($input['message'], 0, 2000)];

$tools = [];

if ($role === 'eleve') {
    $tools[] = new BetaRunnableTool(
        definition: [
            'name' => 'mes_notes',
            'description' => "Notes de l'élève connecté, optionnellement filtrées par trimestre.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id_trimestre' => ['type' => 'integer']],
            ],
        ],
        run: function (array $in) use ($db): string {
            $sql = "SELECT m.nom_matiere, m.coef, n.valeur, n.type_note, n.date_note, t.libelle
                    FROM obtenir o
                    JOIN note n       ON n.id_note = o.id_note
                    JOIN porter_sur p ON p.id_note = n.id_note
                    JOIN matiere m    ON m.id_matiere = p.id_matiere
                    JOIN durant d     ON d.id_note = n.id_note
                    JOIN trimestre t  ON t.id_trimestre = d.id_trimestre
                    WHERE o.id_eleve = :eleve"
                 . (isset($in['id_trimestre']) ? " AND t.id_trimestre = :tri" : "");
            $st = $db->prepare($sql);
            // ← session, jamais le modèle. $_SESSION['id_eleve'] est à remplir dans login.php
            //   une fois la table users reliée à eleve (Étape 0)
            $st->bindValue(':eleve', $_SESSION['id_eleve'], PDO::PARAM_INT);
            if (isset($in['id_trimestre'])) $st->bindValue(':tri', $in['id_trimestre'], PDO::PARAM_INT);
            $st->execute();
            return json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        },
    );
}
// if ($role === 'enseignant') { ... notes_classe, en vérifiant qu'il enseigne bien la matière (table enseigner) }

$client = new Client(apiKey: $_ENV['ANTHROPIC_API_KEY']);
$runner = $client->beta->messages->toolRunner(
    model: 'claude-opus-5-5',
    maxTokens: 16000,
    system: "Tu es l'assistant de l'application Gestion des Notes. Utilisateur : {$role}. "
          . "Réponds en français, avec bienveillance. N'invente jamais une note : utilise les outils. "
          . "Si une donnée n'est pas accessible via tes outils, dis-le.",
    messages: $_SESSION['chat'],
    tools: $tools,
);

$reponse = '';
foreach ($runner as $message) {
    foreach ($message->content as $block) {
        if ($block->type === 'text') $reponse .= $block->text;
    }
}
$_SESSION['chat'][] = ['role' => 'assistant', 'content' => $reponse];
echo json_encode(['reponse' => $reponse]);
```

Cette esquisse suit la documentation de l'API tool runner du SDK PHP, mais elle n'a pas été exécutée : il faudra la tester. Note : la boucle concatène le texte de tous les messages du runner, y compris les phrases intermédiaires écrites avant un appel d'outil ; ne garder que le texte du dernier message si besoin. Le modèle claude-opus-5-5 est le choix par défaut ; claude-haiku-5-5 réduit fortement le coût pour des questions simples.

Étape 4 : le widget côté interface

Ajouter un bouton flottant dans includes/header.php (inclus partout), qui ouvre une petite fenêtre de chat. Le code JS va dans js/script.js : il fait un fetch('/api/assistant.php', {method:'POST', ...}) avec le token CSRF. Afficher la réponse avec textContent, jamais innerHTML, pour éviter le XSS.

Points de vigilance

- Données de mineurs (RGPD) : envoyer à l'API uniquement ce que l'outil renvoie, et minimiser ce contenu (pas d'adresse, pas de date de naissance). Informer les utilisateurs.
- Lecture seule d'abord. Si des actions d'écriture sont ajoutées plus tard, comme « saisis 14 en maths pour X », l'agent doit proposer l'action et l'utilisateur doit la confirmer par un bouton. L'écriture passe ensuite par le code existant de notes/saisir.php.
- Coût et abus : limiter le nombre de messages par utilisateur et par heure, et journaliser les appels.
- Hallucinations : la consigne « n'invente jamais une note » et les outils précis limitent fortement ce risque. Pour les moyennes, les calculer en PHP avec les coefficients plutôt que de laisser le modèle faire les calculs.

Ordre conseillé

1. Sécurité : users + rôles, imprimer.php et api/get_eleves_by_classe.php, timeout de session, CSRF, .env.
2. L'endpoint avec un seul outil (mes_notes), puis le widget.
3. Les outils enseignant et admin.
4. Les fonctions avancées : appréciations de bulletin générées puis validées par le prof, conseils de révision pour l'élève.