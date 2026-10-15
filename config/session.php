<?php
// ========================================
// SESSION ET AUTHENTIFICATION
// Fichier unique : connexion, timeout, rôles, CSRF, messages flash.
// ========================================
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/database.php';

// Démarrer la session si elle n'est pas déjà démarrée, avec des cookies protégés
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,   // cookie inaccessible au JavaScript
        'samesite' => 'Lax',  // cookie non envoyé par les formulaires d'autres sites
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// Durée d'inactivité avant déconnexion, en secondes (300 = 5 minutes par défaut)
define('SESSION_TIMEOUT', (int) env('SESSION_TIMEOUT', 300));

// URL de base de l'application (déjà définie si header.php est passé avant)
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // Remonter à la racine depuis modules/xxx/ ou api/
    if (strpos($scriptDir, '/modules/') !== false) {
        $scriptDir = substr($scriptDir, 0, strpos($scriptDir, '/modules/'));
    }
    $scriptDir = preg_replace('#/(api|config|includes)$#', '', $scriptDir);
    $baseUrl = rtrim($scriptDir, '/') . '/';
    define('BASE_URL', $baseUrl === '//' ? '/' : $baseUrl);
}

// ========================================
// CONNEXION ET TIMEOUT
// ========================================

// Vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Vérifier si la session est expirée
function isSessionExpired() {
    if (!isset($_SESSION['last_activity'])) {
        return true;
    }
    $inactive_time = time() - $_SESSION['last_activity'];
    return $inactive_time > SESSION_TIMEOUT;
}

// Vérifier l'authentification et la session
function checkAuth() {
    // Vérifier si l'utilisateur est connecté
    if (!isLoggedIn()) {
        // Rediriger vers la page de connexion
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }

    // Vérifier si la session a expiré
    if (isSessionExpired()) {
        // Détruire la session
        session_unset();
        session_destroy();

        // Rediriger vers la page de connexion avec message
        header('Location: ' . BASE_URL . 'login.php?timeout=1');
        exit;
    }

    // Relire le compte en base : un compte désactivé ou supprimé perd l'accès immédiatement,
    // et un changement de rôle s'applique sans attendre une nouvelle connexion
    $stmt = (new Database())->getConnection()->prepare("SELECT role, id_eleve, status FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $compte = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$compte || $compte['status'] !== 'active') {
        logout();
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
    $_SESSION['user_role'] = $compte['role'];
    $_SESSION['id_eleve']  = $compte['role'] === ROLE_ELEVE ? (int) $compte['id_eleve'] : null;

    // Mettre à jour le timestamp de la dernière activité
    $_SESSION['last_activity'] = time();
}

// ========================================
// RÔLES
// ========================================

// Administration : comptes utilisateurs
const ROLES_ADMIN = ['admin'];
// Gestion de l'établissement : élèves, classes, matières (ajout, modification, suppression)
const ROLES_GESTION = ['admin', 'directeur'];
// Travail pédagogique : consulter les données, saisir les notes, éditer les bulletins
const ROLES_PEDAGOGIE = ['admin', 'directeur', 'enseignant'];
// Élève : accès en lecture à ses propres notes et bulletins uniquement (espace élève)
const ROLE_ELEVE = 'eleve';

// L'utilisateur connecté a-t-il l'un des rôles donnés ?
function hasRole($roles) {
    return in_array($_SESSION['user_role'] ?? '', (array) $roles, true);
}

// Exiger une connexion et l'un des rôles donnés, sinon retour au tableau de bord.
// À appeler avant toute sortie HTML (donc avant d'inclure header.php).
function requireRole($roles) {
    checkAuth();
    if (!hasRole($roles)) {
        setFlash('danger', "Vous n'avez pas les droits pour accéder à cette page.");
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

// Espace élève : exiger un compte élève et renvoyer l'identifiant de SA fiche.
// L'identifiant vient toujours de la session (fixé à la connexion), jamais de l'URL,
// pour qu'un élève ne puisse pas consulter les notes d'un autre.
function requireEleve() {
    requireRole([ROLE_ELEVE]);
    return (int) $_SESSION['id_eleve'];
}

// ========================================
// PROTECTION CSRF
// ========================================

// Jeton CSRF de la session (créé à la première demande)
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Champ caché à placer dans chaque formulaire POST
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// Vérifier le jeton CSRF envoyé par un formulaire POST
function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Formulaire envoyé en POST avec un jeton valide : le traitement peut avoir lieu
function isPostWithValidCsrf() {
    return $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf();
}

// Message à afficher si un formulaire POST arrive sans jeton valide ('' sinon)
function csrfError() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
        return 'La session a expiré ou la requête est invalide. Veuillez renvoyer le formulaire.';
    }
    return '';
}

// Pour les actions sensibles (suppression...) : exiger POST + jeton CSRF valide,
// sinon rediriger vers $redirect avec un message d'erreur
function requirePostWithCsrf($redirect) {
    if (!isPostWithValidCsrf()) {
        setFlash('danger', 'Requête invalide ou expirée. Veuillez réessayer.');
        header('Location: ' . $redirect);
        exit;
    }
}

// ========================================
// MESSAGES FLASH
// ========================================

// Message affiché une seule fois après une redirection (type : success, danger, warning)
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// ========================================
// DÉCONNEXION
// ========================================

function logout() {
    // Supprimer toutes les variables de session
    $_SESSION = array();

    // Supprimer le cookie de session
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Supprimer les anciens cookies "Se souvenir de moi" (fonction retirée)
    foreach (['user_id', 'user_email', 'user_name', 'remember_token'] as $cookie) {
        if (isset($_COOKIE[$cookie])) {
            setcookie($cookie, '', time() - 3600, '/');
        }
    }

    // Détruire la session
    session_destroy();
}
?>
