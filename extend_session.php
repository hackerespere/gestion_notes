<?php
header('Content-Type: application/json');
require_once 'config/session.php';

// Prolonger la session uniquement si elle est encore valide
// (une session déjà expirée ne doit pas pouvoir être relancée)
if (isLoggedIn() && !isSessionExpired()) {
    $_SESSION['last_activity'] = time();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>
