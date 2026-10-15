<?php
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_GESTION);
requirePostWithCsrf('index.php');

$id_eleve = (int) ($_POST['id'] ?? 0);

$database = new Database();
$db = $database->getConnection();

try {
    $db->beginTransaction();

    // Notes de l'élève : elles n'ont plus de sens sans lui, on les supprime avec leurs liaisons
    $stmt = $db->prepare("SELECT id_note FROM obtenir WHERE id_eleve = :id");
    $stmt->execute([':id' => $id_eleve]);
    $notes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($notes as $id_note) {
        foreach (['obtenir', 'porter_sur', 'durant', 'saisir', 'note'] as $table) {
            $stmt = $db->prepare("DELETE FROM $table WHERE id_note = :id");
            $stmt->execute([':id' => $id_note]);
        }
    }

    $stmt = $db->prepare("DELETE FROM eleve WHERE id_eleve = :id");
    $stmt->execute([':id' => $id_eleve]);
    $deleted = $stmt->rowCount();

    $db->commit();

    if ($deleted) {
        $msg = 'Élève supprimé avec succès.';
        if (count($notes) > 0) {
            $msg .= ' ' . count($notes) . ' note(s) supprimée(s) également.';
        }
        setFlash('success', $msg);
    } else {
        setFlash('warning', 'Cet élève n\'existe pas ou a déjà été supprimé.');
    }
} catch (PDOException $e) {
    $db->rollBack();
    error_log('Suppression élève: ' . $e->getMessage());
    setFlash('danger', 'Erreur lors de la suppression de l\'élève.');
}

header('Location: index.php');
exit;
