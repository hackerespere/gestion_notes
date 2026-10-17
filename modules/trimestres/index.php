<?php
// ========================================
// TRIMESTRES : liste, ajout, modification (?id=X) et suppression (direction uniquement)
// Un trimestre qui a des notes ne peut pas être supprimé : les bulletins en dépendent.
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_GESTION);

$db = (new Database())->getConnection();

// Suppression (POST + CSRF)
if (isPostWithValidCsrf() && ($_POST['action'] ?? '') === 'supprimer') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT COUNT(*) FROM durant WHERE id_trimestre = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() > 0) {
        setFlash('danger', 'Ce trimestre a des notes : il ne peut pas être supprimé.');
    } else {
        $stmt = $db->prepare("DELETE FROM trimestre WHERE id_trimestre = :id");
        $stmt->execute([':id' => $id]);
        setFlash($stmt->rowCount() ? 'success' : 'warning', $stmt->rowCount() ? 'Trimestre supprimé.' : 'Trimestre introuvable.');
    }
    header('Location: index.php');
    exit;
}

// Trimestre en cours de modification
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$trimestre = null;
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM trimestre WHERE id_trimestre = :id");
    $stmt->execute([':id' => $id]);
    $trimestre = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trimestre) {
        setFlash('warning', 'Trimestre introuvable.');
        header('Location: index.php');
        exit;
    }
}
$edit_mode = $trimestre !== null;

$errors = [];
$error = csrfError();

if (isPostWithValidCsrf() && ($_POST['action'] ?? '') === 'enregistrer') {
    $libelle    = trim($_POST['libelle'] ?? '');
    $date_debut = $_POST['date_debut'] ?? '';
    $date_fin   = $_POST['date_fin'] ?? '';

    if ($libelle === '' || mb_strlen($libelle) > 20) {
        $errors['libelle'] = 'Le libellé est obligatoire (20 caractères au plus).';
    }
    foreach (['date_debut' => $date_debut, 'date_fin' => $date_fin] as $champ => $valeur) {
        $d = DateTime::createFromFormat('Y-m-d', $valeur);
        if (!$d || $d->format('Y-m-d') !== $valeur) {
            $errors[$champ] = 'Date invalide.';
        }
    }
    if (empty($errors) && $date_fin <= $date_debut) {
        $errors['date_fin'] = 'La date de fin doit être après la date de début.';
    }
    if (empty($errors)) {
        // Deux trimestres ne doivent pas se chevaucher
        $stmt = $db->prepare("SELECT libelle FROM trimestre
                              WHERE id_trimestre <> :id AND date_debut <= :fin AND date_fin >= :debut LIMIT 1");
        $stmt->execute([':id' => $id, ':debut' => $date_debut, ':fin' => $date_fin]);
        if ($chevauche = $stmt->fetchColumn()) {
            $errors['date_debut'] = "Ces dates chevauchent le trimestre « $chevauche ».";
        }
    }

    if (empty($errors)) {
        if ($edit_mode) {
            $db->prepare("UPDATE trimestre SET libelle = :l, date_debut = :d, date_fin = :f WHERE id_trimestre = :id")
               ->execute([':l' => $libelle, ':d' => $date_debut, ':f' => $date_fin, ':id' => $id]);
        } else {
            $db->prepare("INSERT INTO trimestre (libelle, date_debut, date_fin) VALUES (:l, :d, :f)")
               ->execute([':l' => $libelle, ':d' => $date_debut, ':f' => $date_fin]);
        }
        setFlash('success', $edit_mode ? 'Trimestre modifié.' : 'Trimestre ajouté.');
        header('Location: index.php');
        exit;
    }
    $error = 'Veuillez corriger les erreurs ci-dessous.';
}

$trimestres = $db->query("SELECT t.*, (SELECT COUNT(*) FROM durant d WHERE d.id_trimestre = t.id_trimestre) AS nb_notes
                          FROM trimestre t ORDER BY t.date_debut")->fetchAll(PDO::FETCH_ASSOC);

// Valeurs du formulaire : saisie renvoyée, sinon trimestre modifié, sinon vide
$form = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ['libelle' => $libelle ?? '', 'date_debut' => $date_debut ?? '', 'date_fin' => $date_fin ?? '']
    : ($trimestre ?? ['libelle' => '', 'date_debut' => '', 'date_fin' => '']);

require_once '../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">
        <span class="title-icon"><i class="bi bi-calendar-range"></i></span>
        Trimestres
    </h1>
    <p class="page-subtitle text-muted">Périodes de notation utilisées par les notes et les bulletins</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card voir-card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Trimestre</th><th>Début</th><th>Fin</th><th>Notes</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($trimestres)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Aucun trimestre enregistré.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($trimestres as $t): $tid = (int) $t['id_trimestre']; ?>
                        <tr class="<?php echo $tid === $id ? 'table-active' : ''; ?>">
                            <td class="fw-semibold"><?php echo htmlspecialchars($t['libelle']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($t['date_debut'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($t['date_fin'])); ?></td>
                            <td><?php echo (int) $t['nb_notes']; ?></td>
                            <td class="text-end">
                                <a href="?id=<?php echo $tid; ?>" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                                <?php if ((int) $t['nb_notes'] === 0): ?>
                                <form method="post" class="d-inline m-0" onsubmit="return confirm('Supprimer ce trimestre ?')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="id" value="<?php echo $tid; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card voir-card">
            <div class="card-header">
                <i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-plus-circle'; ?>"></i>
                <?php echo $edit_mode ? 'Modifier « ' . htmlspecialchars($trimestre['libelle']) . ' »' : 'Ajouter un trimestre'; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo $edit_mode ? '?id=' . $id : ''; ?>" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="enregistrer">
                    <?php foreach (['libelle' => ['Libellé *', 'text'], 'date_debut' => ['Date de début *', 'date'], 'date_fin' => ['Date de fin *', 'date']] as $champ => [$label, $type]): ?>
                    <div class="mb-3">
                        <label for="<?php echo $champ; ?>" class="form-label"><?php echo $label; ?></label>
                        <input type="<?php echo $type; ?>" class="form-control <?php echo isset($errors[$champ]) ? 'is-invalid' : ''; ?>"
                               id="<?php echo $champ; ?>" name="<?php echo $champ; ?>" <?php echo $champ === 'libelle' ? 'maxlength="20" placeholder="Ex : 1er Trimestre"' : ''; ?>
                               value="<?php echo htmlspecialchars((string) $form[$champ]); ?>" required>
                        <?php if (isset($errors[$champ])): ?><div class="invalid-feedback"><?php echo htmlspecialchars($errors[$champ]); ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-dark"><i class="bi bi-save"></i> <?php echo $edit_mode ? 'Enregistrer' : 'Ajouter'; ?></button>
                        <?php if ($edit_mode): ?><a href="index.php" class="btn btn-outline-secondary">Annuler</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
