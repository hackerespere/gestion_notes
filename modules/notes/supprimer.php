<?php
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_PEDAGOGIE);
requirePostWithCsrf('index.php');

$id_note = (int) ($_POST['id'] ?? 0);

$database = new Database();
$db = $database->getConnection();

// Un enseignant ne supprime que les notes qu'il a lui-même saisies
$stmt = $db->prepare("SELECT id_enseignant FROM saisir WHERE id_note = :id");
$stmt->execute([':id' => $id_note]);
$auteur = $stmt->fetchColumn();
if ($auteur !== false && !peutGererNote($auteur)) {
    setFlash('danger', 'Vous ne pouvez supprimer que les notes que vous avez saisies.');
    header('Location: index.php');
    exit;
}

try {
    $db->beginTransaction();

    // Supprimer d'abord les liaisons, puis la note
    foreach (['obtenir', 'porter_sur', 'durant', 'saisir'] as $table) {
        $stmt = $db->prepare("DELETE FROM $table WHERE id_note = :id");
        $stmt->execute([':id' => $id_note]);
    }

    $stmt = $db->prepare("DELETE FROM note WHERE id_note = :id");
    $stmt->execute([':id' => $id_note]);
    $deleted = $stmt->rowCount();

    $db->commit();

    if ($deleted) {
        setFlash('success', 'Note supprimée avec succès.');
    } else {
        setFlash('warning', 'Cette note n\'existe pas ou a déjà été supprimée.');
    }
} catch (PDOException $e) {
    $db->rollBack();
    error_log('Suppression note: ' . $e->getMessage());
    setFlash('danger', 'Erreur lors de la suppression de la note.');
}

header('Location: index.php');
exit;
