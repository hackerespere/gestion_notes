<?php
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Durée de session en secondes (5 minutes = 300 secondes)
define('SESSION_TIMEOUT', 300);

// URL de base de l'application (déjà définie si header.php ou index.php est passé avant)
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
    
    // Mettre à jour le timestamp de la dernière activité
    $_SESSION['last_activity'] = time();
}

// Fonction de déconnexion
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
    
    // Supprimer les cookies "Se souvenir de moi"
    if (isset($_COOKIE['user_id'])) {
        setcookie('user_id', '', time() - 3600, '/');
        setcookie('user_email', '', time() - 3600, '/');
        setcookie('user_name', '', time() - 3600, '/');
        setcookie('remember_token', '', time() - 3600, '/');
    }
    
    // Détruire la session
    session_destroy();
}
?>