<?php
header('Content-Type: application/json');
require_once '../config/session.php';
require_once '../config/database.php';

// API JSON : pas de redirection vers login.php, on renvoie un 401
if (!isLoggedIn() || isSessionExpired()) {
    http_response_code(401);
    echo json_encode(['erreur' => 'Non connecté']);
    exit;
}
if (!hasRole(ROLES_PEDAGOGIE)) {
    http_response_code(403);
    echo json_encode(['erreur' => 'Accès refusé']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$classe_id = isset($_GET['classe_id']) ? $_GET['classe_id'] : null;

if(!$classe_id) {
    echo json_encode([]);
    exit;
}

$query = "SELECT id_eleve, nom, prenom FROM eleve WHERE id_classe = :id_classe ORDER BY nom";
$stmt = $db->prepare($query);
$stmt->bindParam(':id_classe', $classe_id);
$stmt->execute();
$eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($eleves);
?>
