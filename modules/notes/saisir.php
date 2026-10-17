<?php
require_once '../../config/session.php';
requireRole(ROLES_PEDAGOGIE);
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les données pour les formulaires
$eleves = $db->query("SELECT id_eleve, nom, prenom FROM eleve ORDER BY nom")->fetchAll();
$matieres = $db->query("SELECT id_matiere, nom_matiere FROM matiere ORDER BY nom_matiere")->fetchAll();
$enseignants = $db->query("SELECT id_enseignant, nom, prenom FROM enseignant ORDER BY nom")->fetchAll();
$trimestres = $db->query("SELECT id_trimestre, libelle, date_debut, date_fin FROM trimestre ORDER BY id_trimestre")->fetchAll();

$message = '';
$error = '';
$success = false;

// Un enseignant saisit en son nom (fiche liée à son compte) et uniquement dans ses matières
$est_enseignant = hasRole(ROLE_ENSEIGNANT);
$saisie_bloquee = false;
if ($est_enseignant) {
    $mon_enseignant = $_SESSION['id_enseignant'] ?? null;
    $enseignants = array_values(array_filter($enseignants, fn($e) => (int) $e['id_enseignant'] === (int) $mon_enseignant));

    $stmt = $db->prepare("SELECT m.id_matiere, m.nom_matiere
                          FROM enseigner en JOIN matiere m ON m.id_matiere = en.id_matiere
                          WHERE en.id_enseignant = :ens ORDER BY m.nom_matiere");
    $stmt->execute([':ens' => (int) $mon_enseignant]);
    $matieres = $stmt->fetchAll();

    if (!$mon_enseignant || empty($enseignants)) {
        $saisie_bloquee = true;
        $error = "Votre compte n'est relié à aucune fiche enseignant. Demandez à un administrateur de le relier.";
    } elseif (empty($matieres)) {
        $saisie_bloquee = true;
        $error = "Aucune matière ne vous est affectée. Demandez à la direction de vous affecter vos matières.";
    }
}

// Vérifier si un élève est pré-sélectionné
$selected_eleve = isset($_GET['eleve_id']) ? $_GET['eleve_id'] : '';

// Vérifier si une matière est pré-sélectionnée
$selected_matiere = isset($_GET['matiere_id']) ? $_GET['matiere_id'] : '';

// Pré-remplissage depuis une proposition de l'assistant IA : rien n'est enregistré
// tant que l'enseignant n'a pas vérifié puis validé le formulaire lui-même
$selected_trimestre = isset($_GET['trimestre_id']) ? (int) $_GET['trimestre_id'] : 0;
$selected_valeur = isset($_GET['valeur']) && is_numeric($_GET['valeur']) ? (float) $_GET['valeur'] : '';
$selected_type = $_GET['type_note'] ?? '';

// Formulaire refusé si le jeton CSRF est absent ou invalide
if(csrfError()) {
    $error = csrfError();
}

if(isPostWithValidCsrf() && !$saisie_bloquee) {
    $valeur = $_POST['valeur'] ?? '';
    $type_note = $_POST['type_note'];
    $date_note = $_POST['date_note'];
    $id_eleve = $_POST['id_eleve'];
    $id_matiere = $_POST['id_matiere'];
    // Enseignant : l'auteur de la note vient de la session, jamais du formulaire
    $id_enseignant = $est_enseignant ? $mon_enseignant : $_POST['id_enseignant'];
    $id_trimestre = $_POST['id_trimestre'];
    $commentaire = isset($_POST['commentaire']) ? $_POST['commentaire'] : '';

    // La note 0 est valide : tester la chaîne vide, pas empty()
    if($valeur === '' || !is_numeric($valeur) || empty($id_eleve) || empty($id_matiere) || empty($id_enseignant) || empty($id_trimestre)) {
        $error = "Veuillez remplir tous les champs obligatoires.";
    } elseif($est_enseignant && !in_array((int) $id_matiere, array_map('intval', array_column($matieres, 'id_matiere')), true)) {
        $error = "Vous ne pouvez saisir des notes que dans les matières qui vous sont affectées.";
    } elseif($valeur < 0 || $valeur > 20) {
        $error = "La note doit être comprise entre 0 et 20.";
    } else {
        try {
            $db->beginTransaction();

            // Insérer la note
            $query = "INSERT INTO note (valeur, type_note, date_note) VALUES (:valeur, :type_note, :date_note)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':valeur', $valeur);
            $stmt->bindParam(':type_note', $type_note);
            $stmt->bindParam(':date_note', $date_note);
            $stmt->execute();
            $id_note = $db->lastInsertId();

            // Créer la relation OBTENIR (élève-note)
            $query = "INSERT INTO obtenir (id_eleve, id_note) VALUES (:id_eleve, :id_note)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id_eleve', $id_eleve);
            $stmt->bindParam(':id_note', $id_note);
            $stmt->execute();

            // Créer la relation PORTER_SUR (note-matiere)
            $query = "INSERT INTO porter_sur (id_note, id_matiere) VALUES (:id_note, :id_matiere)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id_note', $id_note);
            $stmt->bindParam(':id_matiere', $id_matiere);
            $stmt->execute();

            // Créer la relation SAISIR (enseignant-note)
            $query = "INSERT INTO saisir (id_note, id_enseignant) VALUES (:id_note, :id_enseignant)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id_note', $id_note);
            $stmt->bindParam(':id_enseignant', $id_enseignant);
            $stmt->execute();

            // Créer la relation DURANT (trimestre-note)
            $query = "INSERT INTO durant (id_trimestre, id_note) VALUES (:id_trimestre, :id_note)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id_trimestre', $id_trimestre);
            $stmt->bindParam(':id_note', $id_note);
            $stmt->execute();

            $db->commit();
            $success = true;
            $message = "Note saisie avec succès !";
            
            // Réinitialiser le formulaire après succès
            echo '<script>
                setTimeout(function() {
                    window.location.href = "saisir.php?success=1";
                }, 1500);
            </script>';
        } catch(Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la saisie : " . $e->getMessage();
        }
    }
}

// Vérifier si succès dans l'URL
if(isset($_GET['success']) && $_GET['success'] == 1) {
    $success = true;
    $message = "Note saisie avec succès !";
}

// Récupérer les infos de l'élève si sélectionné
$eleve_info = null;
if($selected_eleve) {
    $query = "SELECT e.*, c.nom_classe FROM eleve e 
              LEFT JOIN classe c ON e.id_classe = c.id_classe 
              WHERE e.id_eleve = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $selected_eleve);
    $stmt->execute();
    $eleve_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Récupérer les notes déjà saisies pour cet élève (si sélectionné)
$notes_existantes = [];
if($selected_eleve) {
    $query = "SELECT n.valeur, m.nom_matiere, n.type_note 
              FROM note n
              JOIN obtenir o ON n.id_note = o.id_note
              JOIN porter_sur ps ON n.id_note = ps.id_note
              JOIN matiere m ON ps.id_matiere = m.id_matiere
              WHERE o.id_eleve = :id
              ORDER BY n.date_note DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $selected_eleve);
    $stmt->execute();
    $notes_existantes = $stmt->fetchAll();
}
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-pencil-square"></i>
                </span>
                Saisir une note
            </h1>
            <p class="page-subtitle text-muted">
                Enregistrez une nouvelle note pour un élève
            </p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
</div>

<!-- Messages -->
<?php if($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle-fill"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Formulaire principal -->
    <div class="col-lg-8">
        <div class="form-card">
            <div class="form-card-header">
                <div class="d-flex align-items-center">
                    <span class="form-card-icon">
                        <i class="bi bi-file-earmark-plus"></i>
                    </span>
                    <span class="form-card-title">Nouvelle note</span>
                </div>
                <span class="form-card-badge">Remplissez tous les champs</span>
            </div>
            <div class="form-card-body">
                <form method="POST" action="" id="noteForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <!-- Ligne 1 : Élève et Matière -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="id_eleve" class="form-label required">Élève</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <select class="form-select <?php echo $selected_eleve ? 'is-valid' : ''; ?>" 
                                            id="id_eleve" name="id_eleve" required>
                                        <option value="">Sélectionner un élève</option>
                                        <?php foreach($eleves as $eleve): ?>
                                            <option value="<?php echo $eleve['id_eleve']; ?>" 
                                                    <?php echo ($selected_eleve == $eleve['id_eleve']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($eleve['nom'] . ' ' . $eleve['prenom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Veuillez sélectionner un élève</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="id_matiere" class="form-label required">Matière</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-book"></i></span>
                                    <select class="form-select <?php echo $selected_matiere ? 'is-valid' : ''; ?>" 
                                            id="id_matiere" name="id_matiere" required>
                                        <option value="">Sélectionner une matière</option>
                                        <?php foreach($matieres as $matiere): ?>
                                            <option value="<?php echo $matiere['id_matiere']; ?>"
                                                    <?php echo ($selected_matiere == $matiere['id_matiere']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($matiere['nom_matiere']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Veuillez sélectionner une matière</div>
                            </div>
                        </div>
                    </div>

                    <!-- Ligne 2 : Enseignant et Trimestre -->
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="id_enseignant" class="form-label required">Enseignant</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                    <?php if($est_enseignant): ?>
                                        <!-- Enseignant connecté : auteur fixé par la session -->
                                        <input type="text" class="form-control" readonly
                                               value="<?php echo $enseignants ? htmlspecialchars($enseignants[0]['nom'] . ' ' . $enseignants[0]['prenom']) : '—'; ?>">
                                    <?php else: ?>
                                    <select class="form-select" id="id_enseignant" name="id_enseignant" required>
                                        <option value="">Sélectionner un enseignant</option>
                                        <?php foreach($enseignants as $enseignant): ?>
                                            <option value="<?php echo $enseignant['id_enseignant']; ?>">
                                                <?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                </div>
                                <div class="invalid-feedback">Veuillez sélectionner un enseignant</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="id_trimestre" class="form-label required">Trimestre</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar-range"></i></span>
                                    <select class="form-select" id="id_trimestre" name="id_trimestre" required>
                                        <option value="">Sélectionner un trimestre</option>
                                        <?php foreach($trimestres as $trimestre): ?>
                                            <option value="<?php echo $trimestre['id_trimestre']; ?>" <?php echo (int) $trimestre['id_trimestre'] === $selected_trimestre ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($trimestre['libelle']); ?>
                                                (<?php echo date('d/m', strtotime($trimestre['date_debut'])); ?> - 
                                                <?php echo date('d/m', strtotime($trimestre['date_fin'])); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="invalid-feedback">Veuillez sélectionner un trimestre</div>
                            </div>
                        </div>
                    </div>

                    <!-- Ligne 3 : Note et Date -->
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="valeur" class="form-label required">Note</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-star"></i></span>
                                    <input type="number" class="form-control" 
                                           id="valeur" name="valeur" 
                                           step="0.25" min="0" max="20"
                                           value="<?php echo htmlspecialchars((string) $selected_valeur); ?>"
                                           placeholder="0-20" required>
                                    <span class="input-group-text">/20</span>
                                </div>
                                <div class="invalid-feedback">Veuillez saisir une note entre 0 et 20</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="type_note" class="form-label">Type</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                    <select class="form-select" id="type_note" name="type_note">
                                        <?php foreach (['Contrôle', 'Devoir', 'Composition', 'TP', 'Interrogation'] as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo $selected_type === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="date_note" class="form-label">Date</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                                    <input type="date" class="form-control" 
                                           id="date_note" name="date_note" 
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ligne 4 : Commentaire -->
                    <div class="row g-3 mt-2">
                        <div class="col-12">
                            <div class="form-group">
                                <label for="commentaire" class="form-label">Commentaire (optionnel)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-chat"></i></span>
                                    <textarea class="form-control" id="commentaire" name="commentaire" 
                                              rows="2" placeholder="Ajouter un commentaire sur la note..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="form-actions">
                                <button type="submit" class="btn btn-dark" <?php echo $saisie_bloquee ? 'disabled' : ''; ?>>
                                    <i class="bi bi-save"></i> Enregistrer la note
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Annuler
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sidebar - Informations -->
    <div class="col-lg-4">
        <!-- Infos élève -->
        <?php if($eleve_info): ?>
            <div class="info-card mb-3">
                <div class="info-card-header">
                    <i class="bi bi-person-circle"></i>
                    <span>Informations élève</span>
                </div>
                <div class="info-card-body">
                    <div class="info-item">
                        <span class="info-label">Nom complet</span>
                        <span class="info-value"><?php echo htmlspecialchars($eleve_info['prenom'] . ' ' . $eleve_info['nom']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Classe</span>
                        <span class="info-value"><?php echo htmlspecialchars($eleve_info['nom_classe'] ?? 'Non affecté'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date de naissance</span>
                        <span class="info-value"><?php echo date('d/m/Y', strtotime($eleve_info['date_naiss'])); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Notes existantes -->
        <?php if(count($notes_existantes) > 0): ?>
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-clock-history"></i>
                    <span>Dernières notes</span>
                    <span class="badge bg-secondary ms-auto"><?php echo count($notes_existantes); ?></span>
                </div>
                <div class="info-card-body">
                    <div class="notes-mini-list">
                        <?php foreach($notes_existantes as $note): ?>
                            <div class="note-mini-item">
                                <span class="note-mini-subject">
                                    <?php echo htmlspecialchars($note['nom_matiere']); ?>
                                </span>
                                <span class="note-mini-score <?php echo $note['valeur'] >= 10 ? 'score-high' : 'score-low'; ?>">
                                    <?php echo $note['valeur']; ?>/20
                                </span>
                                <span class="note-mini-type">
                                    <?php echo htmlspecialchars($note['type_note']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Aide / Conseils -->
        <div class="tips-card">
            <div class="tips-card-header">
                <i class="bi bi-lightbulb"></i>
                <span>Conseils</span>
            </div>
            <div class="tips-card-body">
                <ul class="tips-list">
                    <li>
                        <i class="bi bi-check-circle"></i>
                        La note doit être comprise entre <strong>0</strong> et <strong>20</strong>
                    </li>
                    <li>
                        <i class="bi bi-check-circle"></i>
                        Tous les champs marqués d'un <span class="text-danger">*</span> sont obligatoires
                    </li>
                    <li>
                        <i class="bi bi-check-circle"></i>
                        Vous pouvez utiliser des notes avec des décimales (ex: 14.5)
                    </li>
                    <li>
                        <i class="bi bi-check-circle"></i>
                        Les notes sont automatiquement enregistrées dans la base de données
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   PAGE DE SAISIE DES NOTES
   ======================================== */

/* Page Header */
.page-header {
    margin-bottom: 28px;
}

.page-title {
    font-size: 1.65rem;
    font-weight: 700;
    color: #1a202c;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title .title-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: #1a202c;
    color: white;
    border-radius: 12px;
    font-size: 1.2rem;
}

.page-subtitle {
    font-size: 0.95rem;
    color: #64748b;
    margin: 6px 0 0 56px;
}

/* Form Card */
.form-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.form-card-header {
    padding: 16px 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.form-card-icon {
    font-size: 1.1rem;
    color: #64748b;
    margin-right: 10px;
}

.form-card-title {
    font-weight: 600;
    font-size: 1rem;
    color: #1a202c;
}

.form-card-badge {
    font-size: 0.7rem;
    font-weight: 500;
    color: #64748b;
    background: #f1f5f9;
    padding: 4px 14px;
    border-radius: 50px;
}

.form-card-body {
    padding: 24px;
}

/* Form Groups */
.form-group {
    margin-bottom: 4px;
}

.form-label {
    font-weight: 500;
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 6px;
}

.form-label.required::after {
    content: ' *';
    color: #ef4444;
}

.form-control, .form-select {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

.form-control.is-valid, .form-select.is-valid {
    border-color: #22c55e;
}

.form-control.is-invalid, .form-select.is-invalid {
    border-color: #ef4444;
}

.input-group-text {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #94a3b8;
    font-size: 0.9rem;
    border-radius: 8px 0 0 8px;
}

.input-group .form-control,
.input-group .form-select {
    border-radius: 0 8px 8px 0;
}

.invalid-feedback {
    font-size: 0.8rem;
    color: #ef4444;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.form-actions .btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 500;
    font-size: 0.9rem;
}

/* Info Card */
.info-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.info-card-header {
    padding: 12px 16px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    color: #1a202c;
}

.info-card-header i {
    color: #64748b;
}

.info-card-body {
    padding: 16px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid #f1f5f9;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-size: 0.8rem;
    color: #94a3b8;
}

.info-value {
    font-size: 0.85rem;
    font-weight: 500;
    color: #1a202c;
}

/* Notes Mini List */
.notes-mini-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.note-mini-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
    background: #f8fafc;
    border-radius: 6px;
    font-size: 0.85rem;
}

.note-mini-subject {
    font-weight: 500;
    color: #334155;
}

.note-mini-score {
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 4px;
}

.note-mini-score.score-high {
    color: #166534;
    background: #dcfce7;
}

.note-mini-score.score-low {
    color: #991b1b;
    background: #fee2e2;
}

.note-mini-type {
    font-size: 0.7rem;
    color: #94a3b8;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 4px;
}

/* Tips Card */
.tips-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    margin-top: 16px;
}

.tips-card-header {
    padding: 12px 16px;
    background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    color: #1a202c;
}

.tips-card-header i {
    color: #f59e0b;
}

.tips-card-body {
    padding: 12px 16px;
}

.tips-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tips-list li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 0.82rem;
    color: #475569;
    padding: 4px 0;
    line-height: 1.4;
}

.tips-list li i {
    color: #22c55e;
    margin-top: 2px;
    flex-shrink: 0;
}

/* Alertes */
.alert {
    border: none;
    border-radius: 10px;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert i {
    font-size: 1.2rem;
}

/* Responsive */
@media (max-width: 991.98px) {
    .page-title {
        font-size: 1.4rem;
    }
    
    .page-subtitle {
        margin-left: 0;
    }
    
    .form-card-badge {
        display: none;
    }
}

@media (max-width: 767.98px) {
    .page-header .d-flex {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    
    .form-card-body {
        padding: 16px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 575.98px) {
    .form-card-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .info-item {
        flex-direction: column;
        gap: 2px;
    }
}
</style>

<script>
// Validation du formulaire
(function() {
    'use strict';
    
    const form = document.getElementById('noteForm');
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        form.classList.add('was-validated');
    }, false);
    
    // Validation de la note en temps réel
    const valeurInput = document.getElementById('valeur');
    valeurInput.addEventListener('input', function() {
        const val = parseFloat(this.value);
        if (val < 0 || val > 20) {
            this.setCustomValidity('La note doit être entre 0 et 20');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Pré-remplir le type de note en fonction de la matière
    document.getElementById('id_matiere').addEventListener('change', function() {
        const typeSelect = document.getElementById('type_note');
        const matiereId = this.value;
        
        // Logique optionnelle pour pré-sélectionner un type selon la matière
        // Exemple: matières scientifiques -> TP, matières littéraires -> Composition
    });
    
    // Afficher les informations de l'élève sélectionné
    document.getElementById('id_eleve').addEventListener('change', function() {
        const eleveId = this.value;
        if (eleveId) {
            window.location.href = 'saisir.php?eleve_id=' + eleveId;
        }
    });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>