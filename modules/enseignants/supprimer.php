<?php
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_GESTION);
requirePostWithCsrf('index.php');

$id_enseignant = (int) ($_POST['id'] ?? 0);

$db = (new Database())->getConnection();

try {
    // Refuser si l'enseignant a saisi des notes (elles perdraient leur auteur)
    $stmt = $db->prepare("SELECT COUNT(*) FROM saisir WHERE id_enseignant = :id");
    $stmt->execute([':id' => $id_enseignant]);
    $nb_notes = (int) $stmt->fetchColumn();

    if ($nb_notes > 0) {
        setFlash('warning', "Impossible de supprimer cet enseignant : il a saisi $nb_notes note(s). Désactivez plutôt son compte.");
    } else {
        $db->beginTransaction();

        // Retirer ses affectations, puis la fiche (son compte est supprimé par la clé étrangère)
        $db->prepare("DELETE FROM enseigner WHERE id_enseignant = :id")->execute([':id' => $id_enseignant]);
        $stmt = $db->prepare("DELETE FROM enseignant WHERE id_enseignant = :id");
        $stmt->execute([':id' => $id_enseignant]);
        $deleted = $stmt->rowCount();

        $db->commit();

        setFlash($deleted ? 'success' : 'warning', $deleted ? 'Enseignant supprimé.' : 'Cet enseignant n\'existe pas.');
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Suppression enseignant: ' . $e->getMessage());
    setFlash('danger', 'Erreur lors de la suppression de l\'enseignant.');
}

header('Location: index.php');
exit;
