<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les classes
$classes = $db->query("SELECT id_classe, nom_classe, niveau FROM classe ORDER BY nom_classe")->fetchAll();

// Récupérer les statistiques
$total_eleves = $db->query("SELECT COUNT(*) as total FROM eleve")->fetch(PDO::FETCH_ASSOC)['total'];

$message = '';
$error = '';
$success = false;
$form_data = [];

// Gestion des données pré-remplies (pour modification éventuelle)
$edit_mode = isset($_GET['id']) && !empty($_GET['id']);
$eleve_data = null;

if($edit_mode) {
    $query = "SELECT * FROM eleve WHERE id_eleve = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->execute();
    $eleve_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$eleve_data) {
        $error = "Élève non trouvé.";
        $edit_mode = false;
    }
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nom = htmlspecialchars(trim($_POST['nom']));
    $prenom = htmlspecialchars(trim($_POST['prenom']));
    $date_naiss = $_POST['date_naiss'];
    $sexe = $_POST['sexe'];
    $adresse = htmlspecialchars(trim($_POST['adresse']));
    $id_classe = $_POST['id_classe'] ?: null;

    // Validation
    $errors = [];
    
    if(empty($nom)) {
        $errors['nom'] = "Le nom est obligatoire.";
    }
    if(empty($prenom)) {
        $errors['prenom'] = "Le prénom est obligatoire.";
    }
    if(empty($date_naiss)) {
        $errors['date_naiss'] = "La date de naissance est obligatoire.";
    } else {
        $age = date_diff(date_create($date_naiss), date_create('today'))->y;
        if($age < 3) {
            $errors['date_naiss'] = "L'élève doit avoir au moins 3 ans.";
        } elseif($age > 25) {
            $errors['date_naiss'] = "L'élève doit avoir moins de 25 ans.";
        }
    }
    if(empty($sexe)) {
        $errors['sexe'] = "Le sexe est obligatoire.";
    }
    
    if(empty($errors)) {
        try {
            if($edit_mode && $eleve_data) {
                $query = "UPDATE eleve SET 
                          nom = :nom, 
                          prenom = :prenom, 
                          date_naiss = :date_naiss, 
                          sexe = :sexe, 
                          adresse = :adresse, 
                          id_classe = :id_classe
                          WHERE id_eleve = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $_GET['id']);
            } else {
                $query = "INSERT INTO eleve (nom, prenom, date_naiss, sexe, adresse, id_classe) 
                          VALUES (:nom, :prenom, :date_naiss, :sexe, :adresse, :id_classe)";
                $stmt = $db->prepare($query);
            }
            
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':prenom', $prenom);
            $stmt->bindParam(':date_naiss', $date_naiss);
            $stmt->bindParam(':sexe', $sexe);
            $stmt->bindParam(':adresse', $adresse);
            $stmt->bindParam(':id_classe', $id_classe);
            
            if($stmt->execute()) {
                $success = true;
                $message = $edit_mode ? "Élève modifié avec succès !" : "Élève ajouté avec succès !";
                echo '<script>
                    setTimeout(function() {
                        window.location.href = "index.php?success=1";
                    }, 1500);
                </script>';
            } else {
                $error = "Erreur lors de l'enregistrement.";
            }
        } catch(PDOException $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    } else {
        $error = "Veuillez corriger les erreurs ci-dessous.";
        $form_data = $_POST;
    }
}

// Pré-remplir en mode édition (sauf si le formulaire a été renvoyé avec des erreurs)
if($edit_mode && $eleve_data && empty($form_data)) {
    $form_data = $eleve_data;
}

$age_affiche = '';
if(!empty($form_data['date_naiss'])) {
    $age_affiche = date_diff(date_create($form_data['date_naiss']), date_create('today'))->y . ' ans';
}
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-person-plus-fill'; ?>"></i>
                </span>
                <?php echo $edit_mode ? 'Modifier un élève' : 'Ajouter un élève'; ?>
            </h1>
            <p class="page-subtitle text-muted">
                <?php echo $edit_mode ? 'Modifiez les informations de l\'élève' : 'Enregistrez un nouvel élève dans le système'; ?>
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
<?php if($message): ?>
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

<!-- Formulaire principal avec sidebar -->
<div class="row g-4">
    <!-- Colonne principale - Formulaire -->
    <div class="col-lg-8">
        <div class="form-card">
            <div class="form-card-header">
                <div class="d-flex align-items-center">
                    <span class="form-card-icon">
                        <i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-file-earmark-plus'; ?>"></i>
                    </span>
                    <span class="form-card-title">
                        <?php echo $edit_mode ? 'Informations de l\'élève' : 'Nouvel élève'; ?>
                    </span>
                    <?php if($edit_mode && $eleve_data): ?>
                        <span class="form-card-badge ms-3">
                            #<?php echo $eleve_data['id_eleve']; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="form-card-badge">* Champs obligatoires</span>
            </div>
            <div class="form-card-body">
                <form method="POST" action="" id="eleveForm" class="needs-validation" novalidate>
                    <!-- Informations personnelles -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="bi bi-person-circle"></i>
                            Informations personnelles
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="nom" class="form-label required">Nom</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <input type="text" class="form-control <?php echo isset($errors['nom']) ? 'is-invalid' : ''; ?>" 
                                               id="nom" name="nom" 
                                               value="<?php echo htmlspecialchars($form_data['nom'] ?? ''); ?>"
                                               placeholder="Nom" required>
                                        <?php if(isset($errors['nom'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['nom']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="prenom" class="form-label required">Prénom</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <input type="text" class="form-control <?php echo isset($errors['prenom']) ? 'is-invalid' : ''; ?>" 
                                               id="prenom" name="prenom" 
                                               value="<?php echo htmlspecialchars($form_data['prenom'] ?? ''); ?>"
                                               placeholder="Prénom" required>
                                        <?php if(isset($errors['prenom'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['prenom']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="date_naiss" class="form-label required">Date de naissance</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                                        <input type="date" class="form-control <?php echo isset($errors['date_naiss']) ? 'is-invalid' : ''; ?>" 
                                               id="date_naiss" name="date_naiss" 
                                               value="<?php echo htmlspecialchars($form_data['date_naiss'] ?? ''); ?>"
                                               required>
                                        <?php if(isset($errors['date_naiss'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['date_naiss']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if(!empty($age_affiche)): ?>
                                        <small class="text-muted"><i class="bi bi-info-circle"></i> Âge : <?php echo $age_affiche; ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="sexe" class="form-label required">Sexe</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                                        <select class="form-select <?php echo isset($errors['sexe']) ? 'is-invalid' : ''; ?>" 
                                                id="sexe" name="sexe" required>
                                            <option value="">Sélectionner</option>
                                            <option value="M" <?php echo (($form_data['sexe'] ?? '') == 'M') ? 'selected' : ''; ?>>Masculin</option>
                                            <option value="F" <?php echo (($form_data['sexe'] ?? '') == 'F') ? 'selected' : ''; ?>>Féminin</option>
                                        </select>
                                        <?php if(isset($errors['sexe'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['sexe']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="id_classe" class="form-label">Classe</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                                        <select class="form-select" id="id_classe" name="id_classe">
                                            <option value="">Non affecté</option>
                                            <?php foreach($classes as $classe): ?>
                                                <option value="<?php echo $classe['id_classe']; ?>" 
                                                        <?php echo (($form_data['id_classe'] ?? '') == $classe['id_classe']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($classe['nom_classe']); ?>
                                                    <?php if($classe['niveau']): ?>
                                                        (<?php echo htmlspecialchars($classe['niveau']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Coordonnées -->
                    <div class="form-section mt-4">
                        <div class="form-section-title">
                            <i class="bi bi-geo-alt"></i>
                            Coordonnées
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="form-group">
                                    <label for="adresse" class="form-label">Adresse</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-house"></i></span>
                                        <textarea class="form-control" id="adresse" name="adresse" 
                                                  rows="2" placeholder="Adresse complète"><?php echo htmlspecialchars($form_data['adresse'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="form-actions mt-4">
                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-save"></i> 
                            <?php echo $edit_mode ? 'Mettre à jour' : 'Enregistrer'; ?>
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Annuler
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Colonne latérale - Statistiques et conseils -->
    <div class="col-lg-4">
        <!-- Statistiques -->
        <div class="sidebar-card">
            <div class="sidebar-card-header">
                <i class="bi bi-graph-up"></i>
                <span>Statistiques</span>
            </div>
            <div class="sidebar-card-body">
                <div class="stat-item">
                    <span class="stat-item-label">
                        <i class="bi bi-people-fill"></i> Total élèves
                    </span>
                    <span class="stat-item-value"><?php echo $total_eleves; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-item-label">
                        <i class="bi bi-building-fill"></i> Classes disponibles
                    </span>
                    <span class="stat-item-value"><?php echo count($classes); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-item-label">
                        <i class="bi bi-check-circle-fill"></i> Statut
                    </span>
                    <span class="stat-item-value" style="font-size: 0.9rem; color: <?php echo $edit_mode ? '#f59e0b' : '#22c55e'; ?>;">
                        <?php echo $edit_mode ? 'Modification' : 'Nouvel élève'; ?>
                    </span>
                </div>
                <?php if($edit_mode && $eleve_data): ?>
                    <div class="stat-item">
                        <span class="stat-item-label">
                            <i class="bi bi-hash"></i> ID
                        </span>
                        <span class="stat-item-value">#<?php echo $eleve_data['id_eleve']; ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Conseils -->
        <div class="sidebar-card mt-3">
            <div class="sidebar-card-header">
                <i class="bi bi-lightbulb"></i>
                <span>Conseils pratiques</span>
            </div>
            <div class="sidebar-card-body">
                <ul class="tips-list">
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>Les champs marqués d'un <strong class="text-danger">*</strong> sont obligatoires</span>
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>L'élève doit avoir entre <strong>3</strong> et <strong>25</strong> ans</span>
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>Vous pouvez affecter l'élève à une classe maintenant ou plus tard</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Informations rapides -->
        <div class="sidebar-card mt-3">
            <div class="sidebar-card-header">
                <i class="bi bi-info-circle"></i>
                <span>À savoir</span>
            </div>
            <div class="sidebar-card-body">
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-check-circle-fill text-success"></i>
                    </div>
                    <div class="info-content">
                        <strong>Saisie sécurisée</strong>
                        <p class="text-muted small">Les données sont validées avant enregistrement</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-clock-fill text-warning"></i>
                    </div>
                    <div class="info-content">
                        <strong>Enregistrement rapide</strong>
                        <p class="text-muted small">Formulaire optimisé pour une saisie efficace</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-database-fill text-primary"></i>
                    </div>
                    <div class="info-content">
                        <strong>Données persistantes</strong>
                        <p class="text-muted small">Les informations sont stockées en base de données</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   PAGE D'AJOUT D'ÉLÈVE - VERSION AMÉLIORÉE
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
    flex-wrap: wrap;
    gap: 8px;
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

/* Form Sections */
.form-section {
    border: 1px solid #f1f5f9;
    border-radius: 10px;
    padding: 16px 20px;
    background: #fafbfc;
}

.form-section-title {
    font-weight: 600;
    font-size: 0.9rem;
    color: #1a202c;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-section-title i {
    color: #64748b;
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

.input-group-text {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #94a3b8;
    font-size: 0.9rem;
}

.form-control, .form-select {
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

.form-control.is-invalid, .form-select.is-invalid {
    border-color: #ef4444;
}

.form-control.is-invalid:focus, .form-select.is-invalid:focus {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
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
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

.form-actions .btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 500;
    font-size: 0.9rem;
}

/* Sidebar Cards */
.sidebar-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.sidebar-card-header {
    padding: 14px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    color: #1a202c;
}

.sidebar-card-header i {
    color: #64748b;
    font-size: 1rem;
}

.sidebar-card-body {
    padding: 16px 18px;
}

/* Stat Items */
.stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
}

.stat-item:last-child {
    border-bottom: none;
}

.stat-item-label {
    font-size: 0.85rem;
    color: #475569;
}

.stat-item-label i {
    margin-right: 8px;
    color: #94a3b8;
}

.stat-item-value {
    font-weight: 700;
    color: #1a202c;
    font-size: 1.1rem;
}

/* Tips List */
.tips-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tips-list li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 0.82rem;
    color: #475569;
    padding: 6px 0;
    line-height: 1.4;
}

.tips-list li i {
    margin-top: 2px;
    flex-shrink: 0;
    font-size: 0.9rem;
}

.tips-list li strong {
    color: #1a202c;
}

/* Info Items */
.info-item {
    display: flex;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
}

.info-item:last-child {
    border-bottom: none;
}

.info-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

.info-content {
    flex: 1;
}

.info-content strong {
    display: block;
    font-size: 0.85rem;
    color: #1a202c;
}

.info-content p {
    margin: 0;
    font-size: 0.75rem;
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
    
    .col-lg-4 {
        margin-top: 16px;
    }
}

@media (max-width: 767.98px) {
    .page-header .d-flex {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    
    .form-card-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .form-card-body {
        padding: 16px;
    }
    
    .form-section {
        padding: 12px 16px;
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
    .stat-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    
    .info-item {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
}
</style>

<script>
// Validation du formulaire
(function() {
    'use strict';
    
    const form = document.getElementById('eleveForm');
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
    
    // Validation de la date de naissance
    const dateInput = document.getElementById('date_naiss');
    dateInput.addEventListener('change', function() {
        const date = new Date(this.value);
        const today = new Date();
        const age = Math.floor((today - date) / (365.25 * 24 * 60 * 60 * 1000));
        
        if (age < 3) {
            this.setCustomValidity('L\'élève doit avoir au moins 3 ans');
        } else if (age > 25) {
            this.setCustomValidity('L\'élève doit avoir moins de 25 ans');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Auto-formatage du téléphone
    const telInputs = document.querySelectorAll('input[type="tel"]');
    telInputs.forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 10) {
                value = value.slice(0, 10);
            }
            if (value.length >= 2) {
                value = value.slice(0, 2) + ' ' + value.slice(2);
            }
            if (value.length >= 5) {
                value = value.slice(0, 3) + ' ' + value.slice(3);
            }
            this.value = value;
        });
    });
    
    // Mise à jour de l'âge en temps réel
    dateInput.addEventListener('change', function() {
        const date = new Date(this.value);
        const today = new Date();
        const age = Math.floor((today - date) / (365.25 * 24 * 60 * 60 * 1000));
        const ageDisplay = document.querySelector('.form-group small.text-muted');
        if (ageDisplay && this.value) {
            ageDisplay.textContent = 'Âge : ' + age + ' ans';
        }
    });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>