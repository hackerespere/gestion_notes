<?php
// Session et fonction logout() communes
require_once 'config/session.php';

// Variables pour l'affichage
$user_name = '';
$logout_message = '';

// Vérifier si l'utilisateur est connecté avant de se déconnecter
if (isset($_SESSION['user_id'])) {
    $user_name = $_SESSION['user_name'] ?? 'Utilisateur';
    $logout_message = "Au revoir $user_name ! Vous avez été déconnecté avec succès.";
} else {
    $logout_message = "Vous n'étiez pas connecté.";
}

// Exécuter la déconnexion
logout();

// Redirection automatique après 3 secondes
$redirect_url = 'login.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déconnexion - Gestion des Notes Scolaires</title>
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
            background: linear-gradient(135deg, #f0f2f5 0%, #e2e8f0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* ========================================
           CARD DE DÉCONNEXION
           ======================================== */
        .logout-card {
            max-width: 480px;
            width: 100%;
            background: white;
            border-radius: 24px;
            padding: 48px 40px 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12);
            text-align: center;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ========================================
           ICÔNE
           ======================================== */
        .logout-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .logout-icon .icon-inner {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .logout-icon .check-circle {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 32px;
            height: 32px;
            background: #22c55e;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            border: 3px solid white;
            animation: popIn 0.5s ease 0.3s both;
        }

        @keyframes popIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* ========================================
           TEXTE
           ======================================== */
        .logout-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .logout-title span {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logout-message {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 8px;
        }

        .logout-user {
            color: #0f172a;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .logout-sub-message {
            color: #94a3b8;
            font-size: 0.85rem;
            margin-top: 4px;
        }

        /* ========================================
           DIVISEUR
           ======================================== */
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 24px 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .divider span {
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ========================================
           BOUTONS
           ======================================== */
        .btn-primary-custom {
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
            gap: 10px;
            text-decoration: none;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(99, 102, 241, 0.35);
            color: white;
        }

        .btn-primary-custom:active {
            transform: translateY(0);
        }

        .btn-secondary-custom {
            width: 100%;
            padding: 14px;
            background: #f1f5f9;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            color: #475569;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            margin-top: 10px;
        }

        .btn-secondary-custom:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        /* ========================================
           TIMER
           ======================================== */
        .timer-container {
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .timer-container .timer-ring {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 3px solid #e2e8f0;
            border-top-color: #6366f1;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .timer-container .timer-text {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .timer-container .timer-text strong {
            color: #0f172a;
        }

        /* ========================================
           RESPONSIVE
           ======================================== */
        @media (max-width: 575.98px) {
            .logout-card {
                padding: 32px 24px 28px;
            }

            .logout-icon {
                width: 80px;
                height: 80px;
            }

            .logout-icon .icon-inner {
                width: 48px;
                height: 48px;
                font-size: 1.5rem;
            }

            .logout-icon .check-circle {
                width: 28px;
                height: 28px;
                font-size: 1rem;
            }

            .logout-title {
                font-size: 1.4rem;
            }

            .logout-message {
                font-size: 0.9rem;
            }

            .btn-primary-custom,
            .btn-secondary-custom {
                padding: 12px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 380px) {
            .logout-card {
                padding: 24px 16px 20px;
            }

            .logout-icon {
                width: 64px;
                height: 64px;
            }

            .logout-icon .icon-inner {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }

            .logout-icon .check-circle {
                width: 24px;
                height: 24px;
                font-size: 0.8rem;
            }

            .logout-title {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <!-- Icône -->
        <div class="logout-icon">
            <div class="icon-inner">
                <i class="bi bi-box-arrow-right"></i>
            </div>
            <div class="check-circle">
                <i class="bi bi-check-lg"></i>
            </div>
        </div>

        <!-- Titre -->
        <h1 class="logout-title">
            Déconnexion <span>réussie</span>
        </h1>

        <!-- Message -->
        <p class="logout-message">
            <?php echo $logout_message; ?>
        </p>

        <?php if ($user_name): ?>
            <p class="logout-user">
                <i class="bi bi-person-circle"></i>
                <?php echo htmlspecialchars($user_name); ?>
            </p>
        <?php endif; ?>

        <p class="logout-sub-message">
            Vous allez être redirigé automatiquement vers la page de connexion.
        </p>

        <!-- Diviseur -->
        <div class="divider">
            <span>Ou</span>
        </div>

        <!-- Boutons -->
        <a href="<?php echo $redirect_url; ?>" class="btn-primary-custom">
            <i class="bi bi-arrow-right-circle"></i>
            Retourner à la page de connexion
        </a>

        <a href="index.php" class="btn-secondary-custom">
            <i class="bi bi-house"></i>
            Accéder à l'accueil
        </a>

        <!-- Timer -->
        <div class="timer-container">
            <div class="timer-ring"></div>
            <span class="timer-text">
                Redirection dans <strong id="countdown">3</strong> secondes...
            </span>
        </div>
    </div>

    <script>
        // Compte à rebours pour la redirection
        let seconds = 3;
        const countdownElement = document.getElementById('countdown');

        const timer = setInterval(function() {
            seconds--;
            if (countdownElement) {
                countdownElement.textContent = seconds;
            }

            if (seconds <= 0) {
                clearInterval(timer);
                // Rediriger vers la page de connexion
                window.location.href = '<?php echo $redirect_url; ?>';
            }
        }, 1000);

        // Animation supplémentaire au chargement
        document.addEventListener('DOMContentLoaded', function() {
            // Effet de confirmation
            const checkCircle = document.querySelector('.check-circle');
            if (checkCircle) {
                checkCircle.style.animation = 'popIn 0.5s ease 0.5s both';
            }
        });

        // Empêcher le retour en arrière via le bouton de navigation
        window.addEventListener('load', function() {
            // Remplacer l'historique pour empêcher le retour
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
                window.addEventListener('popstate', function() {
                    window.history.replaceState(null, null, window.location.href);
                });
            }
        });
    </script>
</body>
</html>