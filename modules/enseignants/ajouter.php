<?php
// ========================================
// ENSEIGNANTS : ajout et modification (ajouter.php?id=X), avec les matières enseignées
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_GESTION);

$db = (new Database())->getConnection();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$enseignant = null;
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM enseignant WHERE id_enseignant = :id");
    $stmt->execute([':id' => $id]);
    $enseignant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enseignant) {
        setFlash('warning', 'Enseignant introuvable.');
        header('Location: index.php');
        exit;
    }
}
$edit_mode = $enseignant !== null;

$matieres = $db->query("SELECT id_matiere, nom_matiere, coef FROM matiere ORDER BY nom_matiere")->fetchAll(PDO::FETCH_ASSOC);
$ids_matieres = array_map('intval', array_column($matieres, 'id_matiere'));

// Matières actuellement affectées
$affectees = [];
if ($edit_mode) {
    $stmt = $db->prepare("SELECT id_matiere FROM enseigner WHERE id_enseignant = :id");
    $stmt->execute([':id' => $id]);
    $affectees = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$errors = [];
$error = csrfError();

if (isPostWithValidCsrf()) {
    $nom    = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $grade  = trim($_POST['grade'] ?? '');
    // Ne garder que des matières existantes
    $choisies = array_values(array_intersect(array_map('intval', (array) ($_POST['matieres'] ?? [])), $ids_matieres));

    if ($nom === '') {
        $errors['nom'] = 'Le nom est obligatoire.';
    }
    if ($prenom === '') {
        $errors['prenom'] = 'Le prénom est obligatoire.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            if ($edit_mode) {
                $stmt = $db->prepare("UPDATE enseignant SET nom = :nom, prenom = :prenom, grade = :grade WHERE id_enseignant = :id");
                $stmt->execute([':nom' => $nom, ':prenom' => $prenom, ':grade' => $grade ?: null, ':id' => $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO enseignant (nom, prenom, grade) VALUES (:nom, :prenom, :grade)");
                $stmt->execute([':nom' => $nom, ':prenom' => $prenom, ':grade' => $grade ?: null]);
                $id = (int) $db->lastInsertId();
            }

            // Affectations : remplacer la liste des matières enseignées
            $db->prepare("DELETE FROM enseigner WHERE id_enseignant = :id")->execute([':id' => $id]);
            $stmt = $db->prepare("INSERT INTO enseigner (id_enseignant, id_matiere) VALUES (:ens, :mat)");
            foreach ($choisies as $id_matiere) {
                $stmt->execute([':ens' => $id, ':mat' => $id_matiere]);
            }

            $db->commit();
            setFlash('success', ($edit_mode ? 'Enseignant modifié' : 'Enseignant ajouté') . ' · ' . count($choisies) . ' matière(s) affectée(s).');
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            error_log('Enregistrement enseignant: ' . $e->getMessage());
            $error = "Erreur lors de l'enregistrement.";
        }
    } else {
        $error = 'Veuillez corriger les erreurs ci-dessous.';
    }
}

// Valeurs affichées : saisie renvoyée, sinon fiche existante
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = ['nom' => $nom ?? '', 'prenom' => $prenom ?? '', 'grade' => $grade ?? ''];
    $affectees = $choisies ?? [];
} else {
    $form = $enseignant ?? ['nom' => '', 'prenom' => '', 'grade' => ''];
}

require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-person-plus'; ?>"></i></span>
                <?php echo $edit_mode ? 'Modifier un enseignant' : 'Ajouter un enseignant'; ?>
            </h1>
            <p class="page-subtitle text-muted">Identité et matières enseignées</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="post" action="" novalidate>
    <?php echo csrfField(); ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card voir-card">
                <div class="card-header"><i class="bi bi-person"></i> Identité</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom *</label>
                        <input type="text" class="form-control <?php echo isset($errors['nom']) ? 'is-invalid' : ''; ?>" id="nom" name="nom"
                               value="<?php echo htmlspecialchars($form['nom']); ?>" required>
                        <?php if (isset($errors['nom'])): ?><div class="invalid-feedback"><?php echo $errors['nom']; ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="prenom" class="form-label">Prénom *</label>
                        <input type="text" class="form-control <?php echo isset($errors['prenom']) ? 'is-invalid' : ''; ?>" id="prenom" name="prenom"
                               value="<?php echo htmlspecialchars($form['prenom']); ?>" required>
                        <?php if (isset($errors['prenom'])): ?><div class="invalid-feedback"><?php echo $errors['prenom']; ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="grade" class="form-label">Grade / fonction</label>
                        <input type="text" class="form-control" id="grade" name="grade" placeholder="Ex : Professeur principal"
                               value="<?php echo htmlspecialchars($form['grade'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card voir-card">
                <div class="card-header"><i class="bi bi-book"></i> Matières enseignées</div>
                <div class="card-body">
                    <?php if (empty($matieres)): ?>
                        <p class="text-muted mb-0">Aucune matière enregistrée.</p>
                    <?php endif; ?>
                    <?php foreach ($matieres as $m): $mid = (int) $m['id_matiere']; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="matieres[]" value="<?php echo $mid; ?>"
                                   id="mat<?php echo $mid; ?>" <?php echo in_array($mid, $affectees, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="mat<?php echo $mid; ?>">
                                <?php echo htmlspecialchars($m['nom_matiere']); ?> <span class="text-muted small">(coef <?php echo htmlspecialchars($m['coef']); ?>)</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="bi bi-info-circle"></i> Un enseignant ne peut saisir des notes que dans ses matières.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-dark"><i class="bi bi-save"></i> <?php echo $edit_mode ? 'Enregistrer' : 'Ajouter'; ?></button>
        <a href="index.php" class="btn btn-outline-secondary">Annuler</a>
    </div>
</form>

<?php require_once '../../includes/footer.php'; ?>
