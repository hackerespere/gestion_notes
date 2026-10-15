<?php
// ========================================
// ESPACE PARENT : notes, moyennes et bulletins des enfants du parent connecté (lecture seule)
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/eleve_notes.php';

requireParent();

$db = (new Database())->getConnection();

// Enfants rattachés à CE compte : seuls ceux-là peuvent être consultés
$enfants = parentEleves($db);

// Enfant affiché : celui demandé s'il fait partie des enfants du parent, sinon le premier
$demande = isset($_GET['eleve']) ? (int) $_GET['eleve'] : 0;
if ($demande && !isset($enfants[$demande])) {
    setFlash('danger', "Cet élève ne fait pas partie de vos enfants.");
    header('Location: index.php');
    exit;
}
$id_eleve = $demande ?: (int) array_key_first($enfants);

$eleve = $id_eleve ? getEleve($db, $id_eleve) : null;
$trimestres = $eleve ? getNotesParTrimestre($db, $id_eleve) : [];

require_once '../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">
        <span class="title-icon"><i class="bi bi-people"></i></span>
        Mes enfants
    </h1>
    <p class="page-subtitle text-muted">Notes, moyennes et bulletins</p>
</div>

<?php if (empty($enfants)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        Aucun enfant n'est rattaché à votre compte. Contactez l'administration de l'établissement.
    </div>
<?php else: ?>

    <?php if (count($enfants) > 1): ?>
    <!-- Choix de l'enfant -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php foreach ($enfants as $id => $enfant): ?>
            <a href="?eleve=<?php echo (int) $id; ?>"
               class="btn <?php echo $id === $id_eleve ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                <i class="bi bi-person"></i>
                <?php echo htmlspecialchars($enfant['prenom'] . ' ' . $enfant['nom']); ?>
                <?php if ($enfant['nom_classe']): ?>
                    <span class="small opacity-75">· <?php echo htmlspecialchars($enfant['nom_classe']); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="h5 mb-3">
        <?php echo htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']); ?>
        <?php if ($eleve['nom_classe']): ?>
            <span class="text-muted fw-normal">· <?php echo htmlspecialchars($eleve['nom_classe']); ?></span>
        <?php endif; ?>
    </h2>

    <div class="row g-4">
        <div class="col-lg-4">
            <?php renderMoyennesCard($trimestres, $id_eleve, '../bulletins/imprimer.php'); ?>
        </div>
        <div class="col-lg-8">
            <?php renderNotesTrimestres($trimestres, "Pas encore de notes pour cet élève."); ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
