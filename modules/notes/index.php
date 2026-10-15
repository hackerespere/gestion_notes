<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$matiere_filter = isset($_GET['matiere']) ? $_GET['matiere'] : '';
$trimestre_filter = isset($_GET['trimestre']) ? $_GET['trimestre'] : '';

// Récupérer les matières pour le filtre
$matieres = $db->query("SELECT id_matiere, nom_matiere FROM matiere ORDER BY nom_matiere")->fetchAll();

// Récupérer les trimestres pour le filtre
$trimestres = $db->query("SELECT id_trimestre, libelle FROM trimestre ORDER BY id_trimestre")->fetchAll();

// Construction de la requête pour récupérer toutes les notes avec pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Requête principale avec toutes les jointures nécessaires
$sql = "SELECT n.*, 
               e.id_eleve,
               e.nom as eleve_nom, 
               e.prenom as eleve_prenom,
               m.id_matiere,
               m.nom_matiere,
               t.id_trimestre,
               t.libelle as trimestre,
               ens.id_enseignant,
               ens.nom as enseignant_nom, 
               ens.prenom as enseignant_prenom
        FROM note n
        INNER JOIN obtenir o ON n.id_note = o.id_note
        INNER JOIN eleve e ON o.id_eleve = e.id_eleve
        INNER JOIN porter_sur ps ON n.id_note = ps.id_note
        INNER JOIN matiere m ON ps.id_matiere = m.id_matiere
        INNER JOIN durant d ON n.id_note = d.id_note
        INNER JOIN trimestre t ON d.id_trimestre = t.id_trimestre
        INNER JOIN saisir s ON n.id_note = s.id_note
        INNER JOIN enseignant ens ON s.id_enseignant = ens.id_enseignant
        WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total 
              FROM note n
              INNER JOIN obtenir o ON n.id_note = o.id_note
              INNER JOIN eleve e ON o.id_eleve = e.id_eleve
              INNER JOIN porter_sur ps ON n.id_note = ps.id_note
              INNER JOIN matiere m ON ps.id_matiere = m.id_matiere
              INNER JOIN durant d ON n.id_note = d.id_note
              INNER JOIN trimestre t ON d.id_trimestre = t.id_trimestre
              WHERE 1=1";

$params = [];

if($search) {
    $sql .= " AND (e.nom LIKE :search OR e.prenom LIKE :search OR m.nom_matiere LIKE :search)";
    $count_sql .= " AND (e.nom LIKE :search OR e.prenom LIKE :search OR m.nom_matiere LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if($matiere_filter) {
    $sql .= " AND m.id_matiere = :matiere";
    $count_sql .= " AND m.id_matiere = :matiere";
    $params[':matiere'] = $matiere_filter;
}

if($trimestre_filter) {
    $sql .= " AND t.id_trimestre = :trimestre";
    $count_sql .= " AND t.id_trimestre = :trimestre";
    $params[':trimestre'] = $trimestre_filter;
}

$sql .= " ORDER BY n.date_note DESC LIMIT :offset, :per_page";

// Exécuter la requête de comptage
$count_stmt = $db->prepare($count_sql);
foreach($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_notes = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_notes / $per_page);

// Exécuter la requête principale
$stmt = $db->prepare($sql);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques globales
$stats = [
    'total' => $total_notes,
    'moyenne_globale' => 0,
    'max' => 0,
    'min' => 20
];

if($total_notes > 0) {
    $stats_query = "SELECT AVG(n.valeur) as moyenne, MAX(n.valeur) as max, MIN(n.valeur) as min 
                    FROM note n";
    
    $stats_params = [];
    
    if($matiere_filter || $trimestre_filter) {
        $stats_query .= " WHERE 1=1";
        if($matiere_filter) {
            $stats_query .= " AND n.id_note IN (SELECT id_note FROM porter_sur WHERE id_matiere = :matiere)";
            $stats_params[':matiere'] = $matiere_filter;
        }
        if($trimestre_filter) {
            $stats_query .= " AND n.id_note IN (SELECT id_note FROM durant WHERE id_trimestre = :trimestre)";
            $stats_params[':trimestre'] = $trimestre_filter;
        }
    }
    
    $stats_stmt = $db->prepare($stats_query);
    foreach($stats_params as $key => $value) {
        $stats_stmt->bindValue($key, $value);
    }
    $stats_stmt->execute();
    $stats_data = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    $stats['moyenne_globale'] = round($stats_data['moyenne'] ?? 0, 2);
    $stats['max'] = round($stats_data['max'] ?? 0, 2);
    $stats['min'] = round($stats_data['min'] ?? 0, 2);
}
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-grid"></i>
                </span>
                Gestion des notes
            </h1>
            <p class="page-subtitle text-muted">
                Gérez et consultez toutes les notes enregistrées
            </p>
        </div>
        <div>
            <a href="saisir.php" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Saisir une note
            </a>
        </div>
    </div>
</div>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon">
                <i class="bi bi-pencil-fill"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Total notes</span>
                <span class="stat-mini-value"><?php echo $stats['total']; ?></span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon">
                <i class="bi bi-graph-up"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Moyenne</span>
                <span class="stat-mini-value"><?php echo $stats['moyenne_globale']; ?>/20</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon text-success">
                <i class="bi bi-arrow-up-circle"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Meilleure</span>
                <span class="stat-mini-value text-success"><?php echo $stats['max']; ?>/20</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-mini-card">
            <div class="stat-mini-icon text-danger">
                <i class="bi bi-arrow-down-circle"></i>
            </div>
            <div class="stat-mini-info">
                <span class="stat-mini-label">Moins bonne</span>
                <span class="stat-mini-value text-danger"><?php echo $stats['min']; ?>/20</span>
            </div>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="filter-card mb-4">
    <div class="filter-card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label">Rechercher</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Élève ou matière..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label for="matiere" class="form-label">Matière</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-book"></i></span>
                    <select class="form-select" id="matiere" name="matiere">
                        <option value="">Toutes</option>
                        <?php foreach($matieres as $matiere): ?>
                            <option value="<?php echo $matiere['id_matiere']; ?>" 
                                    <?php echo ($matiere_filter == $matiere['id_matiere']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($matiere['nom_matiere']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <label for="trimestre" class="form-label">Trimestre</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar-range"></i></span>
                    <select class="form-select" id="trimestre" name="trimestre">
                        <option value="">Tous</option>
                        <?php foreach($trimestres as $trimestre): ?>
                            <option value="<?php echo $trimestre['id_trimestre']; ?>" 
                                    <?php echo ($trimestre_filter == $trimestre['id_trimestre']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($trimestre['libelle']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-dark w-100">
                    <i class="bi bi-funnel"></i> Filtrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tableau des notes -->
<div class="notes-table-card">
    <div class="notes-table-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <span class="card-icon"><i class="bi bi-table"></i></span>
                <span class="card-title">Liste des notes</span>
                <span class="notes-count-badge"><?php echo $total_notes; ?> notes</span>
            </div>
            <div>
                <?php if($search || $matiere_filter || $trimestre_filter): ?>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="notes-table-body">
        <?php if(count($notes) > 0): ?>
            <div class="table-responsive">
                <table class="table notes-table">
                    <thead>
                        <tr>
                            <th>Élève</th>
                            <th>Matière</th>
                            <th>Note</th>
                            <th>Type</th>
                            <th>Trimestre</th>
                            <th>Enseignant</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($notes as $note): ?>
                        <tr>
                            <td>
                                <a href="consulter.php?eleve_id=<?php echo $note['id_eleve']; ?>" class="student-link">
                                    <?php echo htmlspecialchars($note['eleve_prenom'] . ' ' . $note['eleve_nom']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($note['nom_matiere']); ?></td>
                            <td>
                                <span class="score-badge <?php echo $note['valeur'] >= 10 ? 'score-high' : 'score-low'; ?>">
                                    <?php echo $note['valeur']; ?>/20
                                </span>
                            </td>
                            <td>
                                <span class="type-badge"><?php echo htmlspecialchars($note['type_note']); ?></span>
                            </td>
                            <td>
                                <span class="trimestre-badge"><?php echo htmlspecialchars($note['trimestre']); ?></span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($note['enseignant_prenom'] . ' ' . $note['enseignant_nom']); ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($note['date_note'])); ?></td>
                            <td>
                                <form method="post" action="supprimer.php" class="d-inline m-0"
                                      onsubmit="return confirm('Confirmer la suppression de cette note ?')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $note['id_note']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav aria-label="Pagination">
                        <ul class="pagination">
                            <?php if($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&matiere=<?php echo $matiere_filter; ?>&trimestre=<?php echo $trimestre_filter; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&matiere=<?php echo $matiere_filter; ?>&trimestre=<?php echo $trimestre_filter; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&matiere=<?php echo $matiere_filter; ?>&trimestre=<?php echo $trimestre_filter; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h6>Aucune note trouvée</h6>
                <p class="text-muted"><?php echo $search || $matiere_filter || $trimestre_filter ? 'Aucun résultat pour les filtres sélectionnés' : 'Commencez par saisir une note'; ?></p>
                <?php if($search || $matiere_filter || $trimestre_filter): ?>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser les filtres
                    </a>
                <?php else: ?>
                    <a href="saisir.php" class="btn btn-dark btn-sm">
                        <i class="bi bi-plus-circle"></i> Saisir une note
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ========================================
   PAGE DE GESTION DES NOTES
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

/* Filter Card */
.filter-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 24px;
}

.filter-card-body .input-group-text {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #94a3b8;
}

.filter-card-body .form-control,
.filter-card-body .form-select {
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
}

.filter-card-body .form-control:focus,
.filter-card-body .form-select:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

/* Notes Table */
.notes-table-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
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
}

.notes-table tbody td {
    padding: 12px 16px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}

.notes-table tbody tr:hover {
    background: #f8fafc;
}

.student-link {
    color: #1a202c;
    text-decoration: none;
    font-weight: 500;
}

.student-link:hover {
    color: #4F46E5;
    text-decoration: underline;
}

/* Badges */
.score-badge {
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 50px;
    font-size: 0.85rem;
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
}

.trimestre-badge {
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 4px;
    background: #eff6ff;
    color: #1e40af;
}

/* Pagination */
.pagination-container {
    padding: 16px 20px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: center;
}

.pagination .page-link {
    color: #475569;
    border-color: #e2e8f0;
    padding: 8px 14px;
    font-size: 0.85rem;
}

.pagination .page-link:hover {
    background: #f8fafc;
    color: #1a202c;
}

.pagination .page-item.active .page-link {
    background: #1a202c;
    border-color: #1a202c;
    color: white;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 3rem;
    color: #e2e8f0;
}

.empty-state h6 {
    margin: 16px 0 8px 0;
    color: #1a202c;
    font-weight: 600;
}

.empty-state p {
    font-size: 0.9rem;
    margin-bottom: 12px;
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
    
    .stat-mini-card {
        padding: 10px 12px;
    }
    
    .stat-mini-value {
        font-size: 1rem;
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
    
    .type-badge,
    .trimestre-badge {
        font-size: 0.65rem;
        padding: 2px 6px;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>