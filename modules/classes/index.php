<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$niveau_filter = isset($_GET['niveau']) ? $_GET['niveau'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;

// Limites pour la pagination
$per_page_options = [10, 20, 50, 100];
if(!in_array($per_page, $per_page_options)) {
    $per_page = 20;
}

$offset = ($page - 1) * $per_page;

// Récupérer les niveaux pour le filtre
$niveaux = $db->query("SELECT DISTINCT niveau FROM classe WHERE niveau IS NOT NULL AND niveau != '' ORDER BY niveau")->fetchAll();

// Construction de la requête COUNT
$count_sql = "SELECT COUNT(*) as total FROM classe WHERE 1=1";
$params = [];

if($search) {
    $count_sql .= " AND (nom_classe LIKE :search OR niveau LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if($niveau_filter) {
    $count_sql .= " AND niveau = :niveau";
    $params[':niveau'] = $niveau_filter;
}

// Exécuter la requête COUNT
$count_stmt = $db->prepare($count_sql);
foreach($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_classes = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_classes / $per_page);

// Construction de la requête principale
$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM eleve WHERE id_classe = c.id_classe) as total_eleves
        FROM classe c
        WHERE 1=1";

if($search) {
    $sql .= " AND (c.nom_classe LIKE :search OR c.niveau LIKE :search)";
}

if($niveau_filter) {
    $sql .= " AND c.niveau = :niveau";
}

$sql .= " ORDER BY c.nom_classe LIMIT :offset, :per_page";

// Exécuter la requête principale
$stmt = $db->prepare($sql);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques globales
$stats_query = "SELECT COUNT(*) as total, 
                COUNT(DISTINCT niveau) as niveaux,
                SUM((SELECT COUNT(*) FROM eleve WHERE id_classe = classe.id_classe)) as total_eleves
                FROM classe";
$stats_result = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

$total_classes_stats = $stats_result['total'] ?? 0;
$total_niveaux = $stats_result['niveaux'] ?? 0;
$total_eleves = $stats_result['total_eleves'] ?? 0;

// Fonction pour construire l'URL avec les paramètres
function buildUrl($page, $per_page = null) {
    $params = $_GET;
    $params['page'] = $page;
    if($per_page !== null) {
        $params['per_page'] = $per_page;
    }
    return '?' . http_build_query($params);
}
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-building-fill"></i>
                </span>
                Gestion des classes
            </h1>
            <p class="page-subtitle text-muted">
                Gérez et consultez toutes les classes
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="ajouter.php" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Ajouter une classe
            </a>
        </div>
    </div>
</div>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="bi bi-building-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_classes_stats; ?></span>
                <span class="stat-label">Total classes</span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="stat-card">
            <div class="stat-icon text-primary">
                <i class="bi bi-layers-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_niveaux; ?></span>
                <span class="stat-label">Niveaux</span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="stat-card">
            <div class="stat-icon text-success">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_eleves; ?></span>
                <span class="stat-label">Élèves inscrits</span>
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
                           placeholder="Nom de la classe..." value="<?php echo htmlspecialchars($search); ?>">
                    <?php if($search): ?>
                        <a href="index.php" class="input-group-text bg-transparent border-0 text-muted" 
                           style="margin-left: -30px; z-index: 5;">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label for="niveau" class="form-label">Niveau</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-layers"></i></span>
                    <select class="form-select" id="niveau" name="niveau">
                        <option value="">Tous</option>
                        <?php foreach($niveaux as $niveau): ?>
                            <option value="<?php echo htmlspecialchars($niveau['niveau']); ?>" 
                                    <?php echo ($niveau_filter == $niveau['niveau']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($niveau['niveau']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <label for="per_page" class="form-label">Afficher</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-list-ul"></i></span>
                    <select class="form-select" id="per_page" name="per_page" onchange="this.form.submit()">
                        <?php foreach($per_page_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo ($per_page == $option) ? 'selected' : ''; ?>>
                                <?php echo $option; ?>
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
        <?php if($search || $niveau_filter): ?>
            <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                </a>
                <span class="text-muted small">
                    <i class="bi bi-info-circle"></i>
                    <?php echo $total_classes; ?> résultat(s) trouvé(s)
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tableau des classes -->
<div class="classes-table-card">
    <div class="classes-table-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="card-icon"><i class="bi bi-table"></i></span>
                <span class="card-title">Liste des classes</span>
                <span class="classes-count-badge"><?php echo $total_classes; ?> classes</span>
                <?php if($total_classes > 0): ?>
                    <span class="classes-count-badge text-muted">
                        Page <?php echo $page; ?> sur <?php echo $total_pages; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()" title="Imprimer">
                    <i class="bi bi-printer"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="exportToCSV()" title="Exporter CSV">
                    <i class="bi bi-download"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="classes-table-body">
        <?php if(count($classes) > 0): ?>
            <div class="table-responsive">
                <table class="table classes-table" id="classesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nom de la classe</th>
                            <th>Niveau</th>
                            <th>Année scolaire</th>
                            <th>Élèves</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($classes as $index => $classe): 
                            $row_number = $offset + $index + 1;
                        ?>
                        <tr>
                            <td>
                                <span class="row-number"><?php echo $row_number; ?></span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($classe['nom_classe']); ?></strong>
                            </td>
                            <td>
                                <span class="niveau-badge">
                                    <?php echo htmlspecialchars($classe['niveau'] ?? 'Non défini'); ?>
                                </span>
                            </td>
                            <td>
                                <i class="bi bi-calendar3 text-muted"></i>
                                <?php echo htmlspecialchars($classe['anne_scolaire'] ?? 'Non définie'); ?>
                            </td>
                            <td>
                                <span class="eleves-count">
                                    <i class="bi bi-people"></i>
                                    <?php echo $classe['total_eleves'] ?? 0; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="modifier.php?id=<?php echo $classe['id_classe']; ?>" 
                                       class="btn btn-sm btn-outline-secondary" title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="voir.php?id=<?php echo $classe['id_classe']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Voir les détails">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="../eleves/index.php?classe=<?php echo $classe['id_classe']; ?>" 
                                       class="btn btn-sm btn-outline-success" title="Voir les élèves">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <a href="supprimer.php?id=<?php echo $classe['id_classe']; ?>" 
                                       class="btn btn-sm btn-outline-danger" title="Supprimer"
                                       onclick="return confirm('Confirmer la suppression de cette classe ?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        <span class="text-muted small">
                            Affichage de <?php echo $offset + 1; ?> à <?php echo min($offset + $per_page, $total_classes); ?> 
                            sur <?php echo $total_classes; ?> classes
                        </span>
                    </div>
                    <nav aria-label="Pagination">
                        <ul class="pagination">
                            <!-- Première page -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl(1); ?>" title="Première page">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page précédente -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl($page - 1); ?>" title="Page précédente">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            
                            <!-- Pages numérotées -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . buildUrl(1) . '">1</a></li>';
                                if($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                            }
                            
                            for($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildUrl($i); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if($end_page < $total_pages): ?>
                                <?php if($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?php echo buildUrl($total_pages); ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                            
                            <!-- Page suivante -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl($page + 1); ?>" title="Page suivante">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            
                            <!-- Dernière page -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl($total_pages); ?>" title="Dernière page">
                                    <i class="bi bi-chevron-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-building"></i>
                <h6>Aucune classe trouvée</h6>
                <p class="text-muted">
                    <?php echo ($search || $niveau_filter) ? 
                        'Aucun résultat pour les filtres sélectionnés' : 
                        'Commencez par ajouter une classe'; ?>
                </p>
                <?php if($search || $niveau_filter): ?>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser les filtres
                    </a>
                <?php else: ?>
                    <a href="ajouter.php" class="btn btn-dark btn-sm">
                        <i class="bi bi-plus-circle"></i> Ajouter une classe
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ========================================
   PAGE DE GESTION DES CLASSES
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

/* Stat Cards */
.stat-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.2s ease;
}

.stat-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    transform: translateY(-2px);
}

.stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #64748b;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-value {
    display: block;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1a202c;
    line-height: 1.2;
}

.stat-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
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

.filter-card-body .form-label {
    font-weight: 500;
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 6px;
}

/* Classes Table Card */
.classes-table-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.classes-table-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.classes-table-header .card-icon {
    font-size: 1rem;
    color: #64748b;
    margin-right: 8px;
}

.classes-table-header .card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #1a202c;
}

.classes-count-badge {
    font-size: 0.7rem;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 12px;
    border-radius: 50px;
    margin-left: 10px;
}

.classes-table-body {
    padding: 0;
}

/* Table */
.classes-table {
    margin-bottom: 0;
}

.classes-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.classes-table tbody td {
    padding: 10px 14px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}

.classes-table tbody tr {
    transition: background-color 0.15s ease;
}

.classes-table tbody tr:hover {
    background: #f8fafc;
}

.row-number {
    font-weight: 600;
    color: #94a3b8;
    font-size: 0.8rem;
}

/* Niveau Badge */
.niveau-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    background: #e2e8f0;
    color: #475569;
}

/* Eleves Count */
.eleves-count {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
    color: #1a202c;
}

.eleves-count i {
    color: #94a3b8;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 3px;
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

/* Pagination */
.pagination-container {
    padding: 16px 20px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #fafbfc;
    border-radius: 0 0 12px 12px;
}

.pagination-info {
    display: flex;
    align-items: center;
}

.pagination {
    margin: 0;
    gap: 2px;
}

.pagination .page-item .page-link {
    color: #475569;
    border: 1px solid #e2e8f0;
    padding: 6px 12px;
    font-size: 0.85rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    background: white;
    min-width: 36px;
    text-align: center;
}

.pagination .page-item .page-link:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #1a202c;
}

.pagination .page-item.active .page-link {
    background: #1a202c;
    border-color: #1a202c;
    color: white;
}

.pagination .page-item.active .page-link:hover {
    background: #2d3748;
    border-color: #2d3748;
}

.pagination .page-item.disabled .page-link {
    color: #94a3b8;
    background: #f8fafc;
    cursor: not-allowed;
}

.pagination .page-item .page-link i {
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
    
    .filter-card {
        padding: 16px;
    }
    
    .stat-card {
        padding: 10px 12px;
    }
    
    .stat-value {
        font-size: 1.1rem;
    }
    
    .classes-table thead th {
        font-size: 0.6rem;
        padding: 6px 8px;
    }
    
    .classes-table tbody td {
        font-size: 0.7rem;
        padding: 6px 8px;
    }
    
    .action-buttons .btn {
        width: 26px;
        height: 26px;
        font-size: 0.7rem;
    }
    
    .pagination-container {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .pagination-info {
        justify-content: center;
        font-size: 0.8rem;
    }
    
    .pagination {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .pagination .page-item .page-link {
        padding: 4px 10px;
        font-size: 0.75rem;
        min-width: 32px;
    }
}

@media (max-width: 575.98px) {
    .stat-card {
        flex-direction: column;
        text-align: center;
        padding: 10px;
    }
    
    .stat-icon {
        margin: 0 auto;
    }
    
    .pagination .page-item .page-link {
        padding: 4px 8px;
        font-size: 0.7rem;
        min-width: 28px;
    }
    
    .pagination .page-item:not(.active):not(.page-item:first-child):not(.page-item:last-child) {
        display: none;
    }
    
    .pagination .page-item.active {
        display: block;
    }
    
    .pagination .page-item:first-child,
    .pagination .page-item:last-child {
        display: block;
    }
}

/* Print styles */
@media print {
    .filter-card,
    .page-header .btn,
    .classes-table-header .btn,
    .pagination-container,
    .no-print {
        display: none !important;
    }
    
    .classes-table-card {
        border: none !important;
    }
    
    .classes-table thead th {
        background: #f5f5f5 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>

<script>
// Fonction pour exporter en CSV
function exportToCSV() {
    const table = document.querySelector('.classes-table');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    // En-têtes
    const headers = ['#', 'Nom de la classe', 'Niveau', 'Année scolaire', 'Élèves'];
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
    link.download = 'liste_classes.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

// Auto-soumission des filtres
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.filter-card form');
    const selects = form.querySelectorAll('select');
    const searchInput = document.getElementById('search');
    
    selects.forEach(select => {
        select.addEventListener('change', function() {
            form.submit();
        });
    });
    
    let timeout = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                form.submit();
            }, 500);
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>