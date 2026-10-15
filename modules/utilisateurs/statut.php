<?php
// Activer / désactiver un compte (administrateurs uniquement, POST + CSRF)
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_ADMIN);
requirePostWithCsrf('index.php');

$id = (int) ($_POST['id'] ?? 0);

// Un administrateur ne peut pas désactiver son propre compte (il se bloquerait)
if ($id === (int) $_SESSION['user_id']) {
    setFlash('warning', 'Vous ne pouvez pas désactiver votre propre compte.');
    header('Location: index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = :id");
$stmt->execute([':id' => $id]);

if ($stmt->rowCount()) {
    $status = $db->prepare("SELECT status FROM users WHERE id = :id");
    $status->execute([':id' => $id]);
    setFlash('success', $status->fetchColumn() === 'active' ? 'Compte réactivé.' : 'Compte désactivé : la connexion est bloquée.');
} else {
    setFlash('warning', 'Ce compte n\'existe pas.');
}

header('Location: index.php');
exit;
