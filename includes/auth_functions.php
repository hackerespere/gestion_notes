<?php
/**
 * ========================================
 * CONFIGURATION DE SESSION ET AUTHENTIFICATION
 * ========================================
 * 
 * Ce fichier gère :
 * - Le démarrage sécurisé de la session
 * - Les fonctions d'authentification (isLoggedIn, hasRole, ...)
 * - La vérification du timeout d'inactivité
 * - Les redirections avec chemins absolus
 * - Les logs de connexion et d'activité
 */

// ========================================
// PROTECTION CONTRE DOUBLE INCLUSION
// ========================================
if (defined('SESSION_CONFIG_LOADED')) {
    return;
}
define('SESSION_CONFIG_LOADED', true);

// ========================================
// DÉTECTION DU CHEMIN DE BASE
// ========================================
if (!defined('BASE_PATH')) {
    // session.php est dans /gestion_notes/config/
    define('BASE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('BASE_URL')) {
    // Calculer l'URL de base dynamiquement
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    
    // Si on est dans un sous-dossier (modules/xxx/), remonter à la racine
    if (strpos($scriptDir, '/modules/') !== false) {
        $scriptDir = substr($scriptDir, 0, strpos($scriptDir, '/modules/'));
    }
    
    // Si on est dans /config/ (appel direct), remonter
    if (substr($scriptDir, -7) === '/config') {
        $scriptDir = substr($scriptDir, 0, -7);
    }
    
    $baseUrl = rtrim($scriptDir, '/') . '/';
    if ($baseUrl === '//') $baseUrl = '/';
    if ($baseUrl === '') $baseUrl = '/';
    
    define('BASE_URL', $baseUrl);
}

// ========================================
// CONFIGURATION DU TIMEOUT DE SESSION
// ========================================
if (!defined('SESSION_TIMEOUT')) {
    // 30 minutes par défaut (1800 secondes)
    define('SESSION_TIMEOUT', 1800);
}

if (!defined('SESSION_WARNING_TIME')) {
    // Avertir 1 minute avant expiration
    define('SESSION_WARNING_TIME', 60);
}

// ========================================
// CONFIGURATION DES COOKIES DE SESSION
// ========================================
// À configurer AVANT session_start()
if (session_status() === PHP_SESSION_NONE) {
    // Sécurité des cookies de session
    ini_set('session.cookie_httponly', 1);  // Empêche l'accès JS
    ini_set('session.use_only_cookies', 1); // Pas d'ID en URL
    ini_set('session.cookie_samesite', 'Lax');
    
    // En production (HTTPS), décommenter :
    // ini_set('session.cookie_secure', 1);
    
    session_start();
}

// ========================================
// GESTION DU TIMEOUT DE SESSION
// ========================================
/**
 * Vérifie et met à jour le timeout de session
 * Déconnecte si la session a expiré
 */
function checkSessionTimeout() {
    if (!isset($_SESSION['user_id'])) {
        return false; // Pas de session à vérifier
    }

    $lastActivity = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time();
    $elapsed = time() - $lastActivity;

    // Session expirée
    if ($elapsed > SESSION_TIMEOUT) {
        // Détruire complètement la session
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        session_destroy();
        return false;
    }

    // Rafraîchir le timer
    $_SESSION['last_activity'] = time();
    return true;
}

// Lancer la vérification automatiquement
checkSessionTimeout();

// ========================================
// FONCTIONS D'AUTHENTIFICATION
// ========================================

/**
 * Vérifie si l'utilisateur est connecté ET si la session n'a pas expiré
 */
function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }

    // Vérifier le timeout
    $lastActivity = $_SESSION['last_activity'] ?? time();
    if ((time() - $lastActivity) > SESSION_TIMEOUT) {
        return false;
    }

    return true;
}

/**
 * Vérifie si l'utilisateur a un rôle spécifique
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Vérifie si l'utilisateur a au moins un des rôles spécifiés
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

/**
 * ========================================
 * FONCTION PRINCIPALE : checkAuth()
 * ========================================
 * Vérifie l'authentification et redirige si nécessaire.
 * Utilisée automatiquement par header.php
 */
function checkAuth() {
    if (!isLoggedIn()) {
        // Nettoyer la session
        $_SESSION = [];
        
        // Construire l'URL de redirection
        $redirect = BASE_URL . 'login.php';
        
        // Ajouter le paramètre timeout si la session a expiré
        if (isset($_SESSION['user_id']) || isset($_SESSION['last_activity'])) {
            $redirect .= '?timeout=1';
        }
        
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Rediriger si non connecté (alias de checkAuth)
 */
function requireLogin() {
    checkAuth();
}

/**
 * Rediriger si déjà connecté (pour login.php, register.php)
 */
function requireLogout() {
    if (isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

/**
 * Rediriger si le rôle n'est pas autorisé
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: ' . BASE_URL . 'index.php?error=unauthorized');
        exit;
    }
}

/**
 * Rediriger si l'utilisateur n'a aucun des rôles autorisés
 */
function requireAnyRole($roles) {
    requireLogin();
    if (!hasAnyRole($roles)) {
        header('Location: ' . BASE_URL . 'index.php?error=unauthorized');
        exit;
    }
}

// ========================================
// FONCTIONS UTILISATEUR
// ========================================

/**
 * Récupérer les informations complètes de l'utilisateur connecté
 */
function getCurrentUser($pdo) {
    if (!isLoggedIn()) {
        return null;
    }

    try {
        $query = "SELECT * FROM users WHERE id = :id AND status = 'active' LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erreur getCurrentUser: ' . $e->getMessage());
        return null;
    }
}

/**
 * Connecte un utilisateur (création de session)
 */
function loginUser($user) {
    // Régénérer l'ID de session (protection contre session fixation)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']      = $user['id'];
    $_SESSION['user_name']    = ($user['nom'] ?? '') . ' ' . ($user['prenom'] ?? '');
    $_SESSION['user_email']   = $user['email'] ?? '';
    $_SESSION['user_role']    = $user['role'] ?? 'user';
    $_SESSION['login_time']   = time();
    $_SESSION['last_activity'] = time();
}

/**
 * Déconnecte l'utilisateur complètement
 */
function logoutUser() {
    // Vider toutes les variables de session
    $_SESSION = [];

    // Détruire le cookie de session
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Détruire la session
    session_destroy();

    // Détruire les cookies "remember me"
    foreach (['user_id', 'user_email', 'user_name'] as $cookie) {
        if (isset($_COOKIE[$cookie])) {
            setcookie($cookie, '', time() - 3600, '/');
        }
    }
}

// ========================================
// FONCTIONS DE MOT DE PASSE
// ========================================

/**
 * Vérifier un mot de passe
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Hacher un mot de passe
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
}

/**
 * Vérifier si un hash doit être re-haché (mise à jour de l'algo)
 */
function needsRehash($hash) {
    return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12]);
}

// ========================================
// FONCTIONS DE TOKEN
// ========================================

/**
 * Générer un token aléatoire sécurisé
 * @param int $bytes Nombre de bytes (le résultat fait 2x cette longueur en hex)
 * @return string
 */
function generateToken($bytes = 32) {
    return bin2hex(random_bytes($bytes));
}

/**
 * Vérifier un token CSRF
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) 
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Générer un token CSRF pour les formulaires
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken(32);
    }
    return $_SESSION['csrf_token'];
}

// ========================================
// LOGS (avec protection contre erreurs)
// ========================================

/**
 * Enregistrer une tentative de connexion
 * (ne plante PAS si la table n'existe pas)
 */
function logLoginAttempt($pdo, $email, $ip, $userAgent, $success, $userId = null) {
    try {
        $query = "INSERT INTO login_logs (user_id, email, ip_address, user_agent, login_success) 
                  VALUES (:user_id, :email, :ip_address, :user_agent, :login_success)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':ip_address', $ip);
        $stmt->bindParam(':user_agent', $userAgent);
        $stmt->bindParam(':login_success', $success, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        // Non bloquant : on log l'erreur mais on ne plante pas
        error_log('logLoginAttempt: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enregistrer une activité utilisateur
 * (ne plante PAS si la table n'existe pas)
 */
function logUserActivity($pdo, $userId, $action, $description = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $query = "INSERT INTO user_activities (user_id, action, description, ip_address, user_agent) 
                  VALUES (:user_id, :action, :description, :ip_address, :user_agent)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':ip_address', $ip);
        $stmt->bindParam(':user_agent', $userAgent);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log('logUserActivity: ' . $e->getMessage());
        return false;
    }
}

// ========================================
// FONCTIONS UTILITAIRES DE SESSION
// ========================================

/**
 * Récupérer le temps restant avant expiration (en secondes)
 */
function getSessionTimeRemaining() {
    if (!isLoggedIn()) {
        return 0;
    }
    $lastActivity = $_SESSION['last_activity'] ?? time();
    $elapsed = time() - $lastActivity;
    return max(0, SESSION_TIMEOUT - $elapsed);
}

/**
 * Prolonger manuellement la session
 */
function extendSession() {
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}