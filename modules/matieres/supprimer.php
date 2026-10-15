<?php
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_GESTION);
requirePostWithCsrf('index.php');

$id_matiere = (int) ($_POST['id'] ?? 0);

$database = new Database();
$db = $database->getConnection();

try {
    // Refuser si des notes existent dans cette matière (les bulletins en dépendent)
    $stmt = $db->prepare("SELECT COUNT(*) FROM porter_sur WHERE id_matiere = :id");
    $stmt->execute([':id' => $id_matiere]);
    $nb_notes = (int) $stmt->fetchColumn();

    if ($nb_notes > 0) {
        setFlash('warning', "Impossible de supprimer cette matière : $nb_notes note(s) y sont rattachées.");
    } else {
        $db->beginTransaction();

        // Retirer les enseignants affectés à la matière, puis la matière
        $stmt = $db->prepare("DELETE FROM enseigner WHERE id_matiere = :id");
        $stmt->execute([':id' => $id_matiere]);

        $stmt = $db->prepare("DELETE FROM matiere WHERE id_matiere = :id");
        $stmt->execute([':id' => $id_matiere]);
        $deleted = $stmt->rowCount();

        $db->commit();

        if ($deleted) {
            setFlash('success', 'Matière supprimée avec succès.');
        } else {
            setFlash('warning', 'Cette matière n\'existe pas ou a déjà été supprimée.');
        }
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Suppression matière: ' . $e->getMessage());
    setFlash('danger', 'Erreur lors de la suppression de la matière.');
}

header('Location: index.php');
exit;
