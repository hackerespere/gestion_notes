<?php
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_GESTION);
requirePostWithCsrf('index.php');

$id_classe = (int) ($_POST['id'] ?? 0);

$database = new Database();
$db = $database->getConnection();

try {
    // Refuser si la classe contient encore des élèves (ils perdraient leur classe)
    $stmt = $db->prepare("SELECT COUNT(*) FROM eleve WHERE id_classe = :id");
    $stmt->execute([':id' => $id_classe]);
    $nb_eleves = (int) $stmt->fetchColumn();

    if ($nb_eleves > 0) {
        setFlash('warning', "Impossible de supprimer cette classe : elle contient encore $nb_eleves élève(s). Changez-les de classe ou supprimez-les d'abord.");
    } else {
        $stmt = $db->prepare("DELETE FROM classe WHERE id_classe = :id");
        $stmt->execute([':id' => $id_classe]);

        if ($stmt->rowCount()) {
            setFlash('success', 'Classe supprimée avec succès.');
        } else {
            setFlash('warning', 'Cette classe n\'existe pas ou a déjà été supprimée.');
        }
    }
} catch (PDOException $e) {
    error_log('Suppression classe: ' . $e->getMessage());
    setFlash('danger', 'Erreur lors de la suppression de la classe.');
}

header('Location: index.php');
exit;
