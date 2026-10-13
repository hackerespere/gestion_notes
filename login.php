<?php
// ========================================
// DÉMARRER LA SESSION EN PREMIER (avant toute sortie)
// ========================================
session_start();

// ========================================
// CONFIGURATION DE LA BASE DE DONNÉES
// ========================================
$host = 'localhost';
$dbname = 'gestion_notes';
$username = 'root';
$password_db = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur de connexion à la base de données");
}

// ========================================
// REDIRIGER SI DÉJÀ CONNECTÉ
// ========================================
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$timeout_msg = '';

// ========================================
// GESTION DU TIMEOUT DE SESSION
// ========================================
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $timeout_msg = 'Votre session a expiré. Veuillez vous reconnecter.';
}

// ========================================
// TRAITEMENT DU FORMULAIRE DE CONNEXION
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // Validation
    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez saisir une adresse email valide.';
    } else {
        try {
            $query = "SELECT * FROM users WHERE email = :email AND status = 'active' LIMIT 1";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch();
if ($user && password_verify($password, $user['password'])) {
    // ========================================
    // CONNEXION RÉUSSIE
    // ========================================
    // Régénérer l'ID de session (sécurité)
    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['nom'] . ' ' . $user['prenom'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_role']     = $user['role'] ?? 'user';
    $_SESSION['login_time']    = time();          // ← Heure de connexion
    $_SESSION['last_activity'] = time();          // ← IMPORTANT : même clé que session.php

    // Mettre à jour la dernière connexion
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
    } catch (PDOException $e) {
        error_log('Update last_login: ' . $e->getMessage());
    }

    // Cookie de session persistante
    if ($remember) {
        $cookieExpire = time() + (86400 * 30);
        setcookie('user_id',    $user['id'],             $cookieExpire, '/');
        setcookie('user_email', $user['email'],          $cookieExpire, '/');
        setcookie('user_name',  $_SESSION['user_name'],  $cookieExpire, '/');
    }

    // Redirection
    header('Location: index.php');
    exit;
} else {
                $error = 'Email ou mot de passe incorrect.';
            }
        } catch (PDOException $e) {
            $error = 'Erreur lors de la connexion. Veuillez réessayer.';
            error_log('Erreur login: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Gestion des Notes Scolaires</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ========================================
           RESET & BASE
           ======================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* ========================================
           CONTAINER PRINCIPAL
           ======================================== */
        .login-container {
            width: 100%;
            max-width: 1100px;
            display: flex;
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12);
            min-height: 580px;
        }

        /* ========================================
           COLONNE GAUCHE - BRANDING
           ======================================== */
        .login-brand {
            flex: 0 0 48%;
            background: linear-gradient(145deg, #0f172a 0%, #1e293b 100%);
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .login-brand::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 70% 30%, rgba(99, 102, 241, 0.08) 0%, transparent 70%);
            animation: rotateBg 30s linear infinite;
        }

        @keyframes rotateBg {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .login-brand .brand-content {
            position: relative;
            z-index: 1;
        }

        .login-brand .brand-logo {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 36px;
        }

        .login-brand .brand-logo .logo-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: white;
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
            flex-shrink: 0;
        }

        .login-brand .brand-logo .logo-text {
            color: white;
        }

        .login-brand .brand-logo .logo-text h1 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }

        .login-brand .brand-logo .logo-text span {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 400;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .login-brand .brand-title {
            color: white;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 14px;
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .login-brand .brand-title span {
            background: linear-gradient(135deg, #818cf8, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-brand .brand-description {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.95rem;
            line-height: 1.7;
            margin-bottom: 32px;
            max-width: 380px;
        }

        .login-brand .brand-features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .login-brand .brand-features .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .login-brand .brand-features .feature-item i {
            color: #818cf8;
            font-size: 0.9rem;
            background: rgba(99, 102, 241, 0.15);
            padding: 3px;
            border-radius: 50%;
        }

        /* ========================================
           COLONNE DROITE - FORMULAIRE
           ======================================== */
        .login-form {
            flex: 1;
            padding: 48px 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
        }

        .login-form .form-header {
            margin-bottom: 32px;
        }

        .login-form .form-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .login-form .form-header p {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        /* ========================================
           MESSAGES
           ======================================== */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-warning {
            background: #fffbeb;
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        .alert i {
            font-size: 1.1rem;
        }

        /* ========================================
           FORMULAIRE
           ======================================== */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            font-weight: 500;
            font-size: 0.8rem;
            color: #334155;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group label i {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .input-group-custom {
            position: relative;
        }

        .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1rem;
            z-index: 2;
        }

        .input-group-custom .form-control {
            padding: 12px 16px 12px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: #f8fafc;
            height: 50px;
            color: #0f172a;
        }

        .input-group-custom .form-control::placeholder {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .input-group-custom .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            background: white;
        }

        .input-group-custom .form-control.is-invalid {
            border-color: #ef4444;
        }

        .input-group-custom .form-control.is-invalid:focus {
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .input-group-custom .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
            font-size: 1rem;
            z-index: 2;
            transition: color 0.2s ease;
        }

        .input-group-custom .toggle-password:hover {
            color: #0f172a;
        }

        .invalid-feedback {
            font-size: 0.75rem;
            color: #ef4444;
            margin-top: 4px;
        }

        /* ========================================
           OPTIONS
           ======================================== */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 6px 0 20px 0;
        }

        .form-options .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #475569;
            cursor: pointer;
            user-select: none;
        }

        .form-options .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #6366f1;
            cursor: pointer;
            margin: 0;
        }

        .form-options .forgot-link {
            color: #6366f1;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .form-options .forgot-link:hover {
            color: #4f46e5;
            text-decoration: underline;
        }

        /* ========================================
           BOUTON
           ======================================== */
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 50px;
            cursor: pointer;
            position: relative;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(99, 102, 241, 0.35);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-login .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        .btn-login.loading .spinner {
            display: inline-block;
        }

        .btn-login.loading .btn-text {
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ========================================
           FOOTER DU FORMULAIRE
           ======================================== */
        .form-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .form-footer p {
            color: #94a3b8;
            font-size: 0.85rem;
            margin: 0;
        }

        .form-footer a {
            color: #6366f1;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .form-footer a:hover {
            color: #4f46e5;
            text-decoration: underline;
        }

        /* ========================================
           RESPONSIVE
           ======================================== */
        @media (max-width: 991.98px) {
            .login-container {
                flex-direction: column;
                max-width: 450px;
                min-height: auto;
            }

            .login-brand {
                flex: none;
                padding: 32px 28px;
            }

            .login-brand .brand-title {
                font-size: 1.8rem;
            }

            .login-brand .brand-features {
                grid-template-columns: 1fr 1fr;
            }

            .login-form {
                padding: 32px 28px;
            }
        }

        @media (max-width: 575.98px) {
            body {
                padding: 10px;
            }

            .login-container {
                border-radius: 16px;
                max-width: 100%;
            }

            .login-brand {
                padding: 24px 20px;
            }

            .login-brand .brand-title {
                font-size: 1.4rem;
            }

            .login-brand .brand-description {
                font-size: 0.85rem;
            }

            .login-brand .brand-features {
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }

            .login-brand .brand-features .feature-item {
                font-size: 0.75rem;
            }

            .login-form {
                padding: 24px 18px;
            }

            .login-form .form-header h2 {
                font-size: 1.3rem;
            }

            .form-options {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .input-group-custom .form-control {
                height: 44px;
                font-size: 0.85rem;
            }

            .btn-login {
                height: 44px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 380px) {
            .login-brand .brand-features {
                grid-template-columns: 1fr;
            }
        }

        /* ========================================
           ANIMATION D'ENTRÉE
           ======================================== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-container {
            animation: fadeInUp 0.6s ease;
        }

        .login-brand .brand-content {
            animation: fadeInUp 0.6s ease 0.2s both;
        }

        .login-form {
            animation: fadeInUp 0.6s ease 0.4s both;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Colonne gauche - Branding -->
        <div class="login-brand">
            <div class="brand-content">
                <div class="brand-logo">
                    <div class="logo-icon">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <div class="logo-text">
                        <h1>Gestion Notes</h1>
                        <span>Scolaire</span>
                    </div>
                </div>

                <h2 class="brand-title">
                    Gérez vos notes<br>
                    <span>en toute simplicité</span>
                </h2>

                <p class="brand-description">
                    Accédez à votre espace personnel pour gérer les élèves,
                    les classes, les matières et les notes en toute sécurité.
                </p>

                <div class="brand-features">
                    <div class="feature-item">
                        <i class="bi bi-shield-check"></i>
                        Sécurisé
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-speedometer2"></i>
                        Rapide
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-lightbulb"></i>
                        Intuitif
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-database-check"></i>
                        Fiable
                    </div>
                </div>
            </div>
        </div>

        <!-- Colonne droite - Formulaire -->
        <div class="login-form">
            <div class="form-header">
                <h2>Bienvenue !</h2>
                <p>Connectez-vous pour accéder à votre espace</p>
            </div>

            <!-- Message de timeout -->
            <?php if ($timeout_msg): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-clock-history"></i>
                    <?php echo htmlspecialchars($timeout_msg); ?>
                </div>
            <?php endif; ?>

            <!-- Message d'erreur -->
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Message de succès -->
            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Formulaire -->
            <form method="POST" action="" id="loginForm" novalidate>
                <div class="form-group">
                    <label for="email">
                        <i class="bi bi-envelope"></i> Adresse email
                    </label>
                    <div class="input-group-custom">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               placeholder="exemple@email.com" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               required
                               autofocus>
                        <div class="invalid-feedback">Veuillez saisir une adresse email valide.</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="bi bi-lock"></i> Mot de passe
                    </label>
                    <div class="input-group-custom">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Votre mot de passe"
                               required
                               minlength="6">
                        <button type="button" class="toggle-password" onclick="togglePassword()" tabindex="-1">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                        <div class="invalid-feedback">Veuillez saisir votre mot de passe (minimum 6 caractères).</div>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember" <?php echo isset($_POST['remember']) ? 'checked' : ''; ?>>
                        Se souvenir de moi
                    </label>
                    <a href="forgot-password.php" class="forgot-link">Mot de passe oublié ?</a>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">
                        <i class="bi bi-box-arrow-in-right"></i>
                        Se connecter
                    </span>
                </button>
            </form>

            <div class="form-footer">
                <p>
                    Pas encore de compte ?
                    <a href="register.php">Créer un compte</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            const btn = document.getElementById('loginBtn');

            let isValid = true;

            // Reset validation
            email.classList.remove('is-invalid');
            password.classList.remove('is-invalid');

            // Email validation
            if (!email.value || !email.value.includes('@')) {
                email.classList.add('is-invalid');
                isValid = false;
            }

            // Password validation
            if (!password.value || password.value.length < 6) {
                password.classList.add('is-invalid');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                const firstInvalid = document.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                return;
            }

            // Show loading state
            btn.classList.add('loading');
            btn.disabled = true;
        });

        // Remove invalid class on input
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });

            input.addEventListener('blur', function() {
                if (this.value && this.id === 'email' && !this.value.includes('@')) {
                    this.classList.add('is-invalid');
                }
                if (this.value && this.id === 'password' && this.value.length < 6) {
                    this.classList.add('is-invalid');
                }
            });
        });

        // Auto-focus on email if not filled
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (!emailField.value) {
                emailField.focus();
            }
        });
    </script>

    <!-- Bootstrap JS pour les fonctionnalités -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>