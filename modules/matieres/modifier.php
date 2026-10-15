<?php
require_once '../../config/session.php';
requireRole(ROLES_GESTION);
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Vérifier si l'ID est passé
$id_matiere = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id_matiere <= 0) {
    header('Location: index.php');
    exit;
}

// Récupérer les informations de la matière
$query = "SELECT * FROM matiere WHERE id_matiere = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id_matiere);
$stmt->execute();
$matiere = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$matiere) {
    header('Location: index.php');
    exit;
}

// Récupérer les statistiques
$total_matieres = $db->query("SELECT COUNT(*) as total FROM matiere")->fetch(PDO::FETCH_ASSOC)['total'];

$message = '';
$error = '';
$success = false;
$form_data = $matiere;

// Formulaire refusé si le jeton CSRF est absent ou invalide
if(csrfError()) {
    $error = csrfError();
}

if(isPostWithValidCsrf()) {
    $nom_matiere = htmlspecialchars(trim($_POST['nom_matiere']));
    $coef = floatval($_POST['coef']);

    // Validation
    $errors = [];
    
    if(empty($nom_matiere)) {
        $errors['nom_matiere'] = "Le nom de la matière est obligatoire.";
    }
    if(empty($coef) || $coef <= 0) {
        $errors['coef'] = "Le coefficient doit être supérieur à 0.";
    }
    if($coef > 10) {
        $errors['coef'] = "Le coefficient ne peut pas dépasser 10.";
    }
    
    if(empty($errors)) {
        try {
            $query = "UPDATE matiere SET 
                      nom_matiere = :nom_matiere, 
                      coef = :coef
                      WHERE id_matiere = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':nom_matiere', $nom_matiere);
            $stmt->bindParam(':coef', $coef);
            $stmt->bindParam(':id', $id_matiere);
            
            if($stmt->execute()) {
                $success = true;
                $message = "Matière modifiée avec succès !";
                // Mettre à jour les données du formulaire
                $form_data['nom_matiere'] = $nom_matiere;
                $form_data['coef'] = $coef;
                
                echo '<script>
                    setTimeout(function() {
                        window.location.href = "index.php?success=1";
                    }, 1500);
                </script>';
            } else {
                $error = "Erreur lors de la modification.";
            }
        } catch(PDOException $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    } else {
        $error = "Veuillez corriger les erreurs ci-dessous.";
        $form_data = $_POST;
    }
}

// Récupérer les statistiques pour les cartes
$total_enseignants = $db->query("SELECT COUNT(*) as total FROM enseigner WHERE id_matiere = $id_matiere")->fetch(PDO::FETCH_ASSOC)['total'];
$total_notes = $db->query("SELECT COUNT(*) as total FROM porter_sur WHERE id_matiere = $id_matiere")->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-pencil-square"></i>
                </span>
                Modifier une matière
            </h1>
            <p class="page-subtitle text-muted">
                Modifiez les informations de la matière
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="voir.php?id=<?php echo $id_matiere; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-eye"></i> Voir
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
</div>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon">
                <i class="bi bi-book-fill"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Total matières</span>
                <span class="stat-mini-value"><?php echo $total_matieres; ?></span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon text-primary">
                <i class="bi bi-person-badge"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Enseignants</span>
                <span class="stat-mini-value"><?php echo $total_enseignants; ?></span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon text-success">
                <i class="bi bi-pencil"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Notes</span>
                <span class="stat-mini-value"><?php echo $total_notes; ?></span>
            </div>
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
                        <i class="bi bi-pencil-square"></i>
                    </span>
                    <span class="form-card-title">
                        Informations de la matière
                    </span>
                    <span class="form-card-badge ms-3">
                        #<?php echo $id_matiere; ?>
                    </span>
                </div>
                <span class="form-card-badge">* Champs obligatoires</span>
            </div>
            <div class="form-card-body">
                <form method="POST" action="" id="matiereForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <!-- Informations de la matière -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="bi bi-book"></i>
                            Informations générales
                        </div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label for="nom_matiere" class="form-label required">Nom de la matière</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-book"></i></span>
                                        <input type="text" class="form-control <?php echo isset($errors['nom_matiere']) ? 'is-invalid' : ''; ?>" 
                                               id="nom_matiere" name="nom_matiere" 
                                               value="<?php echo htmlspecialchars($form_data['nom_matiere'] ?? ''); ?>"
                                               placeholder="Ex: Mathématiques" required>
                                        <?php if(isset($errors['nom_matiere'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['nom_matiere']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i> Choisissez un nom unique pour la matière
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="coef" class="form-label required">Coefficient</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-star"></i></span>
                                        <input type="number" class="form-control <?php echo isset($errors['coef']) ? 'is-invalid' : ''; ?>" 
                                               id="coef" name="coef" 
                                               value="<?php echo htmlspecialchars($form_data['coef'] ?? '1'); ?>"
                                               step="0.5" min="0.5" max="10" required>
                                        <?php if(isset($errors['coef'])): ?>
                                            <div class="invalid-feedback"><?php echo $errors['coef']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i> Entre 0.5 et 10
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Informations supplémentaires -->
                    <div class="form-section mt-4">
                        <div class="form-section-title">
                            <i class="bi bi-info-circle"></i>
                            Informations supplémentaires
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="info-display">
                                    <span class="info-display-label">
                                        <i class="bi bi-hash"></i> ID
                                    </span>
                                    <span class="info-display-value">#<?php echo $id_matiere; ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-display">
                                    <span class="info-display-label">
                                        <i class="bi bi-person-badge"></i> Enseignants
                                    </span>
                                    <span class="info-display-value"><?php echo $total_enseignants; ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-display">
                                    <span class="info-display-label">
                                        <i class="bi bi-pencil"></i> Notes
                                    </span>
                                    <span class="info-display-value"><?php echo $total_notes; ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-display">
                                    <span class="info-display-label">
                                        <i class="bi bi-clock"></i> Dernière modification
                                    </span>
                                    <span class="info-display-value"><?php echo date('d/m/Y H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="form-actions mt-4">
                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-save"></i> Mettre à jour
                        </button>
                        <a href="voir.php?id=<?php echo $id_matiere; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-eye"></i> Voir
                        </a>
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
                        <span>Le nom de la matière doit être unique</span>
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>Le coefficient est compris entre <strong>0.5</strong> et <strong>10</strong></span>
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success"></i>
                        <span>La modification affectera toutes les notes associées</span>
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
                        <strong>Modification sécurisée</strong>
                        <p class="text-muted small">Les données sont validées avant enregistrement</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-people-fill text-primary"></i>
                    </div>
                    <div class="info-content">
                        <strong>Impact sur les notes</strong>
                        <p class="text-muted small">Le coefficient affecte le calcul des moyennes</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-database-fill text-warning"></i>
                    </div>
                    <div class="info-content">
                        <strong>Historique conservé</strong>
                        <p class="text-muted small">Les modifications sont traçables</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions supplémentaires -->
        <div class="sidebar-card mt-3">
            <div class="sidebar-card-header" style="background: #fee2e2;">
                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                <span class="text-danger">Zone de danger</span>
            </div>
            <div class="sidebar-card-body">
                <form method="post" action="supprimer.php" class="m-0"
                      onsubmit="return confirm('Confirmer la suppression définitive de cette matière ?')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $id_matiere; ?>">
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-trash"></i> Supprimer cette matière
                    </button>
                </form>
                <small class="text-muted d-block mt-2 text-center">
                    <i class="bi bi-exclamation-circle"></i> Cette action est irréversible
                </small>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   PAGE DE MODIFICATION D'UNE MATIERE
   ======================================== */

/* Page Header */
.page-header {
    margin-bottom: 32px;
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

.form-control {
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.form-control:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

.form-control.is-invalid {
    border-color: #ef4444;
}

.form-control.is-invalid:focus {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
}

.invalid-feedback {
    font-size: 0.8rem;
    color: #ef4444;
}

.text-muted i {
    margin-right: 4px;
}

/* Info Display */
.info-display {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #f1f5f9;
}

.info-display-label {
    font-size: 0.8rem;
    color: #94a3b8;
}

.info-display-label i {
    margin-right: 4px;
}

.info-display-value {
    font-weight: 600;
    color: #1a202c;
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
    
    .info-display {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
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
    
    const form = document.getElementById('matiereForm');
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
    
    // Validation du coefficient
    const coefInput = document.getElementById('coef');
    coefInput.addEventListener('input', function() {
        const val = parseFloat(this.value);
        if (val < 0.5) {
            this.setCustomValidity('Le coefficient doit être au moins 0.5');
        } else if (val > 10) {
            this.setCustomValidity('Le coefficient ne peut pas dépasser 10');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Confirmation avant suppression
    const deleteBtn = document.querySelector('.btn-danger');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(e) {
            if (!confirm('Confirmer la suppression définitive de cette matière ?')) {
                e.preventDefault();
            }
        });
    }
})();
</script>

<?php require_once '../../includes/footer.php'; ?>