<?php
require_once '../../config/session.php';
requireRole(ROLES_PEDAGOGIE);
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

$eleve_id = isset($_GET['eleve_id']) ? $_GET['eleve_id'] : null;
$trimestre_filter = isset($_GET['trimestre']) ? $_GET['trimestre'] : null;
$eleve_nom = '';
$notes = [];
$moyennes = [];
$moyenne_generale = 0;
$total_coef = 0;
$stats_notes = [];

if($eleve_id) {
    // Récupérer le nom de l'élève et ses informations
    $query = "SELECT e.*, c.nom_classe, c.niveau 
              FROM eleve e 
              LEFT JOIN classe c ON e.id_classe = c.id_classe 
              WHERE e.id_eleve = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $eleve_id);
    $stmt->execute();
    $eleve = $stmt->fetch(PDO::FETCH_ASSOC);
    if($eleve) {
        $eleve_nom = $eleve['prenom'] . ' ' . $eleve['nom'];
    }

    // Récupérer les notes de l'élève avec filtre trimestre
    $sql = "SELECT n.*, m.nom_matiere, m.coef, t.libelle as trimestre, 
            ens.nom as enseignant_nom, ens.prenom as enseignant_prenom
            FROM note n
            INNER JOIN obtenir o ON n.id_note = o.id_note
            INNER JOIN porter_sur ps ON n.id_note = ps.id_note
            INNER JOIN matiere m ON ps.id_matiere = m.id_matiere
            INNER JOIN saisir s ON n.id_note = s.id_note
            INNER JOIN enseignant ens ON s.id_enseignant = ens.id_enseignant
            INNER JOIN durant d ON n.id_note = d.id_note
            INNER JOIN trimestre t ON d.id_trimestre = t.id_trimestre
            WHERE o.id_eleve = :id";
    
    if($trimestre_filter) {
        $sql .= " AND d.id_trimestre = :trimestre";
    }
    
    $sql .= " ORDER BY n.date_note DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':id', $eleve_id);
    if($trimestre_filter) {
        $stmt->bindParam(':trimestre', $trimestre_filter);
    }
    $stmt->execute();
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculer les moyennes par matière
    $moyennes = [];
    foreach($notes as $note) {
        $matiere = $note['nom_matiere'];
        if(!isset($moyennes[$matiere])) {
            $moyennes[$matiere] = [
                'total' => 0, 
                'count' => 0, 
                'coef' => $note['coef'],
                'notes' => []
            ];
        }
        $moyennes[$matiere]['total'] += $note['valeur'];
        $moyennes[$matiere]['count']++;
        $moyennes[$matiere]['notes'][] = $note['valeur'];
    }

    // Calculer la moyenne générale
    $moyenne_generale = 0;
    $total_coef = 0;
    foreach($moyennes as $matiere => $data) {
        $moy = $data['total'] / $data['count'];
        $moyenne_generale += $moy * $data['coef'];
        $total_coef += $data['coef'];
    }
    if($total_coef > 0) {
        $moyenne_generale /= $total_coef;
    }

    // Statistiques des notes
    if(count($notes) > 0) {
        $notes_values = array_column($notes, 'valeur');
        $stats_notes = [
            'min' => min($notes_values),
            'max' => max($notes_values),
            'moyenne' => array_sum($notes_values) / count($notes_values),
            'count' => count($notes_values),
            'sup_10' => count(array_filter($notes_values, function($v) { return $v >= 10; })),
            'inf_10' => count(array_filter($notes_values, function($v) { return $v < 10; }))
        ];
    }
}

// Récupérer la liste des élèves pour le formulaire
$eleves = $db->query("SELECT id_eleve, nom, prenom FROM eleve ORDER BY nom")->fetchAll();

// Récupérer les trimestres pour le filtre
$trimestres = $db->query("SELECT id_trimestre, libelle FROM trimestre ORDER BY id_trimestre")->fetchAll();
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-eye"></i>
                </span>
                Consulter les notes
            </h1>
            <p class="page-subtitle text-muted">
                Visualisez et analysez les notes des élèves
            </p>
        </div>
        <div>
            <a href="saisir.php" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Saisir une note
            </a>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="filter-card mb-4">
    <div class="filter-card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="eleve_id" class="form-label">Élève</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <select class="form-select" id="eleve_id" name="eleve_id" onchange="this.form.submit()">
                        <option value="">-- Sélectionner un élève --</option>
                        <?php foreach($eleves as $eleve): ?>
                            <option value="<?php echo $eleve['id_eleve']; ?>" 
                                    <?php echo ($eleve_id == $eleve['id_eleve']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($eleve['nom'] . ' ' . $eleve['prenom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <label for="trimestre" class="form-label">Trimestre</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar-range"></i></span>
                    <select class="form-select" id="trimestre" name="trimestre" onchange="this.form.submit()">
                        <option value="">Tous les trimestres</option>
                        <?php foreach($trimestres as $trimestre): ?>
                            <option value="<?php echo $trimestre['id_trimestre']; ?>" 
                                    <?php echo ($trimestre_filter == $trimestre['id_trimestre']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($trimestre['libelle']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <a href="consulter.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                </a>
            </div>
        </form>
    </div>
</div>

<?php if($eleve_id && $eleve_nom): ?>
    <!-- Carte de résumé de l'élève -->
    <div class="student-summary-card mb-4">
        <div class="student-summary-body">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <div class="student-avatar">
                        <span><?php echo strtoupper(substr($eleve['prenom'], 0, 1) . substr($eleve['nom'], 0, 1)); ?></span>
                    </div>
                    <div class="student-name">
                        <h4 class="mb-0"><?php echo htmlspecialchars($eleve_nom); ?></h4>
                        <span class="text-muted"><?php echo htmlspecialchars($eleve['nom_classe'] ?? 'Non affecté'); ?></span>
                    </div>
                </div>
                <div class="col-md-9">
                    <div class="row g-2">
                        <div class="col-sm-3 col-6">
                            <div class="stat-mini">
                                <span class="stat-mini-label">Moyenne générale</span>
                                <span class="stat-mini-value <?php echo $moyenne_generale >= 10 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format($moyenne_generale, 2); ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="stat-mini">
                                <span class="stat-mini-label">Total notes</span>
                                <span class="stat-mini-value"><?php echo $stats_notes['count'] ?? 0; ?></span>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="stat-mini">
                                <span class="stat-mini-label">Meilleure note</span>
                                <span class="stat-mini-value text-success"><?php echo $stats_notes['max'] ?? '-'; ?></span>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="stat-mini">
                                <span class="stat-mini-label">Note minimale</span>
                                <span class="stat-mini-value text-danger"><?php echo $stats_notes['min'] ?? '-'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(count($notes) > 0): ?>
        <!-- Tableau des notes -->
        <div class="notes-table-card">
            <div class="notes-table-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="card-icon"><i class="bi bi-table"></i></span>
                        <span class="card-title">Détail des notes</span>
                        <span class="notes-count-badge"><?php echo count($notes); ?> notes</span>
                    </div>
                    <div>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                            <i class="bi bi-printer"></i> Imprimer
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="exportToCSV()">
                            <i class="bi bi-download"></i> Exporter
                        </button>
                    </div>
                </div>
            </div>
            <div class="notes-table-body">
                <div class="table-responsive">
                    <table class="table notes-table" id="notesTable">
                        <thead>
                            <tr>
                                <th>Matière</th>
                                <th>Note</th>
                                <th>Coeff.</th>
                                <th>Type</th>
                                <th>Trimestre</th>
                                <th>Enseignant</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($notes as $note): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($note['nom_matiere']); ?></strong>
                                </td>
                                <td>
                                    <span class="score-badge <?php echo $note['valeur'] >= 10 ? 'score-high' : 'score-low'; ?>">
                                        <?php echo $note['valeur']; ?>/20
                                    </span>
                                </td>
                                <td><?php echo $note['coef']; ?></td>
                                <td>
                                    <span class="type-badge">
                                        <?php echo htmlspecialchars($note['type_note']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="trimestre-badge">
                                        <?php echo htmlspecialchars($note['trimestre']); ?>
                                    </span>
                                </td>
                                <td>
                                    <i class="bi bi-person-circle text-muted"></i>
                                    <?php echo htmlspecialchars($note['enseignant_prenom'] . ' ' . $note['enseignant_nom']); ?>
                                </td>
                                <td>
                                    <i class="bi bi-calendar3 text-muted"></i>
                                    <?php echo date('d/m/Y', strtotime($note['date_note'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Moyennes par matière -->
        <?php if(count($moyennes) > 0): ?>
            <div class="averages-card mt-4">
                <div class="averages-card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="card-icon"><i class="bi bi-graph-up"></i></span>
                            <span class="card-title">Moyennes par matière</span>
                        </div>
                        <span class="card-badge">Pondérées par coefficient</span>
                    </div>
                </div>
                <div class="averages-card-body">
                    <div class="row g-3">
                        <?php 
                        $moyenne_generale = 0;
                        $total_coef = 0;
                        foreach($moyennes as $matiere => $data):
                            $moy = $data['total'] / $data['count'];
                            $moy_ponderee = $moy * $data['coef'];
                            $moyenne_generale += $moy_ponderee;
                            $total_coef += $data['coef'];
                            $pourcentage = ($moy / 20) * 100;
                        ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="matiere-average">
                                <div class="matiere-average-header">
                                    <span class="matiere-name"><?php echo htmlspecialchars($matiere); ?></span>
                                    <span class="matiere-coef">Coef. <?php echo $data['coef']; ?></span>
                                </div>
                                <div class="matiere-average-body">
                                    <div class="matiere-score">
                                        <span class="matiere-score-value <?php echo $moy >= 10 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($moy, 2); ?>
                                        </span>
                                        <span class="matiere-score-max">/20</span>
                                    </div>
                                    <div class="matiere-progress">
                                        <div class="progress-bar-custom" style="width: <?php echo $pourcentage; ?>%; 
                                             background: <?php echo $moy >= 10 ? '#22c55e' : '#ef4444'; ?>;">
                                        </div>
                                    </div>
                                    <div class="matiere-details">
                                        <span class="matiere-notes-count">
                                            <i class="bi bi-pencil"></i> <?php echo $data['count']; ?> notes
                                        </span>
                                        <span class="matiere-ponderee">
                                            Pondérée: <?php echo number_format($moy_ponderee, 2); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Moyenne générale -->
                    <?php if($total_coef > 0): 
                        $moyenne_gen = $moyenne_generale / $total_coef;
                    ?>
                        <div class="general-average">
                            <div class="general-average-content">
                                <div class="general-average-left">
                                    <span class="general-average-label">Moyenne générale</span>
                                    <span class="general-average-sub">Toutes matières confondues</span>
                                </div>
                                <div class="general-average-right">
                                    <span class="general-average-value <?php echo $moyenne_gen >= 10 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($moyenne_gen, 2); ?>
                                    </span>
                                    <span class="general-average-max">/20</span>
                                </div>
                            </div>
                            <div class="general-average-progress">
                                <div class="progress-bar-custom" style="width: <?php echo ($moyenne_gen / 20) * 100; ?>%; 
                                     background: <?php echo $moyenne_gen >= 10 ? '#22c55e' : '#ef4444'; ?>;">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state-card">
            <div class="empty-state-content">
                <i class="bi bi-inbox"></i>
                <h5>Aucune note trouvée</h5>
                <p class="text-muted">Cet élève n'a pas encore de notes enregistrées</p>
                <a href="saisir.php?eleve_id=<?php echo $eleve_id; ?>" class="btn btn-dark">
                    <i class="bi bi-plus-circle"></i> Saisir une note
                </a>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- État initial - Aucun élève sélectionné -->
    <div class="empty-state-card">
        <div class="empty-state-content">
            <i class="bi bi-person-circle"></i>
            <h5>Sélectionnez un élève</h5>
            <p class="text-muted">Choisissez un élève dans le menu déroulant ci-dessus pour consulter ses notes</p>
            <span class="text-muted small">
                <i class="bi bi-arrow-up"></i> Utilisez le filtre en haut de la page
            </span>
        </div>
    </div>
<?php endif; ?>

<style>
/* ========================================
   PAGE DE CONSULTATION DES NOTES - STYLE PROFESSIONNEL
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

/* Filter Card */
.filter-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 24px;
    transition: all 0.2s ease;
}

.filter-card:hover {
    border-color: #cbd5e1;
}

.filter-card-body .input-group-text {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #94a3b8;
}

.filter-card-body .form-select {
    border: 1px solid #e2e8f0;
    border-radius: 0 8px 8px 0;
    padding: 10px 14px;
    font-size: 0.9rem;
}

.filter-card-body .form-select:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

.filter-card-body .form-label {
    font-weight: 500;
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 6px;
}

/* Student Summary Card */
.student-summary-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.student-summary-card:hover {
    border-color: #cbd5e1;
}

.student-summary-body {
    padding: 20px 24px;
}

.student-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #1a202c;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0 auto 10px auto;
    box-shadow: 0 2px 10px rgba(26, 32, 44, 0.1);
}

.student-name {
    text-align: center;
}

.student-name h4 {
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 2px;
}

.student-name .text-muted {
    font-size: 0.85rem;
}

/* Stat Mini */
.stat-mini {
    text-align: center;
    padding: 12px 10px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #f1f5f9;
    transition: all 0.2s ease;
}

.stat-mini:hover {
    background: #f1f5f9;
}

.stat-mini-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    margin-bottom: 2px;
}

.stat-mini-value {
    display: block;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1a202c;
}

/* Notes Table Card */
.notes-table-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.notes-table-card:hover {
    border-color: #cbd5e1;
}

.notes-table-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.notes-table-header .card-icon {
    font-size: 1rem;
    color: #64748b;
    margin-right: 8px;
}

.notes-table-header .card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #1a202c;
}

.notes-count-badge {
    font-size: 0.7rem;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 12px;
    border-radius: 50px;
    margin-left: 10px;
}

.notes-table-body {
    padding: 0;
}

.notes-table {
    margin-bottom: 0;
}

.notes-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 16px;
    border-bottom: 2px solid #e2e8f0;
    position: sticky;
    top: 0;
    z-index: 1;
}

.notes-table tbody td {
    padding: 12px 16px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}

.notes-table tbody tr {
    transition: background-color 0.15s ease;
}

.notes-table tbody tr:hover {
    background: #f8fafc;
}

/* Badges */
.score-badge {
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 50px;
    font-size: 0.85rem;
    display: inline-block;
}

.score-high {
    background: #dcfce7;
    color: #166534;
}

.score-low {
    background: #fee2e2;
    color: #991b1b;
}

.type-badge {
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 4px;
    background: #f1f5f9;
    color: #475569;
    display: inline-block;
}

.trimestre-badge {
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 4px;
    background: #eff6ff;
    color: #1e40af;
    display: inline-block;
}

/* Averages Card */
.averages-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.averages-card:hover {
    border-color: #cbd5e1;
}

.averages-card-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.averages-card-header .card-icon {
    font-size: 1rem;
    color: #64748b;
    margin-right: 8px;
}

.averages-card-header .card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #1a202c;
}

.averages-card-header .card-badge {
    font-size: 0.7rem;
    font-weight: 500;
    color: #64748b;
    background: #f1f5f9;
    padding: 4px 12px;
    border-radius: 50px;
}

.averages-card-body {
    padding: 20px 24px;
}

/* Matiere Average */
.matiere-average {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    transition: all 0.2s ease;
    height: 100%;
}

.matiere-average:hover {
    border-color: #cbd5e1;
    background: #f1f5f9;
    transform: translateY(-2px);
}

.matiere-average-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.matiere-name {
    font-weight: 600;
    color: #1a202c;
    font-size: 0.9rem;
}

.matiere-coef {
    font-size: 0.7rem;
    color: #94a3b8;
    background: white;
    padding: 2px 10px;
    border-radius: 4px;
}

.matiere-average-body {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.matiere-score {
    display: flex;
    align-items: baseline;
    gap: 2px;
}

.matiere-score-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.matiere-score-max {
    font-size: 0.8rem;
    color: #94a3b8;
}

.matiere-progress {
    width: 100%;
    height: 4px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar-custom {
    height: 100%;
    border-radius: 4px;
    transition: width 0.8s ease;
}

.matiere-details {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 4px;
}

.matiere-notes-count i {
    margin-right: 4px;
}

.matiere-ponderee {
    font-weight: 500;
    color: #64748b;
}

/* General Average */
.general-average {
    margin-top: 20px;
    padding: 16px 20px;
    background: #1a202c;
    border-radius: 10px;
    color: white;
    transition: all 0.3s ease;
}

.general-average:hover {
    box-shadow: 0 4px 20px rgba(26, 32, 44, 0.15);
}

.general-average-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.general-average-left {
    flex: 1;
}

.general-average-label {
    font-weight: 600;
    font-size: 1rem;
}

.general-average-sub {
    font-size: 0.75rem;
    opacity: 0.7;
    display: block;
}

.general-average-right {
    text-align: right;
    flex-shrink: 0;
}

.general-average-value {
    font-size: 1.8rem;
    font-weight: 700;
}

.general-average-max {
    font-size: 0.9rem;
    opacity: 0.7;
}

.general-average-progress {
    margin-top: 10px;
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    overflow: hidden;
}

/* Empty State */
.empty-state-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
}

.empty-state-content i {
    font-size: 3rem;
    color: #e2e8f0;
}

.empty-state-content h5 {
    margin: 16px 0 8px 0;
    color: #1a202c;
    font-weight: 600;
}

.empty-state-content p {
    font-size: 0.9rem;
    color: #64748b;
}

/* Responsive */
@media (max-width: 991.98px) {
    .page-title {
        font-size: 1.4rem;
    }
    
    .page-subtitle {
        margin-left: 0;
    }
    
    .student-summary-body .row > div:first-child {
        text-align: center;
        margin-bottom: 16px;
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
    
    .student-avatar {
        width: 50px;
        height: 50px;
        font-size: 1rem;
    }
    
    .matiere-average {
        padding: 12px;
    }
    
    .general-average-content {
        flex-direction: column;
        text-align: center;
    }
    
    .general-average-right {
        text-align: center;
        margin-top: 8px;
    }
    
    .general-average-value {
        font-size: 1.5rem;
    }
}

@media (max-width: 575.98px) {
    .notes-table thead th {
        font-size: 0.6rem;
        padding: 8px 10px;
    }
    
    .notes-table tbody td {
        font-size: 0.75rem;
        padding: 8px 10px;
    }
    
    .score-badge {
        font-size: 0.7rem;
        padding: 2px 8px;
    }
    
    .averages-card-body {
        padding: 12px 16px;
    }
    
    .stat-mini-value {
        font-size: 1rem;
    }
    
    .matiere-score-value {
        font-size: 1.2rem;
    }
}

/* Print styles */
@media print {
    .filter-card,
    .page-header .btn,
    .notes-table-header .btn,
    .no-print {
        display: none !important;
    }
    
    .student-summary-card,
    .notes-table-card,
    .averages-card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        break-inside: avoid;
    }
    
    .notes-table thead th {
        background: #f5f5f5 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .score-badge,
    .type-badge,
    .trimestre-badge {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .general-average {
        background: #1a202c !important;
        color: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .student-avatar {
        background: #1a202c !important;
        color: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>

<script>
// Fonction pour exporter en CSV
function exportToCSV() {
    const table = document.getElementById('notesTable');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    // En-têtes
    const headers = ['Matière', 'Note', 'Coefficient', 'Type', 'Trimestre', 'Enseignant', 'Date'];
    csv.push(headers.join(','));
    
    // Données
    rows.forEach(row => {
        if (row.querySelector('th')) return;
        const cols = row.querySelectorAll('td');
        const rowData = [];
        cols.forEach(col => {
            let text = col.textContent.trim();
            text = text.replace(/,/g, ';');
            rowData.push(text);
        });
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });
    
    // Télécharger
    const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'notes_eleve.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

// Soumission automatique du formulaire
document.addEventListener('DOMContentLoaded', function() {
    const eleveSelect = document.getElementById('eleve_id');
    const trimestreSelect = document.getElementById('trimestre');
    
    if (eleveSelect) {
        eleveSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }
    
    if (trimestreSelect) {
        trimestreSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>