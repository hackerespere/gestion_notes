<?php
// ========================================
// PROTECTION CONTRE DOUBLE INCLUSION
// ========================================
if (defined('HEADER_INCLUDED')) {
    return;
}
define('HEADER_INCLUDED', true);

// ========================================
// DÉTECTION DU CHEMIN DE BASE
// ========================================
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    // Si on est dans un sous-dossier (modules/xxx/), remonter à la racine
    if (strpos($scriptDir, '/modules/') !== false) {
        $scriptDir = substr($scriptDir, 0, strpos($scriptDir, '/modules/'));
    }

    // Si on est dans /config/ ou /includes/, remonter à la racine
    if (preg_match('#/(config|includes)$#', $scriptDir)) {
        $scriptDir = preg_replace('#/(config|includes)$#', '', $scriptDir);
    }

    $baseUrl = rtrim($scriptDir, '/') . '/';
    if ($baseUrl === '//' || $baseUrl === '') {
        $baseUrl = '/';
    }

    define('BASE_URL', $baseUrl);
}

// ========================================
// CHARGEMENT DE LA CONFIGURATION DE SESSION
// ========================================
// session.php est le seul fichier d'authentification : session sécurisée,
// SESSION_TIMEOUT, checkAuth(), rôles (hasRole/requireRole), CSRF, messages flash
require_once BASE_PATH . 'config/session.php';

// ========================================
// VÉRIFICATION DE L'AUTHENTIFICATION
// ========================================
// checkAuth() contrôle la connexion et l'inactivité, puis rafraîchit last_activity
$current_page = basename($_SERVER['PHP_SELF']);
$public_pages = ['login.php', 'logout.php'];

if (!in_array($current_page, $public_pages)) {
    checkAuth();
}

// ========================================
// INFORMATIONS UTILISATEUR
// ========================================
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_name  = $_SESSION['user_name']  ?? 'Utilisateur';
$user_role  = $_SESSION['user_role']  ?? 'user';
$user_email = $_SESSION['user_email'] ?? '';

// Initiales pour l'avatar
$user_initial = 'U';
if ($user_name && $user_name !== 'Utilisateur') {
    $nameParts = explode(' ', trim($user_name));
    $user_initial = strtoupper(substr($nameParts[0] ?? '', 0, 1));
    if (isset($nameParts[1]) && !empty($nameParts[1])) {
        $user_initial .= strtoupper(substr($nameParts[1], 0, 1));
    }
}

// Libellés de rôle
$roleLabels = [
    'admin'      => 'Administrateur',
    'directeur'  => 'Directeur',
    'enseignant' => 'Enseignant',
    'parent'     => 'Parent',
    'eleve'      => 'Élève',
    'user'       => 'Utilisateur',
];
$user_role_label = $roleLabels[$user_role] ?? 'Utilisateur';

// Couleurs d'avatar selon le rôle
$roleColors = [
    'admin'      => 'linear-gradient(135deg, #4F46E5, #7C3AED)',
    'directeur'  => 'linear-gradient(135deg, #0EA5E9, #3B82F6)',
    'enseignant' => 'linear-gradient(135deg, #10B981, #059669)',
    'parent'     => 'linear-gradient(135deg, #F59E0B, #D97706)',
    'eleve'      => 'linear-gradient(135deg, #EC4899, #DB2777)',
    'user'       => 'linear-gradient(135deg, #6B7280, #4B5563)',
];
$avatar_color = $roleColors[$user_role] ?? $roleColors['user'];

// Timeout session (pour le JS côté client)
$session_timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800;
$last_activity   = $_SESSION['last_activity'] ?? time();
$time_remaining  = max(0, $session_timeout - (time() - $last_activity));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Notes Scolaires</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/style.css">
    <style>
        /* Reset et base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            padding-top: 70px;
        }

        /* Navbar professionnelle */
        .navbar-pro {
            background: #ffffff !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            padding: 0;
            height: 70px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            transition: all 0.3s ease;
        }

        .navbar-pro .container-fluid {
            height: 100%;
            display: flex;
            align-items: center;
        }

        /* Brand / Logo */
        .navbar-brand {
            font-weight: 700;
            font-size: 1.25rem;
            color: #1a202c !important;
            padding: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.5px;
            text-decoration: none;
        }

        .navbar-brand .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #4F46E5, #7C3AED);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .navbar-brand .brand-text { color: #1a202c; }
        .navbar-brand .brand-text span { color: #4F46E5; }

        .navbar-brand .brand-sub {
            font-size: 0.65rem;
            font-weight: 400;
            color: #94a3b8;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-left: 2px;
        }

        /* Navigation links */
        .navbar-nav {
            gap: 2px;
            height: 100%;
            align-items: center;
        }

        .nav-item {
            height: 100%;
            display: flex;
            align-items: center;
        }

        .nav-link {
            color: #475569 !important;
            font-weight: 500;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            align-items: center;
            gap: 6px;
            height: 40px;
            text-decoration: none;
        }

        .nav-link i {
            font-size: 1rem;
            color: #94a3b8;
            transition: color 0.2s ease;
        }

        .nav-link:hover { background: #f1f5f9; color: #1a202c !important; }
        .nav-link:hover i { color: #4F46E5; }
        .nav-link.active { background: #f1f5f9; color: #1a202c !important; font-weight: 600; }
        .nav-link.active i { color: #4F46E5; }

        .nav-link .nav-badge {
            background: #4F46E5;
            color: white;
            font-size: 0.6rem;
            padding: 2px 6px;
            border-radius: 12px;
            margin-left: 4px;
            font-weight: 600;
        }

        /* Dropdown */
        .dropdown-toggle::after {
            border: none;
            content: '\f282';
            font-family: 'bootstrap-icons';
            font-size: 0.6rem;
            margin-left: 4px;
            vertical-align: middle;
            transition: transform 0.2s ease;
        }

        .dropdown-toggle[aria-expanded="true"]::after {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 8px;
            min-width: 220px;
            margin-top: 10px;
            background: #ffffff;
        }

        .dropdown-item {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #475569;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .dropdown-item i {
            font-size: 1.1rem;
            color: #94a3b8;
            width: 20px;
            text-align: center;
            transition: color 0.2s ease;
        }

        .dropdown-item:hover {
            background: #f1f5f9;
            color: #1a202c;
            transform: translateX(4px);
        }

        .dropdown-item:hover i { color: #4F46E5; }

        .dropdown-item.text-danger:hover {
            background: #fef2f2;
            color: #dc2626 !important;
        }

        .dropdown-item.text-danger:hover i { color: #dc2626; }

        .dropdown-divider {
            margin: 6px 0;
            border-color: #f1f5f9;
        }

        .dropdown-header {
            font-size: 0.6rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 14px;
        }

        /* Right section */
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 100%;
        }

        .navbar-right .divider {
            width: 1px;
            height: 30px;
            background: #e2e8f0;
        }

        /* User Info Dropdown */
        .navbar-right .user-dropdown { position: relative; }

        .navbar-right .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 12px 4px 4px;
            border-radius: 50px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            user-select: none;
        }

        .navbar-right .user-info:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .navbar-right .user-info .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: <?php echo $avatar_color; ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .navbar-right .user-info .user-details {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .navbar-right .user-info .user-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: #1a202c;
        }

        .navbar-right .user-info .user-role {
            font-size: 0.6rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .navbar-right .user-info .user-chevron {
            font-size: 0.6rem;
            color: #94a3b8;
            transition: transform 0.2s ease;
            margin-left: 4px;
        }

        /* User Dropdown Menu */
        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
            padding: 8px;
            min-width: 240px;
            display: none;
            border: 1px solid #e2e8f0;
            z-index: 1040;
        }

        .user-dropdown-menu.show {
            display: block;
            animation: slideDown 0.2s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .user-dropdown-menu .dropdown-header {
            padding: 8px 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .user-dropdown-menu .dropdown-header .header-name {
            font-weight: 600;
            color: #1a202c;
            font-size: 0.9rem;
        }

        .user-dropdown-menu .dropdown-header .header-email {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .navbar-right .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            position: relative;
            cursor: pointer;
        }

        .navbar-right .btn-icon:hover {
            background: #f1f5f9;
            color: #1a202c;
        }

        .navbar-right .btn-icon .notification-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 8px;
            height: 8px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid white;
        }

        /* Toggler */
        .navbar-toggler {
            border: none;
            padding: 8px;
            border-radius: 8px;
            color: #475569;
        }

        .navbar-toggler:hover { background: #f1f5f9; }
        .navbar-toggler:focus { box-shadow: none; }

        /* Responsive */
        @media (max-width: 991.98px) {
            .navbar-pro { height: auto; min-height: 70px; }
            .navbar-nav { padding: 16px 0; gap: 4px; }
            .nav-item { height: auto; }
            .nav-link { height: auto; padding: 10px 16px; }

            .navbar-right {
                padding: 12px 0;
                border-top: 1px solid #e2e8f0;
                margin-top: 8px;
                flex-wrap: wrap;
            }

            .navbar-right .divider { display: none; }

            .dropdown-menu {
                border: none;
                box-shadow: none;
                padding: 0;
                margin: 0;
                background: transparent;
            }

            .dropdown-item { padding: 8px 16px 8px 36px; }
            .dropdown-item:hover { transform: none; }

            .user-dropdown-menu {
                position: static;
                box-shadow: none;
                border: none;
                padding: 0 0 0 16px;
                margin-top: 4px;
                background: transparent;
            }

            .user-dropdown-menu .dropdown-header { display: none; }
        }

        @media (max-width: 576px) {
            .navbar-brand .brand-sub { display: none; }
            .navbar-right .user-info .user-details { display: none; }
        }

        .navbar-pro.scrolled {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        /* Login/Register buttons */
        .btn-login-nav {
            padding: 6px 18px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-login-nav.btn-outline {
            color: #475569;
            border: 1px solid #e2e8f0;
            background: transparent;
        }

        .btn-login-nav.btn-outline:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .btn-login-nav.btn-primary-nav {
            background: linear-gradient(135deg, #4F46E5, #7C3AED);
            color: white;
            border: none;
        }

        .btn-login-nav.btn-primary-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-pro navbar-expand-lg" id="mainNav">
        <div class="container-fluid">
            <!-- Brand / Logo -->
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>index.php">
                <div class="brand-icon">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <span class="brand-text">Gestion<span>Notes</span></span>
                    <span class="brand-sub">Scolaire</span>
                </div>
            </a>

            <!-- Toggler -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <i class="bi bi-list" style="font-size: 1.5rem;"></i>
            </button>

            <!-- Navigation -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php if ($isLoggedIn): ?>
                <!-- Menu pour utilisateur connecté -->
                <ul class="navbar-nav me-auto">
                    <?php if (hasRole(ROLE_ELEVE)): ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="<?php echo BASE_URL; ?>modules/espace_eleve/index.php">
                            <i class="bi bi-mortarboard-fill"></i>
                            Mes notes
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>index.php">
                            <i class="bi bi-grid-1x2-fill"></i>
                            Tableau de bord
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (hasRole(ROLES_PEDAGOGIE)): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-people-fill"></i>
                            Élèves
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/eleves/index.php">
                                <i class="bi bi-list-ul"></i> Liste des élèves
                            </a></li>
                            <?php if (hasRole(ROLES_GESTION)): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/eleves/ajouter.php">
                                <i class="bi bi-person-plus"></i> Ajouter un élève
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-building-fill"></i>
                            Classes
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/classes/index.php">
                                <i class="bi bi-list-ul"></i> Liste des classes
                            </a></li>
                            <?php if (hasRole(ROLES_GESTION)): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/classes/ajouter.php">
                                <i class="bi bi-plus-circle"></i> Ajouter une classe
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-book-fill"></i>
                            Matières
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/matieres/index.php">
                                <i class="bi bi-list-ul"></i> Liste des matières
                            </a></li>
                            <?php if (hasRole(ROLES_GESTION)): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/matieres/ajouter.php">
                                <i class="bi bi-plus-circle"></i> Ajouter une matière
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-pencil-fill"></i>
                            Notes
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/notes/index.php">
                                <i class="bi bi-grid"></i> Gestion des notes
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/notes/saisir.php">
                                <i class="bi bi-plus-circle"></i> Saisir des notes
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/notes/consulter.php">
                                <i class="bi bi-eye"></i> Consulter les notes
                            </a></li>
                        </ul>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'bulletins') !== false ? 'active' : ''; ?>" 
                           href="<?php echo BASE_URL; ?>modules/bulletins/index.php">
                            <i class="bi bi-file-text-fill"></i>
                            Bulletins
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (hasRole(ROLES_ADMIN)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'utilisateurs') !== false ? 'active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>modules/utilisateurs/index.php">
                            <i class="bi bi-person-gear"></i>
                            Comptes
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Right section - Connecté -->
                <div class="navbar-right">
                    <button class="btn-icon" title="Notifications" id="notificationBtn">
                        <i class="bi bi-bell-fill"></i>
                        <span class="notification-dot"></span>
                    </button>
                    
                    <button class="btn-icon" title="Aide" id="helpBtn">
                        <i class="bi bi-question-circle-fill"></i>
                    </button>

                    <div class="divider"></div>

                    <div class="user-dropdown">
                        <div class="user-info" id="userDropdownToggle">
                            <div class="user-avatar"><?php echo htmlspecialchars($user_initial); ?></div>
                            <div class="user-details">
                                <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                                <span class="user-role"><?php echo htmlspecialchars($user_role_label); ?></span>
                            </div>
                            <i class="bi bi-chevron-down user-chevron"></i>
                        </div>
                        
                        <div class="user-dropdown-menu" id="userDropdownMenu">
                            <div class="dropdown-header">
                                <div class="header-name"><?php echo htmlspecialchars($user_name); ?></div>
                                <div class="header-email"><?php echo htmlspecialchars($user_email); ?></div>
                            </div>
                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>profile.php">
                                <i class="bi bi-person"></i> Mon profil
                            </a>
                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>settings.php">
                                <i class="bi bi-gear"></i> Paramètres
                            </a>
                            <?php if (hasRole(ROLES_PEDAGOGIE)): ?>
                            <a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/notes/index.php">
                                <i class="bi bi-pencil"></i> Mes notes
                            </a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>logout.php">
                                <i class="bi bi-box-arrow-right"></i> Se déconnecter
                            </a>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <!-- Menu pour utilisateur non connecté -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" 
                           href="<?php echo BASE_URL; ?>index.php">
                            <i class="bi bi-grid-1x2-fill"></i>
                            Accueil
                        </a>
                    </li>
                </ul>

                <div class="navbar-right">
                    <a href="<?php echo BASE_URL; ?>login.php" class="btn-login-nav btn-outline">
                        <i class="bi bi-box-arrow-in-right"></i> Connexion
                    </a>
                    <a href="<?php echo BASE_URL; ?>register.php" class="btn-login-nav btn-primary-nav">
                        <i class="bi bi-person-plus"></i> Inscription
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <?php $flash = function_exists('getFlash') ? getFlash() : null; ?>
    <?php if ($flash): ?>
    <div class="container mt-3">
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    </div>
    <?php endif; ?>

    <script>
    // ========================================
    // CONFIGURATION GLOBALE (passée par PHP)
    // ========================================
    window.APP_CONFIG = {
        baseUrl: '<?php echo BASE_URL; ?>',
        sessionTimeout: <?php echo (int) $session_timeout; ?>,
        lastActivity: <?php echo (int) $last_activity; ?>,
        isLoggedIn: <?php echo $isLoggedIn ? 'true' : 'false'; ?>
    };

    // ========================================
    // NAVBAR & UI
    // ========================================
    document.addEventListener('DOMContentLoaded', function() {
        const navbar = document.getElementById('mainNav');
        if (navbar) {
            window.addEventListener('scroll', function() {
                if (window.scrollY > 10) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            });
        }

        const userToggle = document.getElementById('userDropdownToggle');
        const userMenu   = document.getElementById('userDropdownMenu');
        
        if (userToggle && userMenu) {
            userToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenu.classList.toggle('show');
            });

            document.addEventListener('click', function(e) {
                if (!userToggle.contains(e.target) && !userMenu.contains(e.target)) {
                    userMenu.classList.remove('show');
                }
            });
        }

        const notifBtn = document.getElementById('notificationBtn');
        if (notifBtn) {
            notifBtn.addEventListener('click', function() {
                const dot = this.querySelector('.notification-dot');
                if (dot) dot.style.display = 'none';
            });
        }

        const helpBtn = document.getElementById('helpBtn');
        if (helpBtn) {
            helpBtn.addEventListener('click', function() {
                alert('Guide d\'utilisation\n\n' +
                      '1. Élèves : Gérez la liste des élèves\n' +
                      '2. Classes : Gérez les classes\n' +
                      '3. Matières : Gérez les matières\n' +
                      '4. Notes : Saisissez et consultez les notes\n' +
                      '5. Bulletins : Générez les bulletins');
            });
        }
    });

    // ========================================
    // GESTION DE SESSION AVEC TIMEOUT
    // ========================================
    (function() {
        if (!window.APP_CONFIG.isLoggedIn) return;

        const SESSION_TIMEOUT = window.APP_CONFIG.sessionTimeout;
        const WARNING_TIME    = 60;
        const BASE_URL        = window.APP_CONFIG.baseUrl;

        let sessionTimer  = null;
        let warningShown  = false;
        let lastActivity  = window.APP_CONFIG.lastActivity;
        let isRedirecting = false;

        function updateSessionTimer() {
            const now       = Math.floor(Date.now() / 1000);
            const elapsed   = now - lastActivity;
            const remaining = Math.max(0, SESSION_TIMEOUT - elapsed);

            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            const timeDisplay = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            document.title = `Gestion Notes (${timeDisplay})`;

            if (remaining <= WARNING_TIME && remaining > 0 && !warningShown) {
                warningShown = true;
                showSessionWarning(remaining);
            }

            if (remaining <= 0 && !isRedirecting) {
                isRedirecting = true;
                clearInterval(sessionTimer);
                sessionExpired();
            }
        }

        function showSessionWarning(remaining) {
            const existing = document.querySelector('.session-warning');
            if (existing) existing.remove();

            const alertDiv = document.createElement('div');
            alertDiv.className = 'session-warning';
            alertDiv.innerHTML = `
                <div class="session-warning-content">
                    <i class="bi bi-clock-fill"></i>
                    <span>Session expire dans <strong id="sessionCountdown">${Math.ceil(remaining)}</strong> s</span>
                    <button class="btn btn-sm btn-light" onclick="extendSession()">
                        <i class="bi bi-arrow-repeat"></i> Prolonger
                    </button>
                </div>
            `;
            alertDiv.style.cssText = `
                position: fixed;
                top: 80px;
                right: 20px;
                z-index: 9999;
                background: #fef3c7;
                color: #92400e;
                padding: 12px 20px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                border-left: 4px solid #f59e0b;
                animation: slideInRight 0.5s ease;
                max-width: 400px;
            `;
            document.body.appendChild(alertDiv);

            const countdownEl = document.getElementById('sessionCountdown');
            if (countdownEl) {
                let count = Math.ceil(remaining);
                const interval = setInterval(() => {
                    count--;
                    if (count <= 0) {
                        clearInterval(interval);
                        return;
                    }
                    if (countdownEl) countdownEl.textContent = count;
                }, 1000);
            }
        }

        window.extendSession = function() {
            fetch(BASE_URL + 'extend_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    const warning = document.querySelector('.session-warning');
                    if (warning) {
                        warning.style.animation = 'slideOutRight 0.5s ease';
                        setTimeout(() => warning.remove(), 500);
                    }
                    warningShown = false;
                    lastActivity = Math.floor(Date.now() / 1000);
                }
            })
            .catch(err => console.error('Erreur extend session:', err));
        };

        function sessionExpired() {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'session-expired';
            alertDiv.innerHTML = `
                <div class="session-expired-content">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Votre session a expiré. Redirection...</span>
                </div>
            `;
            alertDiv.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 99999;
                background: #fee2e2;
                color: #991b1b;
                padding: 24px 32px;
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                text-align: center;
                min-width: 300px;
            `;
            document.body.appendChild(alertDiv);

            setTimeout(() => {
                window.location.href = BASE_URL + 'login.php?timeout=1';
            }, 2000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateSessionTimer();
            sessionTimer = setInterval(updateSessionTimer, 1000);

            let throttleTimer = null;
            const resetEvents = ['click', 'keydown', 'mousemove'];
            resetEvents.forEach(event => {
                document.addEventListener(event, function() {
                    if (throttleTimer) return;
                    throttleTimer = setTimeout(() => {
                        throttleTimer = null;
                        fetch(BASE_URL + 'extend_session.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin'
                        }).catch(() => {});
                        lastActivity = Math.floor(Date.now() / 1000);
                    }, 30000);
                });
            });
        });
    })();

    // ========================================
    // ANIMATIONS CSS
    // ========================================
    (function() {
        const styleSheet = document.createElement("style");
        styleSheet.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to   { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to   { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(styleSheet);
    })();
    </script>
</body>
</html>