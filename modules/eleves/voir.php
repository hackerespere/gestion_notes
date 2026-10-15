<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/eleve_notes.php';

requireRole(ROLES_PEDAGOGIE);

$database = new Database();
$db = $database->getConnection();

$id_eleve = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Récupérer l'élève (avant toute sortie HTML, pour pouvoir rediriger)
$eleve = getEleve($db, $id_eleve);

if (!$eleve) {
    setFlash('warning', 'Élève introuvable.');
    header('Location: index.php');
    exit;
}

$trimestres = getNotesParTrimestre($db, $id_eleve);
$age = date_diff(date_create($eleve['date_naiss']), date_create('today'))->y;

// Compte de connexion de l'élève (affiché aux administrateurs)
$stmt = $db->prepare("SELECT id, email, status FROM users WHERE id_eleve = :id");
$stmt->execute([':id' => $id_eleve]);
$compte = $stmt->fetch(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-person-vcard"></i></span>
                <?php echo htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']); ?>
            </h1>
            <p class="page-subtitle text-muted">Fiche de l'élève #<?php echo (int) $eleve['id_eleve']; ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="../notes/saisir.php?eleve_id=<?php echo (int) $eleve['id_eleve']; ?>" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Saisir une note
            </a>
            <?php if (hasRole(ROLES_GESTION)): ?>
            <a href="modifier.php?id=<?php echo (int) $eleve['id_eleve']; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Informations -->
    <div class="col-lg-4">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-person-circle"></i> Informations</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Date de naissance</span>
                    <span><?php echo date('d/m/Y', strtotime($eleve['date_naiss'])); ?> (<?php echo $age; ?> ans)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Sexe</span>
                    <span><?php echo $eleve['sexe'] === 'F' ? 'Féminin' : 'Masculin'; ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Classe</span>
                    <span>
                        <?php if ($eleve['id_classe'] && $eleve['nom_classe']): ?>
                            <a href="../classes/voir.php?id=<?php echo (int) $eleve['id_classe']; ?>">
                                <?php echo htmlspecialchars($eleve['nom_classe']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Non affecté</span>
                        <?php endif; ?>
                    </span>
                </li>
                <li class="list-group-item">
                    <span class="text-muted d-block">Adresse</span>
                    <span><?php echo $eleve['adresse'] ? nl2br(htmlspecialchars($eleve['adresse'])) : '<span class="text-muted">Non renseignée</span>'; ?></span>
                </li>
                <?php if (hasRole(ROLES_ADMIN)): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted">Compte de connexion</span>
                    <?php if ($compte): ?>
                        <a href="../utilisateurs/ajouter.php?id=<?php echo (int) $compte['id']; ?>">
                            <?php echo htmlspecialchars($compte['email']); ?>
                            <?php if ($compte['status'] !== 'active'): ?><span class="badge bg-secondary">désactivé</span><?php endif; ?>
                        </a>
                    <?php else: ?>
                        <a href="../utilisateurs/ajouter.php?eleve=<?php echo (int) $eleve['id_eleve']; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-person-plus"></i> Créer un compte
                        </a>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="mt-3">
            <?php renderMoyennesCard($trimestres, $eleve['id_eleve'], '../bulletins/imprimer.php'); ?>
        </div>
    </div>

    <!-- Notes par trimestre -->
    <div class="col-lg-8">
        <?php renderNotesTrimestres($trimestres, 'Aucune note pour cet élève.'); ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
