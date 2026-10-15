<?php
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
$query = "SELECT m.*, 
          (SELECT COUNT(*) FROM enseigner WHERE id_matiere = m.id_matiere) as total_enseignants,
          (SELECT COUNT(*) FROM porter_sur WHERE id_matiere = m.id_matiere) as total_notes,
          (SELECT AVG(valeur) FROM note n 
           JOIN porter_sur ps ON n.id_note = ps.id_note 
           WHERE ps.id_matiere = m.id_matiere) as moyenne_notes,
          (SELECT MIN(valeur) FROM note n 
           JOIN porter_sur ps ON n.id_note = ps.id_note 
           WHERE ps.id_matiere = m.id_matiere) as note_min,
          (SELECT MAX(valeur) FROM note n 
           JOIN porter_sur ps ON n.id_note = ps.id_note 
           WHERE ps.id_matiere = m.id_matiere) as note_max
          FROM matiere m 
          WHERE m.id_matiere = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id_matiere);
$stmt->execute();
$matiere = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$matiere) {
    header('Location: index.php');
    exit;
}

// Récupérer les enseignants de cette matière
$query_enseignants = "SELECT e.* 
                      FROM enseignant e 
                      JOIN enseigner en ON e.id_enseignant = en.id_enseignant 
                      WHERE en.id_matiere = :id_matiere
                      ORDER BY e.nom, e.prenom";
$stmt_enseignants = $db->prepare($query_enseignants);
$stmt_enseignants->bindParam(':id_matiere', $id_matiere);
$stmt_enseignants->execute();
$enseignants = $stmt_enseignants->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les élèves ayant des notes dans cette matière
$query_eleves = "SELECT DISTINCT e.*, 
                 (SELECT AVG(n.valeur) FROM note n 
                  JOIN obtenir o ON n.id_note = o.id_note 
                  JOIN porter_sur ps ON n.id_note = ps.id_note 
                  WHERE o.id_eleve = e.id_eleve AND ps.id_matiere = :id_matiere) as moyenne_matiere
                 FROM eleve e
                 JOIN obtenir o ON e.id_eleve = o.id_eleve
                 JOIN porter_sur ps ON o.id_note = ps.id_note
                 WHERE ps.id_matiere = :id_matiere
                 ORDER BY moyenne_matiere DESC";
$stmt_eleves = $db->prepare($query_eleves);
$stmt_eleves->bindParam(':id_matiere', $id_matiere);
$stmt_eleves->execute();
$eleves = $stmt_eleves->fetchAll(PDO::FETCH_ASSOC);

// Statistiques supplémentaires
$total_eleves_notes = count($eleves);
$moyenne_generale = $matiere['moyenne_notes'] ? round($matiere['moyenne_notes'], 2) : 0;
$note_min = $matiere['note_min'] ?? 0;
$note_max = $matiere['note_max'] ?? 0;
$total_enseignants = $matiere['total_enseignants'] ?? 0;
$total_notes = $matiere['total_notes'] ?? 0;

// Calcul du pourcentage de réussite (notes >= 10)
$pourcentage_reussite = 0;
if($total_notes > 0) {
    $query_reussite = "SELECT COUNT(*) as reussite FROM note n 
                       JOIN porter_sur ps ON n.id_note = ps.id_note 
                       WHERE ps.id_matiere = :id_matiere AND n.valeur >= 10";
    $stmt_reussite = $db->prepare($query_reussite);
    $stmt_reussite->bindParam(':id_matiere', $id_matiere);
    $stmt_reussite->execute();
    $reussite = $stmt_reussite->fetch(PDO::FETCH_ASSOC);
    $pourcentage_reussite = round(($reussite['reussite'] / $total_notes) * 100);
}

// Récupérer la répartition des notes
$repartition = [
    '0-5' => 0, 
    '5-10' => 0, 
    '10-15' => 0, 
    '15-20' => 0
];

$query_repartition = "SELECT valeur FROM note n 
                      JOIN porter_sur ps ON n.id_note = ps.id_note 
                      WHERE ps.id_matiere = :id_matiere";
$stmt_repartition = $db->prepare($query_repartition);
$stmt_repartition->bindParam(':id_matiere', $id_matiere);
$stmt_repartition->execute();
$notes_repartition = $stmt_repartition->fetchAll(PDO::FETCH_ASSOC);

foreach($notes_repartition as $note) {
    $v = $note['valeur'];
    if($v < 5) $repartition['0-5']++;
    elseif($v < 10) $repartition['5-10']++;
    elseif($v < 15) $repartition['10-15']++;
    else $repartition['15-20']++;
}

$max_repartition = max($repartition);

// Définir les couleurs pour chaque tranche
$repartition_colors = [
    '0-5' => '#ef4444',
    '5-10' => '#f59e0b',
    '10-15' => '#3b82f6',
    '15-20' => '#22c55e'
];
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon" style="background: linear-gradient(135deg, #4F46E5, #7C3AED);">
                    <i class="bi bi-book-fill"></i>
                </span>
                <?php echo htmlspecialchars($matiere['nom_matiere']); ?>
            </h1>
            <p class="page-subtitle text-muted">
                <i class="bi bi-star-fill text-warning"></i>
                Coefficient: <?php echo $matiere['coef']; ?> &middot;
                <i class="bi bi-hash"></i> ID: #<?php echo $matiere['id_matiere']; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="modifier.php?id=<?php echo $id_matiere; ?>" class="btn btn-dark">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
</div>

<!-- Quick Stats -->
<div class="quick-stats-grid mb-4">
    <div class="quick-stat">
        <div class="quick-stat-icon">
            <i class="bi bi-people-fill"></i>
        </div>
        <div class="quick-stat-content">
            <span class="quick-stat-value"><?php echo $total_enseignants; ?></span>
            <span class="quick-stat-label">Enseignants</span>
        </div>
    </div>
    <div class="quick-stat">
        <div class="quick-stat-icon" style="background: #dbeafe; color: #2563eb;">
            <i class="bi bi-pencil-fill"></i>
        </div>
        <div class="quick-stat-content">
            <span class="quick-stat-value"><?php echo $total_notes; ?></span>
            <span class="quick-stat-label">Total notes</span>
        </div>
    </div>
    <div class="quick-stat">
        <div class="quick-stat-icon" style="background: #dcfce7; color: #16a34a;">
            <i class="bi bi-graph-up-arrow"></i>
        </div>
        <div class="quick-stat-content">
            <span class="quick-stat-value <?php echo $moyenne_generale >= 10 ? 'text-success' : 'text-danger'; ?>">
                <?php echo $moyenne_generale; ?>
            </span>
            <span class="quick-stat-label">Moyenne générale</span>
        </div>
    </div>
    <div class="quick-stat">
        <div class="quick-stat-icon" style="background: #fef3c7; color: #d97706;">
            <i class="bi bi-trophy-fill"></i>
        </div>
        <div class="quick-stat-content">
            <span class="quick-stat-value"><?php echo $pourcentage_reussite; ?>%</span>
            <span class="quick-stat-label">Taux de réussite</span>
        </div>
    </div>
</div>

<!-- Informations de la matière -->
<div class="row g-4">
    <!-- Colonne principale -->
    <div class="col-lg-8">
        <!-- Statistiques des notes -->
        <div class="info-card">
            <div class="info-card-header">
                <div class="d-flex align-items-center">
                    <span class="info-card-icon">
                        <i class="bi bi-graph-up"></i>
                    </span>
                    <span class="info-card-title">Statistiques des notes</span>
                </div>
                <span class="card-badge">Dernières données</span>
            </div>
            <div class="info-card-body">
                <div class="row g-3">
                    <div class="col-md-3 col-6">
                        <div class="stat-mini">
                            <span class="stat-mini-label">Total notes</span>
                            <span class="stat-mini-value"><?php echo $total_notes; ?></span>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-mini">
                            <span class="stat-mini-label">Moyenne</span>
                            <span class="stat-mini-value <?php echo $moyenne_generale >= 10 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $moyenne_generale; ?>/20
                            </span>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-mini">
                            <span class="stat-mini-label">Note max</span>
                            <span class="stat-mini-value text-success"><?php echo $note_max; ?>/20</span>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-mini">
                            <span class="stat-mini-label">Note min</span>
                            <span class="stat-mini-value text-danger"><?php echo $note_min; ?>/20</span>
                        </div>
                    </div>
                </div>
                
                <!-- Barre de progression de réussite -->
                <div class="success-rate mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">Taux de réussite (notes ≥ 10)</span>
                        <span class="fw-bold <?php echo $pourcentage_reussite >= 50 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $pourcentage_reussite; ?>%
                        </span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar <?php echo $pourcentage_reussite >= 50 ? 'bg-success' : 'bg-danger'; ?>" 
                             role="progressbar" 
                             style="width: <?php echo $pourcentage_reussite; ?>%;" 
                             aria-valuenow="<?php echo $pourcentage_reussite; ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Répartition des notes -->
        <div class="info-card mt-4">
            <div class="info-card-header">
                <div class="d-flex align-items-center">
                    <span class="info-card-icon">
                        <i class="bi bi-bar-chart-fill"></i>
                    </span>
                    <span class="info-card-title">Répartition des notes</span>
                </div>
            </div>
            <div class="info-card-body">
                <div class="repartition-bars">
                    <?php foreach($repartition as $tranche => $count): 
                        $percentage = $max_repartition > 0 ? ($count / $max_repartition) * 100 : 0;
                        $color = $repartition_colors[$tranche] ?? '#6b7280';
                    ?>
                    <div class="repartition-item">
                        <span class="repartition-label"><?php echo $tranche; ?></span>
                        <div class="repartition-bar-track">
                            <div class="repartition-bar-fill" style="width: <?php echo max(5, $percentage); ?>%; background: <?php echo $color; ?>;">
                                <span class="repartition-count"><?php echo $count; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Liste des élèves -->
        <?php if(count($eleves) > 0): ?>
        <div class="info-card mt-4">
            <div class="info-card-header">
                <div class="d-flex align-items-center">
                    <span class="info-card-icon">
                        <i class="bi bi-people-fill"></i>
                    </span>
                    <span class="info-card-title">Élèves ayant des notes</span>
                    <span class="card-badge ms-2"><?php echo $total_eleves_notes; ?></span>
                </div>
                <div>
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportElevesCSV()">
                        <i class="bi bi-download"></i> Exporter
                    </button>
                </div>
            </div>
            <div class="info-card-body">
                <div class="table-responsive">
                    <table class="table eleves-table" id="elevesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Moyenne</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($eleves as $index => $eleve): 
                                $moyenne = $eleve['moyenne_matiere'] ? round($eleve['moyenne_matiere'], 2) : '-';
                                $statut = is_numeric($moyenne) ? ($moyenne >= 10 ? 'Réussi' : 'En difficulté') : '-';
                                $statut_class = is_numeric($moyenne) ? ($moyenne >= 10 ? 'statut-success' : 'statut-danger') : '';
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($eleve['nom']); ?></td>
                                <td><?php echo htmlspecialchars($eleve['prenom']); ?></td>
                                <td>
                                    <span class="moyenne-badge <?php echo is_numeric($moyenne) && $moyenne >= 10 ? 'moyenne-good' : 'moyenne-bad'; ?>">
                                        <?php echo $moyenne; ?>/20
                                    </span>
                                </td>
                                <td>
                                    <span class="statut-badge <?php echo $statut_class; ?>">
                                        <?php echo $statut; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="../eleves/voir.php?id=<?php echo $eleve['id_eleve']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Voir l'élève">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="../notes/consulter.php?eleve_id=<?php echo $eleve['id_eleve']; ?>" 
                                           class="btn btn-sm btn-outline-secondary" title="Voir les notes">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="info-card mt-4">
            <div class="info-card-body text-center py-4">
                <i class="bi bi-people display-1 text-muted"></i>
                <h6 class="mt-3">Aucun élève avec des notes</h6>
                <p class="text-muted">Aucune note n'a été enregistrée pour cette matière</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Colonne latérale -->
    <div class="col-lg-4">
        <!-- Informations générales -->
        <div class="sidebar-card">
            <div class="sidebar-card-header">
                <i class="bi bi-info-circle"></i>
                <span>Informations</span>
            </div>
            <div class="sidebar-card-body">
                <div class="info-row">
                    <span class="info-row-label">
                        <i class="bi bi-tag"></i> Nom
                    </span>
                    <span class="info-row-value"><?php echo htmlspecialchars($matiere['nom_matiere']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-row-label">
                        <i class="bi bi-star"></i> Coefficient
                    </span>
                    <span class="info-row-value">
                        <span class="coef-badge"><?php echo $matiere['coef']; ?></span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-row-label">
                        <i class="bi bi-hash"></i> ID
                    </span>
                    <span class="info-row-value">#<?php echo $matiere['id_matiere']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-row-label">
                        <i class="bi bi-calendar3"></i> Création
                    </span>
                    <span class="info-row-value"><?php echo date('d/m/Y H:i', strtotime($matiere['created_at'] ?? 'now')); ?></span>
                </div>
            </div>
        </div>

        <!-- Liste des enseignants -->
        <div class="sidebar-card mt-3">
            <div class="sidebar-card-header">
                <i class="bi bi-person-badge"></i>
                <span>Enseignants</span>
                <span class="card-badge ms-auto"><?php echo $total_enseignants; ?></span>
            </div>
            <div class="sidebar-card-body">
                <?php if(count($enseignants) > 0): ?>
                    <?php foreach($enseignants as $enseignant): ?>
                        <div class="enseignant-item">
                            <div class="enseignant-avatar" style="background: linear-gradient(135deg, #4F46E5, #7C3AED);">
                                <?php echo strtoupper(substr($enseignant['prenom'], 0, 1) . substr($enseignant['nom'], 0, 1)); ?>
                            </div>
                            <div class="enseignant-info">
                                <div class="enseignant-name">
                                    <?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?>
                                </div>
                                <div class="enseignant-grade">
                                    <?php echo htmlspecialchars($enseignant['grade'] ?? 'Enseignant'); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state-mini">
                        <i class="bi bi-person-x"></i>
                        <p>Aucun enseignant affecté</p>
                        <a href="modifier.php?id=<?php echo $id_matiere; ?>" class="btn btn-dark btn-sm">
                            <i class="bi bi-plus-circle"></i> Ajouter
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="sidebar-card mt-3">
            <div class="sidebar-card-header" style="background: linear-gradient(135deg, #1a202c, #2d3748); color: white;">
                <i class="bi bi-lightning-fill"></i>
                <span style="color: white;">Actions rapides</span>
            </div>
            <div class="sidebar-card-body">
                <div class="d-grid gap-2">
                    <a href="modifier.php?id=<?php echo $id_matiere; ?>" class="btn btn-dark">
                        <i class="bi bi-pencil"></i> Modifier la matière
                    </a>
                    <a href="../notes/index.php?matiere=<?php echo $id_matiere; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-pencil"></i> Voir toutes les notes
                    </a>
                    <form method="post" action="supprimer.php" class="d-inline m-0"
                          onsubmit="return confirm('Confirmer la suppression de cette matière ?')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $id_matiere; ?>">
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-trash"></i> Supprimer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   PAGE DE DETAIL D'UNE MATIERE - AMELIOREE
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
    color: white;
    border-radius: 12px;
    font-size: 1.2rem;
}

.page-subtitle {
    font-size: 0.95rem;
    color: #64748b;
    margin: 6px 0 0 56px;
}

/* Quick Stats */
.quick-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

.quick-stat {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.2s ease;
}

.quick-stat:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
}

.quick-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: #64748b;
    flex-shrink: 0;
}

.quick-stat-content {
    flex: 1;
    min-width: 0;
}

.quick-stat-value {
    display: block;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1a202c;
}

.quick-stat-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
}

/* Info Card */
.info-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.info-card-header {
    padding: 14px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.info-card-icon {
    font-size: 1rem;
    color: #64748b;
    margin-right: 8px;
}

.info-card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #1a202c;
}

.card-badge {
    font-size: 0.7rem;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 12px;
    border-radius: 50px;
}

.info-card-body {
    padding: 20px;
}

/* Stat Mini */
.stat-mini {
    text-align: center;
    padding: 12px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #f1f5f9;
}

.stat-mini-label {
    display: block;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    margin-bottom: 4px;
}

.stat-mini-value {
    display: block;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1a202c;
}

/* Success Rate */
.success-rate {
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}

/* Repartition */
.repartition-bars {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.repartition-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.repartition-label {
    font-size: 0.8rem;
    color: #475569;
    min-width: 45px;
    flex-shrink: 0;
}

.repartition-bar-track {
    flex: 1;
    height: 24px;
    background: #f1f5f9;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.repartition-bar-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.8s ease;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 6px;
    min-width: 20px;
}

.repartition-count {
    font-size: 0.65rem;
    font-weight: 600;
    color: white;
}

/* Eleves Table */
.eleves-table {
    margin-bottom: 0;
}

.eleves-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    border-bottom: 2px solid #e2e8f0;
}

.eleves-table tbody td {
    padding: 10px 14px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}

.eleves-table tbody tr:hover {
    background: #f8fafc;
}

.moyenne-badge {
    padding: 3px 12px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.85rem;
}

.moyenne-good {
    background: #dcfce7;
    color: #166534;
}

.moyenne-bad {
    background: #fee2e2;
    color: #991b1b;
}

.statut-badge {
    padding: 2px 10px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 500;
}

.statut-success {
    background: #dcfce7;
    color: #166534;
}

.statut-danger {
    background: #fee2e2;
    color: #991b1b;
}

/* Sidebar Card */
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

/* Info Row */
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid #f1f5f9;
}

.info-row:last-child {
    border-bottom: none;
}

.info-row-label {
    font-size: 0.8rem;
    color: #94a3b8;
}

.info-row-label i {
    margin-right: 6px;
}

.info-row-value {
    font-weight: 500;
    color: #1a202c;
    font-size: 0.85rem;
}

.coef-badge {
    display: inline-block;
    padding: 2px 12px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 4px;
    font-weight: 700;
    font-size: 0.85rem;
}

/* Enseignant Item */
.enseignant-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
}

.enseignant-item:last-child {
    border-bottom: none;
}

.enseignant-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
}

.enseignant-info {
    flex: 1;
    min-width: 0;
}

.enseignant-name {
    font-weight: 600;
    font-size: 0.85rem;
    color: #1a202c;
}

.enseignant-grade {
    font-size: 0.75rem;
    color: #94a3b8;
}

/* Empty State Mini */
.empty-state-mini {
    text-align: center;
    padding: 20px 10px;
}

.empty-state-mini i {
    font-size: 2rem;
    color: #e2e8f0;
}

.empty-state-mini p {
    font-size: 0.85rem;
    color: #94a3b8;
    margin: 8px 0 12px 0;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 4px;
}

.action-buttons .btn {
    width: 30px;
    height: 30px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-size: 0.8rem;
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
    
    .quick-stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .info-card-body {
        padding: 16px;
    }
    
    .stat-mini-value {
        font-size: 1rem;
    }
    
    .enseignant-item {
        padding: 8px 0;
    }
    
    .repartition-item {
        gap: 6px;
    }
    
    .repartition-label {
        min-width: 35px;
        font-size: 0.7rem;
    }
}

@media (max-width: 575.98px) {
    .quick-stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .quick-stat {
        padding: 10px 12px;
    }
    
    .quick-stat-value {
        font-size: 1rem;
    }
    
    .stat-mini {
        padding: 8px;
    }
    
    .stat-mini-value {
        font-size: 0.9rem;
    }
    
    .eleves-table thead th {
        font-size: 0.6rem;
        padding: 6px 8px;
    }
    
    .eleves-table tbody td {
        font-size: 0.75rem;
        padding: 6px 8px;
    }
    
    .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
    }
}
</style>

<script>
// Fonction pour exporter les élèves en CSV
function exportElevesCSV() {
    const table = document.getElementById('elevesTable');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    // En-têtes
    const headers = ['#', 'Nom', 'Prénom', 'Moyenne', 'Statut'];
    csv.push(headers.join(','));
    
    // Données
    rows.forEach(row => {
        if (row.querySelector('th')) return;
        const cols = row.querySelectorAll('td');
        const rowData = [];
        cols.forEach((col, index) => {
            if (index === 5) return;
            let text = col.textContent.trim();
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
    link.download = 'eleves_matiere_<?php echo $id_matiere; ?>.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<?php require_once '../../includes/footer.php'; ?>