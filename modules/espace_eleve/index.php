<?php
// ========================================
// ESPACE ÉLÈVE : notes, moyennes et bulletins de l'élève connecté (lecture seule)
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/eleve_notes.php';

// Identifiant pris dans la session : un élève ne voit que ses propres notes
$id_eleve = requireEleve();

$database = new Database();
$db = $database->getConnection();

$eleve = getEleve($db, $id_eleve);

// Fiche supprimée pendant que l'élève était connecté : fermer la session
if (!$eleve) {
    logout();
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$trimestres = getNotesParTrimestre($db, $id_eleve);

require_once '../../includes/header.php';
?>

<!-- En-tête de la page -->
<div class="page-header">
    <h1 class="page-title">
        <span class="title-icon"><i class="bi bi-mortarboard"></i></span>
        Mes notes
    </h1>
    <p class="page-subtitle text-muted">
        <?php echo htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']); ?>
        <?php if ($eleve['nom_classe']): ?>
            · <?php echo htmlspecialchars($eleve['nom_classe']); ?>
        <?php endif; ?>
    </p>
</div>

<div class="row g-4">
    <!-- Moyennes et bulletins -->
    <div class="col-lg-4">
        <?php renderMoyennesCard($trimestres, $id_eleve, '../bulletins/imprimer.php'); ?>
        <p class="text-muted small mt-2">
            <i class="bi bi-printer"></i> Le bouton d'impression ouvre votre bulletin du trimestre.
        </p>
    </div>

    <!-- Notes par trimestre -->
    <div class="col-lg-8">
        <?php renderNotesTrimestres($trimestres, "Vous n'avez pas encore de notes."); ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
