<?php
// ========================================
// NOTES : modification d'une note (valeur, type, date, trimestre)
// Mêmes droits que la suppression : la direction modifie toutes les notes,
// un enseignant uniquement celles qu'il a saisies. L'élève et la matière ne changent pas :
// pour cela, supprimer la note et la ressaisir.
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_PEDAGOGIE);

$db = (new Database())->getConnection();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $db->prepare("SELECT n.*, d.id_trimestre, s.id_enseignant, m.nom_matiere,
                             e.nom, e.prenom, c.nom_classe, ens.nom AS ens_nom, ens.prenom AS ens_prenom
                      FROM note n
                      JOIN obtenir o     ON o.id_note = n.id_note
                      JOIN eleve e       ON e.id_eleve = o.id_eleve
                      LEFT JOIN classe c ON c.id_classe = e.id_classe
                      JOIN porter_sur ps ON ps.id_note = n.id_note
                      JOIN matiere m     ON m.id_matiere = ps.id_matiere
                      LEFT JOIN durant d ON d.id_note = n.id_note
                      LEFT JOIN saisir s ON s.id_note = n.id_note
                      LEFT JOIN enseignant ens ON ens.id_enseignant = s.id_enseignant
                      WHERE n.id_note = :id");
$stmt->execute([':id' => $id]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$note) {
    setFlash('warning', 'Note introuvable.');
    header('Location: index.php');
    exit;
}
if (!peutGererNote($note['id_enseignant'])) {
    setFlash('danger', 'Vous ne pouvez modifier que les notes que vous avez saisies.');
    header('Location: index.php');
    exit;
}

$trimestres = $db->query("SELECT id_trimestre, libelle FROM trimestre ORDER BY id_trimestre")->fetchAll(PDO::FETCH_ASSOC);
$types = ['Contrôle', 'Devoir', 'Composition', 'TP', 'Interrogation'];

$errors = [];
$error = csrfError();

if (isPostWithValidCsrf()) {
    $valeur       = trim($_POST['valeur'] ?? '');
    $type_note    = $_POST['type_note'] ?? '';
    $date_note    = $_POST['date_note'] ?? '';
    $id_trimestre = (int) ($_POST['id_trimestre'] ?? 0);

    // La note 0 est valide : tester la chaîne vide, pas empty()
    if ($valeur === '' || !is_numeric($valeur) || $valeur < 0 || $valeur > 20) {
        $errors['valeur'] = 'La note doit être un nombre entre 0 et 20.';
    }
    if (!in_array($type_note, $types, true)) {
        $errors['type_note'] = 'Type de note invalide.';
    }
    $date = DateTime::createFromFormat('Y-m-d', $date_note);
    if (!$date || $date->format('Y-m-d') !== $date_note) {
        $errors['date_note'] = 'Date invalide.';
    }
    if (!in_array($id_trimestre, array_map('intval', array_column($trimestres, 'id_trimestre')), true)) {
        $errors['id_trimestre'] = 'Trimestre invalide.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE note SET valeur = :valeur, type_note = :type, date_note = :date WHERE id_note = :id")
               ->execute([':valeur' => $valeur, ':type' => $type_note, ':date' => $date_note, ':id' => $id]);
            $db->prepare("DELETE FROM durant WHERE id_note = :id")->execute([':id' => $id]);
            $db->prepare("INSERT INTO durant (id_trimestre, id_note) VALUES (:tri, :id)")->execute([':tri' => $id_trimestre, ':id' => $id]);
            $db->commit();
            setFlash('success', 'Note modifiée avec succès.');
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            error_log('Modification note: ' . $e->getMessage());
            $error = "Erreur lors de l'enregistrement.";
        }
    } else {
        $error = 'Veuillez corriger les erreurs ci-dessous.';
    }
}

// Valeurs affichées : saisie renvoyée, sinon note existante
$form = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ['valeur' => $valeur ?? '', 'type_note' => $type_note ?? '', 'date_note' => $date_note ?? '', 'id_trimestre' => $id_trimestre ?? 0]
    : $note;

require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-pencil-square"></i></span>
                Modifier une note
            </h1>
            <p class="page-subtitle text-muted">
                <?php echo htmlspecialchars($note['prenom'] . ' ' . $note['nom']); ?>
                <?php if ($note['nom_classe']): ?>· <?php echo htmlspecialchars($note['nom_classe']); ?><?php endif; ?>
                · <?php echo htmlspecialchars($note['nom_matiere']); ?>
                <?php if ($note['ens_nom']): ?>· saisie par <?php echo htmlspecialchars($note['ens_prenom'] . ' ' . $note['ens_nom']); ?><?php endif; ?>
            </p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="post" action="" novalidate>
    <?php echo csrfField(); ?>
    <div class="card voir-card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="valeur" class="form-label">Note /20 *</label>
                    <input type="number" step="0.25" min="0" max="20" class="form-control <?php echo isset($errors['valeur']) ? 'is-invalid' : ''; ?>"
                           id="valeur" name="valeur" value="<?php echo htmlspecialchars((string) $form['valeur']); ?>" required>
                    <?php if (isset($errors['valeur'])): ?><div class="invalid-feedback"><?php echo $errors['valeur']; ?></div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label for="type_note" class="form-label">Type</label>
                    <select class="form-select <?php echo isset($errors['type_note']) ? 'is-invalid' : ''; ?>" id="type_note" name="type_note">
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $form['type_note'] === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date_note" class="form-label">Date *</label>
                    <input type="date" class="form-control <?php echo isset($errors['date_note']) ? 'is-invalid' : ''; ?>"
                           id="date_note" name="date_note" value="<?php echo htmlspecialchars((string) $form['date_note']); ?>" required>
                    <?php if (isset($errors['date_note'])): ?><div class="invalid-feedback"><?php echo $errors['date_note']; ?></div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label for="id_trimestre" class="form-label">Trimestre *</label>
                    <select class="form-select <?php echo isset($errors['id_trimestre']) ? 'is-invalid' : ''; ?>" id="id_trimestre" name="id_trimestre">
                        <?php foreach ($trimestres as $t): ?>
                            <option value="<?php echo (int) $t['id_trimestre']; ?>" <?php echo (int) $form['id_trimestre'] === (int) $t['id_trimestre'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['libelle']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">
                <i class="bi bi-info-circle"></i> Pour changer l'élève ou la matière, supprimez la note puis ressaisissez-la.
            </p>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-dark"><i class="bi bi-save"></i> Enregistrer</button>
        <a href="index.php" class="btn btn-outline-secondary">Annuler</a>
    </div>
</form>

<?php require_once '../../includes/footer.php'; ?>
