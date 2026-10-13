<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// Récupérer les données pour les filtres
$classes = $db->query("SELECT id_classe, nom_classe, niveau FROM classe ORDER BY nom_classe")->fetchAll();
$trimestres = $db->query("SELECT id_trimestre, libelle, date_debut, date_fin FROM trimestre ORDER BY id_trimestre")->fetchAll();

$eleves = [];
$bulletin_data = null;
$selected_classe = isset($_POST['id_classe']) ? $_POST['id_classe'] : '';
$selected_trimestre = isset($_POST['id_trimestre']) ? $_POST['id_trimestre'] : '';
$selected_eleve = isset($_POST['id_eleve']) ? $_POST['id_eleve'] : '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_classe = $_POST['id_classe'];
    $id_trimestre = $_POST['id_trimestre'];
    $id_eleve = $_POST['id_eleve'] ?? null;

    if(empty($id_classe) || empty($id_trimestre)) {
        $error = "Veuillez sélectionner une classe et un trimestre.";
    } else {
        // Récupérer les élèves de la classe
        $query = "SELECT id_eleve, nom, prenom, date_naiss FROM eleve WHERE id_classe = :id_classe ORDER BY nom";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id_classe', $id_classe);
        $stmt->execute();
        $eleves = $stmt->fetchAll();

        if($id_eleve) {
            // Générer le bulletin pour un élève spécifique
            $bulletin_data = generateBulletin($db, $id_eleve, $id_trimestre);
        }
    }
}

function generateBulletin($db, $id_eleve, $id_trimestre) {
    // Récupérer les infos de l'élève
    $query = "SELECT e.*, c.nom_classe, c.niveau 
              FROM eleve e 
              JOIN classe c ON e.id_classe = c.id_classe 
              WHERE e.id_eleve = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id_eleve);
    $stmt->execute();
    $eleve = $stmt->fetch(PDO::FETCH_ASSOC);

    // Récupérer les notes de l'élève pour le trimestre
    $query = "SELECT n.*, m.nom_matiere, m.coef 
              FROM note n
              JOIN obtenir o ON n.id_note = o.id_note
              JOIN porter_sur ps ON n.id_note = ps.id_note
              JOIN matiere m ON ps.id_matiere = m.id_matiere
              JOIN durant d ON n.id_note = d.id_note
              WHERE o.id_eleve = :id_eleve AND d.id_trimestre = :id_trimestre
              ORDER BY m.nom_matiere";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id_eleve', $id_eleve);
    $stmt->bindParam(':id_trimestre', $id_trimestre);
    $stmt->execute();
    $notes = $stmt->fetchAll();

    // Calculer les moyennes par matière
    $matieres = [];
    foreach($notes as $note) {
        $matiere = $note['nom_matiere'];
        if(!isset($matieres[$matiere])) {
            $matieres[$matiere] = [
                'total' => 0, 
                'count' => 0, 
                'coef' => $note['coef'],
                'notes' => []
            ];
        }
        $matieres[$matiere]['total'] += $note['valeur'];
        $matieres[$matiere]['count']++;
        $matieres[$matiere]['notes'][] = $note['valeur'];
    }

    // Calculer la moyenne générale
    $moyenne_generale = 0;
    $total_coef = 0;
    foreach($matieres as $matiere => $data) {
        $moy = $data['total'] / $data['count'];
        $moyenne_generale += $moy * $data['coef'];
        $total_coef += $data['coef'];
    }
    if($total_coef > 0) {
        $moyenne_generale /= $total_coef;
    }

    // Calculer le rang de l'élève dans la classe
    $rang = 0;
    $total_eleves_classe = 0;
    $query_rang = "SELECT e.id_eleve, 
                   (SELECT AVG(n.valeur) FROM note n 
                    JOIN obtenir o ON n.id_note = o.id_note 
                    JOIN durant d ON n.id_note = d.id_note 
                    WHERE o.id_eleve = e.id_eleve AND d.id_trimestre = :id_trimestre) as moyenne
                   FROM eleve e
                   WHERE e.id_classe = (SELECT id_classe FROM eleve WHERE id_eleve = :id_eleve)";
    $stmt_rang = $db->prepare($query_rang);
    $stmt_rang->bindParam(':id_trimestre', $id_trimestre);
    $stmt_rang->bindParam(':id_eleve', $id_eleve);
    $stmt_rang->execute();
    $moyennes_classe = $stmt_rang->fetchAll(PDO::FETCH_ASSOC);
    
    $total_eleves_classe = count($moyennes_classe);
    $moyennes_triees = array_column($moyennes_classe, 'moyenne');
    rsort($moyennes_triees);
    
    $moyenne_eleve = $moyenne_generale;
    $rang = array_search($moyenne_eleve, $moyennes_triees) + 1;

    return [
        'eleve' => $eleve,
        'notes' => $notes,
        'matieres' => $matieres,
        'moyenne_generale' => $moyenne_generale,
        'rang' => $rang,
        'total_eleves_classe' => $total_eleves_classe
    ];
}
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-file-text-fill"></i>
                </span>
                Bulletins scolaires
            </h1>
            <p class="page-subtitle text-muted">
                Générez et consultez les bulletins des élèves
            </p>
        </div>
        <div>
            <button class="btn btn-outline-secondary" onclick="window.print()" title="Imprimer">
                <i class="bi bi-printer"></i>
            </button>
        </div>
    </div>
</div>

<!-- Filtres améliorés -->
<div class="filter-card mb-4">
    <div class="filter-card-body">
        <form method="POST" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="id_classe" class="form-label">
                    <i class="bi bi-building"></i> Classe *
                </label>
                <select class="form-select" id="id_classe" name="id_classe" required>
                    <option value="">Sélectionner une classe</option>
                    <?php foreach($classes as $classe): ?>
                        <option value="<?php echo $classe['id_classe']; ?>" 
                                <?php echo ($selected_classe == $classe['id_classe']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($classe['nom_classe']); ?>
                            <?php if($classe['niveau']): ?>
                                (<?php echo htmlspecialchars($classe['niveau']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="id_trimestre" class="form-label">
                    <i class="bi bi-calendar-range"></i> Trimestre *
                </label>
                <select class="form-select" id="id_trimestre" name="id_trimestre" required>
                    <option value="">Sélectionner un trimestre</option>
                    <?php foreach($trimestres as $trimestre): ?>
                        <option value="<?php echo $trimestre['id_trimestre']; ?>" 
                                <?php echo ($selected_trimestre == $trimestre['id_trimestre']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($trimestre['libelle']); ?>
                            (<?php echo date('d/m', strtotime($trimestre['date_debut'])); ?> - 
                            <?php echo date('d/m', strtotime($trimestre['date_fin'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="id_eleve" class="form-label">
                    <i class="bi bi-person"></i> Élève
                </label>
                <select class="form-select" id="id_eleve" name="id_eleve">
                    <option value="">Tous les élèves</option>
                    <?php foreach($eleves as $eleve): ?>
                        <option value="<?php echo $eleve['id_eleve']; ?>" 
                                <?php echo ($selected_eleve == $eleve['id_eleve']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($eleve['nom'] . ' ' . $eleve['prenom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-dark w-100">
                    <i class="bi bi-search"></i> Générer le bulletin
                </button>
            </div>
        </form>
    </div>
</div>

<?php if($bulletin_data): ?>
    <!-- Bulletin -->
    <div class="bulletin-card">
        <!-- En-tête du bulletin -->
        <div class="bulletin-header">
            <div class="bulletin-title">
                <h2>
                    <i class="bi bi-mortarboard-fill"></i>
                    BULLETIN SCOLAIRE
                </h2>
                <p><?php echo htmlspecialchars($bulletin_data['eleve']['nom_classe']); ?> - Trimestre <?php echo $selected_trimestre; ?></p>
            </div>
            <div class="bulletin-school">
                <span>Année scolaire <?php echo date('Y') . '-' . (date('Y') + 1); ?></span>
            </div>
        </div>

        <!-- Informations de l'élève -->
        <div class="bulletin-student-info">
            <div class="student-avatar-large">
                <?php echo strtoupper(substr($bulletin_data['eleve']['prenom'], 0, 1) . substr($bulletin_data['eleve']['nom'], 0, 1)); ?>
            </div>
            <div class="student-details">
                <h3><?php echo htmlspecialchars($bulletin_data['eleve']['prenom'] . ' ' . $bulletin_data['eleve']['nom']); ?></h3>
                <div class="student-meta">
                    <span><i class="bi bi-building"></i> <?php echo htmlspecialchars($bulletin_data['eleve']['nom_classe']); ?></span>
                    <span><i class="bi bi-layers"></i> <?php echo htmlspecialchars($bulletin_data['eleve']['niveau']); ?></span>
                    <span><i class="bi bi-calendar3"></i> <?php echo date('d/m/Y', strtotime($bulletin_data['eleve']['date_naiss'])); ?></span>
                </div>
            </div>
            <div class="student-rank">
                <div class="rank-circle">
                    <span class="rank-number"><?php echo $bulletin_data['rang']; ?></span>
                    <span class="rank-label">Rang</span>
                </div>
                <div class="rank-total">sur <?php echo $bulletin_data['total_eleves_classe']; ?> élèves</div>
            </div>
        </div>

        <!-- Résumé des notes -->
        <div class="bulletin-summary">
            <div class="summary-item">
                <span class="summary-label">Moyenne générale</span>
                <span class="summary-value <?php echo $bulletin_data['moyenne_generale'] >= 10 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($bulletin_data['moyenne_generale'], 2); ?>/20
                </span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Total matières</span>
                <span class="summary-value"><?php echo count($bulletin_data['matieres']); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Total notes</span>
                <span class="summary-value"><?php echo count($bulletin_data['notes']); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Appréciation</span>
                <span class="summary-value <?php echo $bulletin_data['moyenne_generale'] >= 10 ? 'text-success' : 'text-danger'; ?>">
                    <?php 
                    if($bulletin_data['moyenne_generale'] >= 16) echo 'Excellent';
                    elseif($bulletin_data['moyenne_generale'] >= 14) echo 'Très bien';
                    elseif($bulletin_data['moyenne_generale'] >= 12) echo 'Bien';
                    elseif($bulletin_data['moyenne_generale'] >= 10) echo 'Assez bien';
                    elseif($bulletin_data['moyenne_generale'] >= 8) echo 'Passable';
                    else echo 'À améliorer';
                    ?>
                </span>
            </div>
        </div>

        <!-- Tableau des notes -->
        <div class="bulletin-table-wrapper">
            <table class="bulletin-table">
                <thead>
                    <tr>
                        <th>Matière</th>
                        <th>Notes</th>
                        <th>Moyenne</th>
                        <th>Coef.</th>
                        <th>Moy. pondérée</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $moyenne_generale = 0;
                    $total_coef = 0;
                    foreach($bulletin_data['matieres'] as $matiere => $data):
                        $moy = $data['total'] / $data['count'];
                        $moy_ponderee = $moy * $data['coef'];
                        $moyenne_generale += $moy_ponderee;
                        $total_coef += $data['coef'];
                    ?>
                    <tr>
                        <td class="matiere-name"><?php echo htmlspecialchars($matiere); ?></td>
                        <td>
                            <div class="notes-list">
                                <?php foreach($data['notes'] as $note): ?>
                                    <span class="note-badge <?php echo $note >= 10 ? 'note-good' : 'note-bad'; ?>">
                                        <?php echo $note; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <span class="moyenne-badge <?php echo $moy >= 10 ? 'moyenne-good' : 'moyenne-bad'; ?>">
                                <?php echo number_format($moy, 2); ?>
                            </span>
                        </td>
                        <td><?php echo $data['coef']; ?></td>
                        <td><?php echo number_format($moy_ponderee, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4"><strong>Moyenne générale</strong></td>
                        <td>
                            <strong>
                                <span class="moyenne-badge <?php echo ($moyenne_generale / $total_coef) >= 10 ? 'moyenne-good' : 'moyenne-bad'; ?>">
                                    <?php echo number_format($moyenne_generale / $total_coef, 2); ?>
                                </span>
                            </strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Pied du bulletin -->
        <div class="bulletin-footer">
            <div class="bulletin-actions">
                <button class="btn btn-dark" onclick="window.print()">
                    <i class="bi bi-printer"></i> Imprimer
                </button>
                <a href="imprimer.php?eleve_id=<?php echo $bulletin_data['eleve']['id_eleve']; ?>&trimestre_id=<?php echo $selected_trimestre; ?>" 
                   class="btn btn-danger">
                    <i class="bi bi-file-pdf"></i> PDF
                </a>
                <button class="btn btn-outline-secondary" onclick="exportBulletinCSV()">
                    <i class="bi bi-download"></i> CSV
                </button>
            </div>
            <div class="bulletin-date">
                <i class="bi bi-clock"></i>
                Généré le <?php echo date('d/m/Y à H:i'); ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- État vide -->
    <div class="empty-state-card">
        <div class="empty-state-content">
            <i class="bi bi-file-text"></i>
            <h5>Aucun bulletin généré</h5>
            <p class="text-muted">
                Sélectionnez une classe, un trimestre et un élève pour générer son bulletin
            </p>
            <span class="text-muted small">
                <i class="bi bi-arrow-up"></i> Utilisez les filtres ci-dessus
            </span>
        </div>
    </div>
<?php endif; ?>

<style>
/* ========================================
   PAGE DES BULLETINS SCOLAIRES
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

/* Filter Card */
.filter-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 24px;
}

.filter-card-body .form-label {
    font-weight: 500;
    font-size: 0.8rem;
    color: #334155;
    margin-bottom: 6px;
}

.filter-card-body .form-label i {
    color: #94a3b8;
    margin-right: 4px;
}

.filter-card-body .form-select {
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 0.9rem;
}

.filter-card-body .form-select:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

/* Bulletin Card */
.bulletin-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
}

/* Bulletin Header */
.bulletin-header {
    padding: 24px 30px;
    background: linear-gradient(135deg, #1a202c, #2d3748);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.bulletin-title h2 {
    font-weight: 700;
    font-size: 1.3rem;
    margin: 0;
    letter-spacing: 1px;
}

.bulletin-title h2 i {
    margin-right: 10px;
}

.bulletin-title p {
    margin: 4px 0 0 0;
    font-size: 0.85rem;
    opacity: 0.8;
}

.bulletin-school {
    font-size: 0.85rem;
    opacity: 0.8;
}

/* Student Info */
.bulletin-student-info {
    padding: 24px 30px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.student-avatar-large {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #1a202c;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    font-weight: 700;
    flex-shrink: 0;
}

.student-details {
    flex: 1;
    min-width: 0;
}

.student-details h3 {
    font-weight: 700;
    color: #1a202c;
    margin: 0;
    font-size: 1.2rem;
}

.student-meta {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 4px;
}

.student-meta span {
    font-size: 0.8rem;
    color: #64748b;
}

.student-meta span i {
    margin-right: 4px;
}

.student-rank {
    display: flex;
    align-items: center;
    gap: 12px;
}

.rank-circle {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: white;
}

.rank-number {
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1;
}

.rank-label {
    font-size: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.9;
}

.rank-total {
    font-size: 0.75rem;
    color: #94a3b8;
}

/* Bulletin Summary */
.bulletin-summary {
    padding: 16px 30px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

.summary-item {
    text-align: center;
}

.summary-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    margin-bottom: 2px;
}

.summary-value {
    display: block;
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a202c;
}

/* Bulletin Table */
.bulletin-table-wrapper {
    padding: 20px 30px;
    overflow-x: auto;
}

.bulletin-table {
    width: 100%;
    border-collapse: collapse;
}

.bulletin-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
}

.bulletin-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}

.bulletin-table tbody tr:hover {
    background: #f8fafc;
}

.bulletin-table tfoot td {
    padding: 12px 14px;
    border-top: 2px solid #e2e8f0;
    font-weight: 700;
    background: #f8fafc;
}

.matiere-name {
    font-weight: 600;
    color: #1a202c;
}

.notes-list {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.note-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
}

.note-good {
    background: #dcfce7;
    color: #166534;
}

.note-bad {
    background: #fee2e2;
    color: #991b1b;
}

.moyenne-badge {
    display: inline-block;
    padding: 2px 12px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 700;
}

.moyenne-good {
    background: #dcfce7;
    color: #166534;
}

.moyenne-bad {
    background: #fee2e2;
    color: #991b1b;
}

/* Bulletin Footer */
.bulletin-footer {
    padding: 16px 30px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.bulletin-actions {
    display: flex;
    gap: 8px;
}

.bulletin-actions .btn {
    padding: 8px 16px;
    font-size: 0.85rem;
    border-radius: 8px;
}

.bulletin-date {
    font-size: 0.75rem;
    color: #94a3b8;
}

.bulletin-date i {
    margin-right: 4px;
}

/* Empty State */
.empty-state-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 60px 20px;
    text-align: center;
}

.empty-state-content i {
    font-size: 3.5rem;
    color: #e2e8f0;
}

.empty-state-content h5 {
    margin: 16px 0 8px 0;
    color: #1a202c;
    font-weight: 600;
}

.empty-state-content p {
    font-size: 0.9rem;
    color: #94a3b8;
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

/* Responsive */
@media (max-width: 991.98px) {
    .page-title {
        font-size: 1.4rem;
    }
    
    .page-subtitle {
        margin-left: 0;
    }
}

@media (max-width: 767.98px) {
    .page-header .d-flex {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    
    .filter-card {
        padding: 16px;
    }
    
    .bulletin-header {
        flex-direction: column;
        text-align: center;
    }
    
    .bulletin-student-info {
        flex-direction: column;
        text-align: center;
    }
    
    .student-meta {
        justify-content: center;
    }
    
    .student-rank {
        flex-direction: column;
    }
    
    .bulletin-summary {
        grid-template-columns: 1fr 1fr;
    }
    
    .bulletin-table-wrapper {
        padding: 12px 16px;
    }
    
    .bulletin-footer {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
    
    .bulletin-actions {
        justify-content: center;
        flex-wrap: wrap;
    }
}

@media (max-width: 575.98px) {
    .bulletin-summary {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .bulletin-table thead th {
        font-size: 0.6rem;
        padding: 6px 8px;
    }
    
    .bulletin-table tbody td {
        font-size: 0.75rem;
        padding: 6px 8px;
    }
    
    .bulletin-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

/* Print styles */
@media print {
    .page-header,
    .filter-card,
    .bulletin-actions,
    .bulletin-date,
    .no-print {
        display: none !important;
    }
    
    .bulletin-card {
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }
    
    .bulletin-header {
        background: #1a202c !important;
        color: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .student-avatar-large {
        background: #1a202c !important;
        color: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .rank-circle {
        background: #f59e0b !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .note-badge,
    .moyenne-badge {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>

<script>
// Mettre à jour la liste des élèves quand la classe change
document.getElementById('id_classe').addEventListener('change', function() {
    const classeId = this.value;
    const eleveSelect = document.getElementById('id_eleve');
    eleveSelect.innerHTML = '<option value="">Tous les élèves</option>';
    
    if(classeId) {
        fetch(`../../api/get_eleves_by_classe.php?classe_id=${classeId}`)
            .then(response => response.json())
            .then(data => {
                data.forEach(eleve => {
                    const option = document.createElement('option');
                    option.value = eleve.id_eleve;
                    option.textContent = eleve.nom + ' ' + eleve.prenom;
                    eleveSelect.appendChild(option);
                });
            })
            .catch(error => console.error('Erreur:', error));
    }
});

// Fonction pour exporter le bulletin en CSV
function exportBulletinCSV() {
    const table = document.querySelector('.bulletin-table');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    // En-têtes
    const headers = ['Matière', 'Notes', 'Moyenne', 'Coefficient', 'Moyenne pondérée'];
    csv.push(headers.join(','));
    
    // Données
    rows.forEach(row => {
        if (row.querySelector('th')) return;
        const cols = row.querySelectorAll('td');
        const rowData = [];
        cols.forEach(col => {
            let text = col.textContent.trim();
            // Nettoyer les données multiples
            text = text.replace(/\s+/g, ' ');
            text = text.replace(/,/g, ';');
            rowData.push(text);
        });
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });
    
    const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'bulletin_<?php echo date('Ymd'); ?>.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<?php require_once '../../includes/footer.php'; ?>