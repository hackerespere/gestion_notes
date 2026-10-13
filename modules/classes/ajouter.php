<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les statistiques
$total_classes = $db->query("SELECT COUNT(*) as total FROM classe")->fetch(PDO::FETCH_ASSOC)['total'];
$total_eleves = $db->query("SELECT COUNT(*) as total FROM eleve")->fetch(PDO::FETCH_ASSOC)['total'];

$message = '';
$error = '';
$success = false;
$form_data = [];

// Gestion des données pré-remplies (pour modification éventuelle)
$edit_mode = isset($_GET['id']) && !empty($_GET['id']);
$classe_data = null;

if($edit_mode) {
    $query = "SELECT * FROM classe WHERE id_classe = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->execute();
    $classe_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$classe_data) {
        $error = "Classe non trouvée.";
        $edit_mode = false;
    }
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nom_classe = htmlspecialchars(trim($_POST['nom_classe']));
    $niveau = htmlspecialchars(trim($_POST['niveau']));
    $anne_scolaire = htmlspecialchars(trim($_POST['anne_scolaire']));
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));

    // Validation
    $errors = [];
    
    if(empty($nom_classe)) {
        $errors['nom_classe'] = "Le nom de la classe est obligatoire.";
    }
    if(empty($niveau)) {
        $errors['niveau'] = "Le niveau est obligatoire.";
    }
    if(empty($anne_scolaire)) {
        $errors['anne_scolaire'] = "L'année scolaire est obligatoire.";
    }
    
    if(empty($errors)) {
        try {
            if($edit_mode && $classe_data) {
                // Mise à jour
                $query = "UPDATE classe SET 
                          nom_classe = :nom_classe, 
                          niveau = :niveau, 
                          anne_scolaire = :anne_scolaire,
                          description = :description
                          WHERE id_classe = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $_GET['id']);
            } else {
                // Insertion
                $query = "INSERT INTO classe (nom_classe, niveau, anne_scolaire, description) 
                          VALUES (:nom_classe, :niveau, :anne_scolaire, :description)";
                $stmt = $db->prepare($query);
            }
            
            $stmt->bindParam(':nom_classe', $nom_classe);
            $stmt->bindParam(':niveau', $niveau);
            $stmt->bindParam(':anne_scolaire', $anne_scolaire);
            $stmt->bindParam(':description', $description);
            
            if($stmt->execute()) {
                $success = true;
                $message = $edit_mode ? "Classe modifiée avec succès !" : "Classe ajoutée avec succès !";
                
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

// Si en mode édition, pré-remplir les données
if($edit_mode && $classe_data) {
    $form_data = $classe_data;
}

// Générer l'année scolaire automatiquement si pas définie
$annee_courante = date('Y');
$annee_suivante = date('Y', strtotime('+1 year'));
$annee_scolaire_defaut = $annee_courante . '-' . $annee_suivante;
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-building-add'; ?>"></i>
                </span>
                <?php echo $edit_mode ? 'Modifier une classe' : 'Ajouter une classe'; ?>
            </h1>
            <p class="page-subtitle text-muted">
                <?php echo $edit_mode ? 'Modifiez les informations de la classe' : 'Enregistrez une nouvelle classe dans le système'; ?>
            </p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour à la liste
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

<!-- Formulaire -->
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
                        <?php echo $edit_mode ? 'Informations de la classe' : 'Nouvelle classe'; ?>
                    </span>
                    <?php if($edit_mode && $classe_data): ?>
                        <span class="form-card-badge ms-3">
                            #<?php echo $classe_data['id_classe']; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="form-card-badge">* Champs obligatoires</span>
            </div>
            <div class="form-card-body">
                <form method="POST" action="" id="classeForm" class="needs-validation" novalidate>
                    <!-- Informations de la classe -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="bi bi-building"></i>
                            Informations générales
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="nom_classe" class="form-label required">Nom de la classe</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                                        <input type="text" class="form-control <?php echo isset($errors['nom_classe']) ? 'is-invalid' : ''; ?>" 
                                               id="nom_classe" name="nom_classe" 
                                               value="<?php echo htmlspecialchars($form_data['nom_classe'] ?? ''); ?>"
                                               placeholder="Ex: 6ème A" required>
                                        <?php if(isset($errors['nom_classe'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['nom_classe']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="niveau" class="form-label required">Niveau</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-layers"></i></span>
                                        <select class="form-select <?php echo isset($errors['niveau']) ? 'is-invalid' : ''; ?>" 
                                                id="niveau" name="niveau" required>
                                            <option value="">Sélectionner un niveau</option>
                                            <option value="6ème" <?php echo (($form_data['niveau'] ?? '') == '6ème') ? 'selected' : ''; ?>>6ème</option>
                                            <option value="5ème" <?php echo (($form_data['niveau'] ?? '') == '5ème') ? 'selected' : ''; ?>>5ème</option>
                                            <option value="4ème" <?php echo (($form_data['niveau'] ?? '') == '4ème') ? 'selected' : ''; ?>>4ème</option>
                                            <option value="3ème" <?php echo (($form_data['niveau'] ?? '') == '3ème') ? 'selected' : ''; ?>>3ème</option>
                                            <option value="Seconde" <?php echo (($form_data['niveau'] ?? '') == 'Seconde') ? 'selected' : ''; ?>>Seconde</option>
                                            <option value="Première" <?php echo (($form_data['niveau'] ?? '') == 'Première') ? 'selected' : ''; ?>>Première</option>
                                            <option value="Terminale" <?php echo (($form_data['niveau'] ?? '') == 'Terminale') ? 'selected' : ''; ?>>Terminale</option>
                                            <option value="Autre" <?php echo (($form_data['niveau'] ?? '') == 'Autre') ? 'selected' : ''; ?>>Autre</option>
                                        </select>
                                        <?php if(isset($errors['niveau'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['niveau']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="anne_scolaire" class="form-label required">Année scolaire</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                                        <input type="text" class="form-control <?php echo isset($errors['anne_scolaire']) ? 'is-invalid' : ''; ?>" 
                                               id="anne_scolaire" name="anne_scolaire" 
                                               value="<?php echo htmlspecialchars($form_data['anne_scolaire'] ?? $annee_scolaire_defaut); ?>"
                                               placeholder="Ex: 2024-2025" required>
                                        <?php if(isset($errors['anne_scolaire'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['anne_scolaire']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="description" class="form-label">Description</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-text-paragraph"></i></span>
                                        <textarea class="form-control" id="description" name="description" 
                                                  rows="2" placeholder="Description de la classe (optionnel)"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
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

    <!-- Colonne latérale - Conseils -->
    <div class="col-lg-4">
        <!-- Conseils -->
        <div class="sidebar-card">
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
                        <span>Le nom de la classe doit être unique (ex: 6ème A)</span>
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>L'année scolaire doit être au format <strong>AAAA-AAAA</strong></span>
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>Vous pouvez ajouter une description pour plus d'informations</span>
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
                        <i class="bi bi-people-fill text-primary"></i>
                    </div>
                    <div class="info-content">
                        <strong>Classes et élèves</strong>
                        <p class="text-muted small">Les élèves pourront être affectés à cette classe</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-database-fill text-warning"></i>
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
   PAGE D'AJOUT DE CLASSE
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

/* Stat Mini Cards */
.stat-mini-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.2s ease;
}

.stat-mini-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
}

.stat-mini-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #64748b;
    flex-shrink: 0;
}

.stat-mini-info {
    flex: 1;
    min-width: 0;
}

.stat-mini-label {
    display: block;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
}

.stat-mini-value {
    display: block;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1a202c;
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
    
    .stat-mini-card {
        padding: 10px 12px;
    }
    
    .stat-mini-value {
        font-size: 1rem;
    }
}

@media (max-width: 575.98px) {
    .stat-mini-card {
        flex-direction: column;
        text-align: center;
        padding: 10px;
    }
    
    .stat-mini-icon {
        margin: 0 auto;
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
    
    const form = document.getElementById('classeForm');
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
    
    // Validation du format de l'année scolaire
    const anneInput = document.getElementById('anne_scolaire');
    anneInput.addEventListener('input', function() {
        const pattern = /^\d{4}-\d{4}$/;
        if (!pattern.test(this.value) && this.value.length > 0) {
            this.setCustomValidity('Format attendu: AAAA-AAAA');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Génération automatique du nom de la classe
    const niveauSelect = document.getElementById('niveau');
    const nomClasseInput = document.getElementById('nom_classe');
    
    niveauSelect.addEventListener('change', function() {
        if (this.value && !nomClasseInput.value) {
            // Ne pas forcer si l'utilisateur a déjà saisi un nom
        }
    });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>